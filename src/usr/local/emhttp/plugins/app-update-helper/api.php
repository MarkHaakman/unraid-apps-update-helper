<?php

require_once __DIR__ . '/include/common.php';

header('Content-Type: application/json');

// --- HELPER FUNCTIONS ---

/**
 * @return array{0: string, 1: string, 2: string}
 */
function parseDockerImage(string $imageName): array
{
    $registry = 'registry-1.docker.io';
    $tag      = 'latest';

    if (($pos = strpos($imageName, '@')) !== false) {
        $imageName = substr($imageName, 0, $pos);
    }

    $lastColon = strrpos($imageName, ':');
    if ($lastColon !== false && strpos($imageName, '/', $lastColon) === false) {
        $tag       = substr($imageName, $lastColon + 1);
        $imageName = substr($imageName, 0, $lastColon);
    }

    $parts = explode('/', $imageName);

    if (count($parts) > 1 && (strpos($parts[0], '.') !== false || strpos($parts[0], ':') !== false || $parts[0] === 'localhost')) {
        $registry = array_shift($parts);
    }

    $repository = implode('/', $parts);
    if ($registry === 'registry-1.docker.io' && strpos($repository, '/') === false) {
        $repository = 'library/' . $repository;
    }

    if ($registry === 'lscr.io') {
        $registry = 'ghcr.io';
    }

    return [$registry, $repository, $tag];
}

/**
 * Runs a batch of requests concurrently on a shared multi handle (which keeps connections alive between batches).
 *
 * @param array<string, array<int, mixed>> $requests curl options per request
 * @return array<string, array{body: string, code: int, headerSize: int}> keyed like $requests; body is '' on failure
 */
