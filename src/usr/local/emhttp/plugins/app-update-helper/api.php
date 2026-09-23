<?php

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
 * @return array{labels: array<string, mixed>, created: string}
 */
function fetchRemoteImageInfo(string $registry, string $repository, string $tag): array
{
    $token = "";

    // 1. Try to get auth realm from headers
    $ch = curl_init("https://{$registry}/v2/");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 10]);
    $response = curl_exec($ch);
    curl_close($ch);

    $realm   = "";
    $service = "";
    if (is_string($response) && preg_match('/www-authenticate:\s*Bearer\s+realm="([^"]+)"(?:,\s*service="([^"]+)")?/i', $response, $matches)) {
        $realm   = $matches[1];
        $service = $matches[2] ?? '';
    }

    // FIX: Fallback directly to registry token endpoint if header realm wasn't parsed (like GHCR)
    if (empty($realm)) {
        $realm   = "https://{$registry}/token";
        $service = $registry;
    }

    $authUrl = $service ? "{$realm}?service={$service}&scope=repository:{$repository}:pull" : "{$realm}?scope=repository:{$repository}:pull";

    $chAuth = curl_init($authUrl);
    curl_setopt_array($chAuth, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $authResponse = curl_exec($chAuth);
    if (is_string($authResponse)) {
        $authJson = json_decode($authResponse, true);
        if (is_array($authJson)) {
            if (is_string($authJson['token'] ?? null)) {
                $token = $authJson['token'];
            } elseif (is_string($authJson['access_token'] ?? null)) {
                $token = $authJson['access_token'];
            }
        }
    }
    curl_close($chAuth);

    // 2. Fetch Manifest
    $chMan   = curl_init("https://{$registry}/v2/{$repository}/manifests/{$tag}");
    $headers = [
        "Authorization: Bearer {$token}",
        "Accept: application/vnd.docker.distribution.manifest.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.index.v1+json"
    ];
    curl_setopt_array($chMan, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);
    $manResponse = curl_exec($chMan);
    curl_close($chMan);

    if ( ! is_string($manResponse)) {
        return ['labels' => [], 'created' => ''];
    }

    $manifest = json_decode($manResponse, true);

    if ( ! is_array($manifest)) {
        return ['labels' => [], 'created' => ''];
    }

    $configDigest = '';

    // 3. Handle Multi-Arch Manifest Lists (Find amd64 for Unraid)
    if (isset($manifest['manifests']) && is_array($manifest['manifests'])) {
        foreach ($manifest['manifests'] as $m) {
            if ( ! is_array($m)) {
                continue;
            }
            $platform = is_array($m['platform'] ?? null) ? $m['platform'] : [];
            if (($platform['architecture'] ?? null) === 'amd64') {
                $digest = is_string($m['digest'] ?? null) ? $m['digest'] : '';
                if ($digest === '') {
                    break;
                }

                $chSub = curl_init("https://{$registry}/v2/{$repository}/manifests/" . $digest);
                curl_setopt_array($chSub, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);
                $subResponse = curl_exec($chSub);

                if (is_string($subResponse)) {
                    $subManifest = json_decode($subResponse, true);
                    if (is_array($subManifest)) {
                        $subConfig    = is_array($subManifest['config'] ?? null) ? $subManifest['config'] : [];
                        $configDigest = is_string($subConfig['digest'] ?? null) ? $subConfig['digest'] : '';
                    }
                }
                curl_close($chSub);
                break;
            }
        }
    } else {
        $config       = is_array($manifest['config'] ?? null) ? $manifest['config'] : [];
        $configDigest = is_string($config['digest'] ?? null) ? $config['digest'] : '';
    }

    if ( ! $configDigest) {
        return ['labels' => [], 'created' => ''];
    }

    // 4. Fetch Config Blob
    $chBlob = curl_init("https://{$registry}/v2/{$repository}/blobs/{$configDigest}");
    curl_setopt_array($chBlob, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 10
    ]);

    $response = curl_exec($chBlob);

    if ( ! is_string($response)) {
        curl_close($chBlob);
        return ['labels' => [], 'created' => ''];
    }

    $httpCode   = curl_getinfo($chBlob, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($chBlob, CURLINFO_HEADER_SIZE);
    $headerStr  = substr($response, 0, $headerSize);
    $body       = substr($response, $headerSize);
    curl_close($chBlob);

    if ($httpCode >= 300 && $httpCode < 400) {
        if (preg_match('/^Location:\s*(.+)$/mi', $headerStr, $matches)) {
            $redirectUrl = trim($matches[1]);
            $chRedir     = curl_init($redirectUrl);
            curl_setopt_array($chRedir, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 10
            ]);
            $redirResponse = curl_exec($chRedir);
            if (is_string($redirResponse)) {
                $body = $redirResponse;
            }
            curl_close($chRedir);
        }
    }

    $configData = json_decode($body, true);
    if ( ! is_array($configData)) {
        return ['labels' => [], 'created' => ''];
    }

    $configBlock = is_array($configData['config'] ?? null) ? $configData['config'] : [];

    return [
        'labels'  => is_array($configBlock['Labels'] ?? null) ? $configBlock['Labels'] : [],
        'created' => is_string($configData['created'] ?? null) ? $configData['created'] : '',
    ];
}

/**
 * @param array<string, mixed> $labels
 */
function extractVersionFromLabels(array $labels): string
{
    $version = $labels['org.opencontainers.image.version']
        ?? $labels['build_version']
        ?? $labels['org.label-schema.version']
        ?? $labels['version']
        ?? "Unknown";

    if ( ! is_string($version)) {
        $version = "Unknown";
    }

    if (preg_match('/version:- v?([0-9a-zA-Z.-]+)/', $version, $matches)) {
        return $matches[1];
    }
    return ltrim($version, 'v');
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

foreach ($containers as $c) {
    if ( ! is_array($c)) {
        continue;
    }

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

    $cachedEntry = $cache[$cache_key] ?? null;
    if (is_array($cachedEntry)) {
        $remoteInfo = [
            'labels'  => is_array($cachedEntry['labels'] ?? null) ? $cachedEntry['labels'] : [],
            'created' => is_string($cachedEntry['created'] ?? null) ? $cachedEntry['created'] : '',
        ];
    } else {
        $remoteInfo = fetchRemoteImageInfo($registry, $repository, $tag);
        if ( ! empty($remoteInfo['labels'])) {
            $cache[$cache_key] = $remoteInfo;
            $cache_updated     = true;
        }
    }

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
        'released'        => $released
    ];
}

if ($cache_updated) {
    file_put_contents($cache_file, json_encode($cache));
}

echo json_encode($results);
