<?php
header('Content-Type: application/json');

// --- HELPER FUNCTIONS ---

function parseDockerImage($imageName) {
    $registry = 'registry-1.docker.io';
    $tag = 'latest';

    if (($pos = strpos($imageName, '@')) !== false) {
        $imageName = substr($imageName, 0, $pos);
    }

    $lastColon = strrpos($imageName, ':');
    if ($lastColon !== false && strpos($imageName, '/', $lastColon) === false) {
        $tag = substr($imageName, $lastColon + 1);
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

function fetchRemoteLabels($registry, $repository, $tag) {
    $token = "";
    
    // 1. Try to get auth realm from headers
    $ch = curl_init("https://$registry/v2/");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 10]);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $realm = "";
    $service = "";
    if ($response !== false && preg_match('/www-authenticate:\s*Bearer\s+realm="([^"]+)"(?:,\s*service="([^"]+)")?/i', $response, $matches)) {
        $realm = $matches[1];
        $service = $matches[2] ?? '';
    }
    
    // FIX: Fallback directly to registry token endpoint if header realm wasn't parsed (like GHCR)
    if (empty($realm)) {
        $realm = "https://$registry/token";
        $service = $registry;
    }
    
    $authUrl = $service ? "$realm?service=$service&scope=repository:$repository:pull" : "$realm?scope=repository:$repository:pull";
    
    $chAuth = curl_init($authUrl);
    curl_setopt_array($chAuth, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $authResponse = curl_exec($chAuth);
    if ($authResponse !== false) {
        $authJson = json_decode($authResponse, true);
        $token = $authJson['token'] ?? ($authJson['access_token'] ?? '');
    }
    curl_close($chAuth);

    // 2. Fetch Manifest
    $chMan = curl_init("https://$registry/v2/$repository/manifests/$tag");
    $headers = [
        "Authorization: Bearer $token",
        "Accept: application/vnd.docker.distribution.manifest.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.index.v1+json"
    ];
    curl_setopt_array($chMan, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);
    $manResponse = curl_exec($chMan);
    
    if ($manResponse === false) {
        curl_close($chMan);
        return [];
    }
    
    $manifest = json_decode($manResponse, true);
    curl_close($chMan);

    if (!$manifest) return [];

    $configDigest = '';

    // 3. Handle Multi-Arch Manifest Lists (Find amd64 for Unraid)
    if (isset($manifest['manifests'])) {
        foreach ($manifest['manifests'] as $m) {
            if (isset($m['platform']['architecture']) && $m['platform']['architecture'] === 'amd64') {
                $chSub = curl_init("https://$registry/v2/$repository/manifests/" . $m['digest']);
                curl_setopt_array($chSub, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);
                $subResponse = curl_exec($chSub);
                
                if ($subResponse !== false) {
                    $subManifest = json_decode($subResponse, true);
                    $configDigest = $subManifest['config']['digest'] ?? '';
                }
                curl_close($chSub);
                break;
            }
        }
    } else {
        $configDigest = $manifest['config']['digest'] ?? '';
    }

    if (!$configDigest) return [];

    // 4. Fetch Config Blob
    $chBlob = curl_init("https://$registry/v2/$repository/blobs/$configDigest");
    curl_setopt_array($chBlob, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_FOLLOWLOCATION => false, 
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($chBlob);
    
    if ($response === false) {
        curl_close($chBlob);
        return [];
    }
    
    $httpCode = curl_getinfo($chBlob, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($chBlob, CURLINFO_HEADER_SIZE);
    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($chBlob);

    if ($httpCode >= 300 && $httpCode < 400) {
        if (preg_match('/^Location:\s*(.+)$/mi', $headerStr, $matches)) {
            $redirectUrl = trim($matches[1]);
            $chRedir = curl_init($redirectUrl);
            curl_setopt_array($chRedir, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10
            ]);
            $redirResponse = curl_exec($chRedir);
            if ($redirResponse !== false) {
                $body = $redirResponse;
            }
            curl_close($chRedir);
        }
    }

    $configData = json_decode($body, true) ?: [];
    return $configData['config']['Labels'] ?? [];
}

function extractVersionFromLabels($labels) {
    $version = $labels['org.opencontainers.image.version'] 
        ?? $labels['build_version'] 
        ?? $labels['org.label-schema.version'] 
        ?? $labels['version'] 
        ?? "Unknown";
        
    if (preg_match('/version:- v?([0-9a-zA-Z.-]+)/', $version, $matches)) {
        return $matches[1];
    }
    return ltrim($version, 'v'); 
}

function compareVersions($local, $remote) {
    if ($local === $remote || $local === "Unknown" || $remote === "Unknown") return "";

    $localParts = explode('-', $local);
    $remoteParts = explode('-', $remote);
    $localBase = $localParts[0];
    $remoteBase = $remoteParts[0];

    preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $localBase, $lMatch);
    preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $remoteBase, $rMatch);

    if ($lMatch && $rMatch) {
        $lMajor = (int)$lMatch[1]; $rMajor = (int)$rMatch[1];
        $lMinor = (int)$lMatch[2]; $rMinor = (int)$rMatch[2];
        $lPatch = isset($lMatch[3]) ? (int)$lMatch[3] : 0;
        $rPatch = isset($rMatch[3]) ? (int)$rMatch[3] : 0;

        if ($rMajor > $lMajor) return "Major";
        if ($rMajor < $lMajor) return ""; 

        if ($rMinor > $lMinor) return "Minor";
        if ($rMinor < $lMinor) return ""; 

        if ($rPatch > $lPatch) return "Patch";
        if ($rPatch < $lPatch) return ""; 
    }

    if ($localBase === $remoteBase && count($localParts) > 1 && count($remoteParts) > 1) {
        preg_match('/\d+$/', $localParts[1], $lBuildMatch);
        preg_match('/\d+$/', $remoteParts[1], $rBuildMatch);
        $lBuild = isset($lBuildMatch[0]) ? (int)$lBuildMatch[0] : 0;
        $rBuild = isset($rBuildMatch[0]) ? (int)$rBuildMatch[0] : 0;
        
        if ($rBuild > $lBuild) return "Build";
        if ($rBuild < $lBuild) return ""; 
        
        if ($localParts[1] !== $remoteParts[1] && $lBuild === 0 && $rBuild === 0) return "Build";
    }

    return "Update Available";
}