function curlMultiFetch(CurlMultiHandle $mh, array $requests): array
{
    $handles = [];
    foreach ($requests as $key => $options) {
        $ch = curl_init();
        curl_setopt_array($ch, $options + [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0 && curl_multi_select($mh, 1.0) === -1) {
            usleep(10000);
        }
    } while ($running > 0 && $status === CURLM_OK);

    $responses = [];
    foreach ($handles as $key => $ch) {
        $responses[$key] = [
            'body'       => curl_multi_getcontent($ch) ?? '',
            'code'       => curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'headerSize' => curl_getinfo($ch, CURLINFO_HEADER_SIZE),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    return $responses;
}

/**
 * Fetches the OCI labels and creation date of many remote images at once. Each step of the registry flow
 * (auth realm, token, manifest, per-arch manifest, config blob, blob redirect) runs for all images in parallel,
 * so the total time is a handful of round trips instead of growing with the number of images.
 *
 * @param array<string, array{0: string, 1: string, 2: string}> $images cache key => [registry, repository, tag]
 * @return array<string, array{labels: array<string, mixed>, created: string}> keyed like $images
 */
function fetchRemoteImageInfos(array $images): array
{
    $results = [];
    foreach ($images as $key => $_) {
        $results[$key] = ['labels' => [], 'created' => ''];
    }
    if ($images === []) {
        return $results;
    }

    // No per-host connection limit: queued requests would burn their timeout while waiting for a free connection
    $mh = curl_multi_init();

    // 1. Get the auth realm from the headers, once per registry
    $probes = [];
    foreach ($images as [$registry]) {
        $probes[$registry] = [CURLOPT_URL => "https://{$registry}/v2/", CURLOPT_HEADER => true, CURLOPT_NOBODY => true];
    }

    $realms = [];
    foreach (curlMultiFetch($mh, $probes) as $registry => $response) {
        if (preg_match('/www-authenticate:\s*Bearer\s+realm="([^"]+)"(?:,\s*service="([^"]+)")?/i', $response['body'], $matches)) {
            $realms[$registry] = [$matches[1], $matches[2] ?? ''];
        } else {
            // Fall back directly to the registry token endpoint if the header realm wasn't parsed (like GHCR)
            $realms[$registry] = ["https://{$registry}/token", $registry];
        }
    }

    // 2. Get a pull token, once per repository
    $tokenRequests = [];
    foreach ($images as [$registry, $repository]) {
        [$realm, $service]                          = $realms[$registry];
        $tokenRequests["{$registry}/{$repository}"] = [
            CURLOPT_URL => $service ? "{$realm}?service={$service}&scope=repository:{$repository}:pull" : "{$realm}?scope=repository:{$repository}:pull",
        ];
    }

    $tokens = [];
    foreach (curlMultiFetch($mh, $tokenRequests) as $tokenKey => $response) {
        $authJson = json_decode($response['body'], true);
        $token    = '';
        if (is_array($authJson)) {
            if (is_string($authJson['token'] ?? null)) {
                $token = $authJson['token'];
            } elseif (is_string($authJson['access_token'] ?? null)) {
                $token = $authJson['access_token'];
            }
        }
        $tokens[$tokenKey] = $token;
    }

    $manifestOptions = function (string $key, string $reference) use ($images, $tokens): array {
        [$registry, $repository] = $images[$key];

        return [
            CURLOPT_URL        => "https://{$registry}/v2/{$repository}/manifests/{$reference}",
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$tokens["{$registry}/{$repository}"]}",
                "Accept: application/vnd.docker.distribution.manifest.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.index.v1+json",
            ],
        ];
    };

    $configDigestOf = function (string $body): string {
        $manifest = json_decode($body, true);
        $config   = is_array($manifest) && is_array($manifest['config'] ?? null) ? $manifest['config'] : [];

        return is_string($config['digest'] ?? null) ? $config['digest'] : '';
    };

    // 3. Fetch the manifests
    $manifestRequests = [];
    foreach ($images as $key => [, , $tag]) {
        $manifestRequests[$key] = $manifestOptions($key, $tag);
    }

    $configDigests      = [];
    $subManifestRequest = [];
    foreach (curlMultiFetch($mh, $manifestRequests) as $key => $response) {
        $manifest = json_decode($response['body'], true);
        if ( ! is_array($manifest)) {
            continue;
        }

        if ( ! isset($manifest['manifests']) || ! is_array($manifest['manifests'])) {
            $configDigests[$key] = $configDigestOf($response['body']);
            continue;
        }

        // Multi-arch manifest list: find amd64 for Unraid
        foreach ($manifest['manifests'] as $m) {
            if ( ! is_array($m)) {
                continue;
            }
            $platform = is_array($m['platform'] ?? null) ? $m['platform'] : [];
            if (($platform['architecture'] ?? null) === 'amd64') {
                $digest = is_string($m['digest'] ?? null) ? $m['digest'] : '';
                if ($digest !== '') {
                    $subManifestRequest[$key] = $manifestOptions($key, $digest);
                }
                break;
            }
        }
    }

    // 4. Fetch the amd64 manifests of multi-arch images
    foreach (curlMultiFetch($mh, $subManifestRequest) as $key => $response) {
        $configDigests[$key] = $configDigestOf($response['body']);
    }

    // 5. Fetch the config blobs
    $blobRequests = [];
    foreach (array_filter($configDigests) as $key => $configDigest) {
        [$registry, $repository] = $images[$key];
        $blobRequests[$key]      = [
            CURLOPT_URL            => "https://{$registry}/v2/{$repository}/blobs/{$configDigest}",
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$tokens["{$registry}/{$repository}"]}"],
            CURLOPT_FOLLOWLOCATION => false,
        ];
    }

    $configBodies     = [];
    $redirectRequests = [];
    foreach (curlMultiFetch($mh, $blobRequests) as $key => $response) {
        $headerStr          = substr($response['body'], 0, $response['headerSize']);
        $configBodies[$key] = substr($response['body'], $response['headerSize']);

        if ($response['code'] >= 300 && $response['code'] < 400 && preg_match('/^Location:\s*(.+)$/mi', $headerStr, $matches)) {
            $redirectRequests[$key] = [CURLOPT_URL => trim($matches[1]), CURLOPT_FOLLOWLOCATION => true];
        }
    }

    // 6. Follow blob redirects (usually to a CDN) without forwarding the registry token
    foreach (curlMultiFetch($mh, $redirectRequests) as $key => $response) {
        if ($response['body'] !== '') {
            $configBodies[$key] = $response['body'];
        }
    }

    curl_multi_close($mh);

    foreach ($configBodies as $key => $body) {
        $configData = json_decode($body, true);
        if ( ! is_array($configData)) {
            continue;
        }

        $configBlock   = is_array($configData['config'] ?? null) ? $configData['config'] : [];
        $results[$key] = [
            'labels'  => is_array($configBlock['Labels'] ?? null) ? $configBlock['Labels'] : [],
            'created' => is_string($configData['created'] ?? null) ? $configData['created'] : '',
        ];
    }

    return $results;
}

function formatDaysAgo(string $createdDate): string
{
    if (empty($createdDate)) {
        return "";
    }

    $createdTs = strtotime($createdDate);
    if ($createdTs === false) {
        return "";
    }

    $days = (int)floor(time() / 86400) - (int)floor($createdTs / 86400);
    if ($days <= 0) {
        return "Today";
    }
    if ($days === 1) {
        return "1 day ago";
    }
    return "{$days} days ago";
}

function compareVersions(string $local, string $remote): string
{
    if ($local === $remote || $local === "Unknown" || $remote === "Unknown") {
        return "";
    }

    $localParts  = explode('-', $local);
    $remoteParts = explode('-', $remote);
    $localBase   = $localParts[0];
    $remoteBase  = $remoteParts[0];

    preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $localBase, $lMatch);
    preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $remoteBase, $rMatch);

    if ($lMatch && $rMatch) {
        $lMajor = (int)$lMatch[1];
        $rMajor = (int)$rMatch[1];
        $lMinor = (int)$lMatch[2];
        $rMinor = (int)$rMatch[2];
        $lPatch = isset($lMatch[3]) ? (int)$lMatch[3] : 0;
        $rPatch = isset($rMatch[3]) ? (int)$rMatch[3] : 0;

        if ($rMajor > $lMajor) {
            return "Major";
        }
        if ($rMajor < $lMajor) {
            return "";
        }

        if ($rMinor > $lMinor) {
            return "Minor";
        }
        if ($rMinor < $lMinor) {
            return "";
        }

        if ($rPatch > $lPatch) {
            return "Patch";
        }
        if ($rPatch < $lPatch) {
            return "";
        }
    }

    if ($localBase === $remoteBase && count($localParts) > 1 && count($remoteParts) > 1) {
        preg_match('/\d+$/', $localParts[1], $lBuildMatch);
        preg_match('/\d+$/', $remoteParts[1], $rBuildMatch);
        $lBuild = isset($lBuildMatch[0]) ? (int)$lBuildMatch[0] : 0;
        $rBuild = isset($rBuildMatch[0]) ? (int)$rBuildMatch[0] : 0;

        if ($rBuild > $lBuild) {
            return "Build";
        }
        if ($rBuild < $lBuild) {
            return "";
        }

        if ($localParts[1] !== $remoteParts[1] && $lBuild === 0 && $rBuild === 0) {
            return "Build";
        }
    }

    return "Update Available";
}

// --- MAIN EXECUTION ---

// FIX: Bump cache version to v5 to add the "created" date alongside labels
$cache_file = '/tmp/docker_versions_cache_v5.json';
$cache      = [];
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 3600) {
    $cacheRaw     = file_get_contents($cache_file);
    $cacheDecoded = is_string($cacheRaw) ? json_decode($cacheRaw, true) : null;
    if (is_array($cacheDecoded)) {
        $cache = $cacheDecoded;
    }
}
$cache_updated = false;

$psOutput      = shell_exec("docker ps -aq");
$container_ids = is_string($psOutput) ? trim($psOutput) : '';
if (empty($container_ids)) {
    echo json_encode([]);
    exit;
}

$container_ids = str_replace("\n", " ", $container_ids);
$inspectOutput = shell_exec("docker inspect " . $container_ids);
$containers    = is_string($inspectOutput) ? json_decode($inspectOutput, true) : null;
$results       = [];

if ( ! is_array($containers)) {
    echo json_encode([]);
    exit;
}

$containers = array_filter($containers, 'is_array');

// Look up every image that isn't cached in one parallel batch
$uncached = [];
foreach ($containers as $c) {
    $config = is_array($c['Config'] ?? null) ? $c['Config'] : [];
    $image  = parseDockerImage(is_string($config['Image'] ?? null) ? $config['Image'] : '');

    $cache_key = "{$image[0]}/{$image[1]}:{$image[2]}";
    if ( ! is_array($cache[$cache_key] ?? null)) {
        $uncached[$cache_key] = $image;
    }
}

$fetched = fetchRemoteImageInfos($uncached);
foreach ($fetched as $cache_key => $remoteInfo) {
    // Failed lookups aren't cached, so they are retried on the next load
    if ( ! empty($remoteInfo['labels'])) {
        $cache[$cache_key] = $remoteInfo;
        $cache_updated     = true;
    }
}

foreach ($containers as $c) {
    $config      = is_array($c['Config'] ?? null) ? $c['Config'] : [];
    $name        = is_string($c['Name'] ?? null) ? ltrim($c['Name'], '/') : '';
    $image       = is_string($config['Image'] ?? null) ? $config['Image'] : '';
    $localLabels = is_array($config['Labels'] ?? null) ? $config['Labels'] : [];

    $local_version                     = extractVersionFromLabels($localLabels);
    list($registry, $repository, $tag) = parseDockerImage($image);

    $release_notes = $localLabels['org.opencontainers.image.documentation']
        ?? $localLabels['org.opencontainers.image.url']
        ?? "";
    if ( ! is_string($release_notes)) {
        $release_notes = "";
    }

    if (strpos($repository, 'linuxserver/') === 0 && empty($release_notes)) {
        $repoParts = explode('/', $repository);
        if (isset($repoParts[1])) {
            $appName       = $repoParts[1];
            $release_notes = "https://github.com/linuxserver/docker-{$appName}/releases";
        }
    }

    $cache_key = "{$registry}/{$repository}:{$tag}";

    $cachedEntry = $fetched[$cache_key] ?? $cache[$cache_key] ?? null;
    $remoteInfo  = [
        'labels'  => is_array($cachedEntry) && is_array($cachedEntry['labels'] ?? null) ? $cachedEntry['labels'] : [],
        'created' => is_array($cachedEntry) && is_string($cachedEntry['created'] ?? null) ? $cachedEntry['created'] : '',
    ];

    $remoteLabels   = $remoteInfo['labels'];
    $remote_version = empty($remoteLabels) ? "Unknown" : extractVersionFromLabels($remoteLabels);
    $update_type    = compareVersions($local_version, $remote_version);
    $released       = formatDaysAgo($remoteInfo['created']);

    $results[] = [
        'name'            => $name,
        'image'           => $image,
        'current_version' => $local_version,
        'newest_version'  => $remote_version,
        'update_type'     => $update_type,
        'release_notes'   => $release_notes,
        'released'        => $released,
        'managed'         => auhHasUserTemplate($name),
    ];
}

if ($cache_updated) {
    file_put_contents($cache_file, json_encode($cache));
}

echo json_encode($results);