// --- MAIN EXECUTION ---

// FIX: Bump cache version to v4 to clear previous empty tokens
$cache_file = '/tmp/docker_versions_cache_v4.json';
$cache = [];
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 3600) {
    $cache = json_decode(file_get_contents($cache_file), true) ?: [];
}
$cache_updated = false;

$container_ids = trim(shell_exec("docker ps -aq"));
if (empty($container_ids)) {
    echo json_encode([]);
    exit;
}

$container_ids = str_replace("\n", " ", $container_ids);
$containers = json_decode(shell_exec("docker inspect " . $container_ids), true);
$results = [];

if (!$containers) {
    echo json_encode([]);
    exit;
}

foreach ($containers as $c) {
    $name = ltrim($c['Name'], '/');
    $image = $c['Config']['Image']; 
    $localLabels = $c['Config']['Labels'] ?? [];
    
    $local_version = extractVersionFromLabels($localLabels);
    list($registry, $repository, $tag) = parseDockerImage($image);
    
    $release_notes = $localLabels['org.opencontainers.image.documentation'] 
        ?? $localLabels['org.opencontainers.image.url'] 
        ?? "";
    
    if (strpos($repository, 'linuxserver/') === 0 && empty($release_notes)) {
        $repoParts = explode('/', $repository);
        if (isset($repoParts[1])) {
            $appName = $repoParts[1];
            $release_notes = "https://github.com/linuxserver/docker-{$appName}/releases";
        }
    }

    $cache_key = "$registry/$repository:$tag";
    
    if (isset($cache[$cache_key])) {
        $remoteLabels = $cache[$cache_key];
    } else {
        $remoteLabels = fetchRemoteLabels($registry, $repository, $tag);
        if (!empty($remoteLabels)) {
            $cache[$cache_key] = $remoteLabels;
            $cache_updated = true;
        }
    }

    $remote_version = empty($remoteLabels) ? "Unknown" : extractVersionFromLabels($remoteLabels);
    $update_type = compareVersions($local_version, $remote_version);

    $results[] = [
        'name' => $name,
        'image' => $image,
        'current_version' => $local_version,
        'newest_version' => $remote_version,
        'update_type' => $update_type,
        'release_notes' => $release_notes
    ];
}

if ($cache_updated) {
    file_put_contents($cache_file, json_encode($cache));
}

echo json_encode($results);