<?php

// Background worker: runs queued update / backup & update jobs one at a time.
// Started (detached) by jobs.php; exits when the queue is empty. A second
// instance exits immediately because it can't acquire the worker lock.

require_once __DIR__ . '/../include/common.php';
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';

const AUH_UPDATE_SCRIPT = '/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/update_container';
const AUH_NOTIFY_SCRIPT = '/usr/local/emhttp/webGui/scripts/notify';

class AuhJobFailed extends RuntimeException
{
}

function auhLog(string $logFile, string $message, bool $syslog = true): void
{
    file_put_contents($logFile, '[' . date('H:i:s') . "] {$message}\n", FILE_APPEND);
    if ($syslog) {
        exec('logger -t ' . escapeshellarg(AUH_PLUGIN) . ' -- ' . escapeshellarg($message));
    }
}

/**
 * Turns (possibly HTML/JS-wrapped) command output into plain log text.
 * update_container is written for the webGUI iframe and may emit markup.
 */
function auhCleanOutput(string $line): string
{
    $line = (string)preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $line);
    $line = html_entity_decode(strip_tags($line), ENT_QUOTES);
    return trim($line);
}

/**
 * Runs a shell command, streaming its combined output into the job log.
 */
function auhRun(string $command, string $logFile): int
{
    $process = proc_open($command . ' 2>&1', [1 => ['pipe', 'w']], $pipes);
    if ( ! is_resource($process)) {
        auhLog($logFile, "Failed to start: {$command}");
        return 127;
    }
    while (($line = fgets($pipes[1])) !== false) {
        $clean = auhCleanOutput($line);
        if ($clean !== '') {
            file_put_contents($logFile, "    {$clean}\n", FILE_APPEND);
        }
    }
    fclose($pipes[1]);
    return proc_close($process);
}

function auhIsRunning(string $name): bool
{
    $info  = auhInspectContainer($name);
    $state = is_array($info['State'] ?? null) ? $info['State'] : [];
    return ($state['Running'] ?? false) === true;
}

function auhNotifyFailure(string $subject, string $description): void
{
    if ( ! is_file(AUH_NOTIFY_SCRIPT)) {
        return;
    }
    exec(AUH_NOTIFY_SCRIPT
        . ' -e ' . escapeshellarg('App Update Helper')
        . ' -s ' . escapeshellarg($subject)
        . ' -d ' . escapeshellarg($description)
        . ' -i alert -l /Docker');
}

/**
 * Host paths of the container's bind mounts that live inside an appdata root.
 * Nested paths are dropped (their parent is already archived), as is a mapping
 * of an appdata root itself (that would back up every container's data).
 * With $existingOnly false, sources missing on disk are kept (for a restore).
 *
 * @param array<mixed> $info    docker inspect output
 * @param list<string> $roots
 * @return list<string>
 */
function auhAppdataVolumes(array $info, array $roots, bool $existingOnly = true): array
{
    $roots  = array_map(fn (string $r): string => rtrim($r, '/'), $roots);
    $paths  = [];
    $mounts = is_array($info['Mounts'] ?? null) ? $info['Mounts'] : [];
    foreach ($mounts as $mount) {
        if ( ! is_array($mount) || ($mount['Type'] ?? null) !== 'bind' || ! is_string($mount['Source'] ?? null)) {
            continue;
        }
        $source = rtrim($mount['Source'], '/');
        if ($source === '' || ($existingOnly && ! file_exists($source))) {
            continue;
        }
        foreach ($roots as $root) {
            if ($root !== '' && str_starts_with($source, $root . '/')) {
                $paths[] = $source;
                break;
            }
        }
    }

    $paths = array_values(array_unique($paths));
    sort($paths);
    $result = [];
    foreach ($paths as $path) {
        $nested = false;
        foreach ($result as $kept) {
            if (str_starts_with($path, $kept . '/')) {
                $nested = true;
                break;
            }
        }
        if ( ! $nested) {
            $result[] = $path;
        }
    }
    return $result;
}

/**
 * @param array<string, string> $config
 */
function auhBackup(string $name, array $config, string $logFile): void
{
    if ( ! auhBackupDestinationValid($config)) {
        throw new AuhJobFailed('Backup destination is not configured, missing or not writable.');
    }

    $info = auhInspectContainer($name);
    if ($info === null) {
        throw new AuhJobFailed("Container {$name} not found.");
    }

    $volumes = auhAppdataVolumes($info, auhConfigList($config['appdata_roots'] ?? ''));
    if ($volumes === []) {
        throw new AuhJobFailed('No appdata volumes found to back up (checked roots: ' . ($config['appdata_roots'] ?? '') . ').');
    }
    auhLog($logFile, 'Volumes to back up: ' . implode(', ', $volumes));

    [$flags, $extension] = match ($config['backup_compression'] ?? 'gzip') {
        'none'  => ['', 'tar'],
        'zstd'  => ['-I ' . escapeshellarg('zstd -T0'), 'tar.zst'],
        default => ['-z', 'tar.gz'],
    };

    $targetDir = rtrim($config['backup_destination'] ?? '', '/') . '/' . $name;
    if ( ! is_dir($targetDir) && ! mkdir($targetDir, 0755, true)) {
        throw new AuhJobFailed("Cannot create {$targetDir}.");
    }
    $labels  = is_array($info['Config'] ?? null) && is_array($info['Config']['Labels'] ?? null) ? $info['Config']['Labels'] : [];
    $version = trim((string)preg_replace('/[^A-Za-z0-9._+-]+/', '-', extractVersionFromLabels($labels)), '-');
    $version = in_array($version, ['', 'Unknown'], true) ? '' : "_{$version}";
    $archive = "{$targetDir}/{$name}{$version}_" . date('Ymd_His') . ".{$extension}";

    $excludes = '';
    foreach (auhConfigList($config['backup_exclusions'] ?? '') as $pattern) {
        $excludes .= ' --exclude=' . escapeshellarg($pattern);
    }

    if (($config['backup_stop_container'] ?? 'yes') === 'yes' && auhIsRunning($name)) {
        auhLog($logFile, "Stopping {$name} for a consistent backup...");
        if (auhDockerControl('stop', $name, $logFile) !== null) {
            throw new AuhJobFailed("Failed to stop {$name}.");
        }
    }

    auhLog($logFile, "Creating {$archive}...");
    // Archive full paths relative to / (e.g. mnt/user/appdata/dokuwiki/), so a
    // restore puts each folder back exactly where it came from.
    $members  = implode(' ', array_map(fn (string $v): string => escapeshellarg(auhArchivePath($v)), $volumes));
    $command  = "tar -c {$flags}{$excludes} -f " . escapeshellarg($archive) . ' -C / ' . $members;
    $exitCode = auhRun($command, $logFile);

    // GNU tar exits 1 when files changed while being read; the archive is still complete.
    if ($exitCode === 1) {
        auhLog($logFile, 'Warning: some files changed while being archived.');
    } elseif ($exitCode !== 0) {
        @unlink($archive);
        throw new AuhJobFailed("tar failed with exit code {$exitCode}.");
    }
    $size = filesize($archive);
    auhLog($logFile, 'Backup written (' . round(($size === false ? 0 : $size) / 1048576, 1) . ' MiB).');

    // Remember which image this appdata belongs to; Unraid deletes it after the update.
    $image = is_array($info['Config'] ?? null) && is_string($info['Config']['Image'] ?? null) ? $info['Config']['Image'] : '';
    $meta  = ['container' => $name, 'image' => $image, 'digest' => auhImageDigest($info, $image), 'version' => $version === '' ? '' : substr($version, 1)];
    file_put_contents("{$archive}.json", json_encode($meta, JSON_PRETTY_PRINT));
    if ($meta['digest'] === '') {
        auhLog($logFile, 'Warning: no registry digest known for the current image; a restore will only bring back appdata.');
    }

    $retention = (int)($config['backup_retention'] ?? '0');
    if ($retention > 0) {
        $existing = array_values(array_filter(glob("{$targetDir}/{$name}_*.tar*") ?: [], fn (string $f): bool => ! str_ends_with($f, '.json')));
        // Newest first; by mtime because the version in the name breaks name ordering.
        usort($existing, fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0) ?: strcmp($b, $a));
        foreach (array_slice($existing, $retention) as $old) {
            auhLog($logFile, 'Removing old backup ' . basename($old));
            @unlink($old);
            @unlink("{$old}.json");
        }
    }
}

/**
 * The registry digest reference (repo@sha256:...) of the container's current
 * image, or '' when the image has none (built locally, never pushed).
 *
 * @param array<mixed> $info docker inspect output
 */
function auhImageDigest(array $info, string $image): string
{
    $imageId = is_string($info['Image'] ?? null) ? $info['Image'] : '';
    if ($imageId === '') {
        return '';
    }
    $json    = shell_exec('docker image inspect --format ' . escapeshellarg('{{json .RepoDigests}}') . ' ' . escapeshellarg($imageId) . ' 2>/dev/null');
    $digests = is_string($json) ? json_decode(trim($json), true) : null;
    if ( ! is_array($digests)) {
        return '';
    }
    $repo  = preg_replace('/(:[^\/:]+)?$/', '', $image);
    $first = '';
    foreach ($digests as $digest) {
        if ( ! is_string($digest)) {
            continue;
        }
        $first = $first === '' ? $digest : $first;
        if (str_starts_with($digest, $repo . '@')) {
            return $digest;
        }
    }
    return $first;
}

/**
 * Local image ID a reference (name:tag, repo@digest or ID) resolves to, or ''.
 */
function auhImageId(string $ref): string
{
    $id = shell_exec('docker image inspect --format ' . escapeshellarg('{{.Id}}') . ' ' . escapeshellarg($ref) . ' 2>/dev/null');
    return is_string($id) ? trim($id) : '';
}

/**
 * Pulls an image through Unraid's DockerClient, logging its status and errors.
 * Returns the pulled image's ID, or '' when it isn't available afterwards.
 */
function auhPullImage(string $ref, string $logFile): string
{
    $client = new DockerClient();
    $client->pullImage($ref, function ($line) use ($logFile): void {
        $data = is_string($line) ? json_decode($line, true) : null;
        if ( ! is_array($data)) {
            return;
        }
        if (is_string($data['error'] ?? null)) {
            auhLog($logFile, "ERROR: {$data['error']}", false);
        } elseif (is_string($data['status'] ?? null) && preg_match('/^(Digest|Status):/', $data['status']) === 1) {
            file_put_contents($logFile, "    {$data['status']}\n", FILE_APPEND);
        }
    });
    return auhImageId($ref);
}

/**
 * Points $image (repo[:tag]) at the given image ID through the Docker Engine
 * API via Unraid's DockerClient (it has no tag method of its own).
 */
function auhTagImage(string $imageId, string $image, string $logFile): bool
{
    [$repo, $tag] = preg_match('/^(.+):([^\/:]+)$/', $image, $m) === 1 ? [$m[1], $m[2]] : [$image, 'latest'];
    $client       = new DockerClient();
    $response     = $client->getDockerJSON('/images/' . rawurlencode($imageId) . '/tag?' . http_build_query(['repo' => $repo, 'tag' => $tag]), 'POST');
    if (auhImageId($image) === $imageId) {
        return true;
    }
    // Docker answers a failed request with {"message": "..."}.
    $reason = is_array($response) && is_string($response['message'] ?? null) ? $response['message'] : 'no response from Docker';
    auhLog($logFile, "ERROR: docker tag {$imageId} {$image}: {$reason}");
    return false;
}

/**
 * The name a folder has inside a backup archive: its full path without the leading slash.
 */
function auhArchivePath(string $path): string
{
    return ltrim($path, '/');
}

/**
 * Which of the given archive paths the archive holds as an entry.
 *
 * @param list<string> $paths
 * @return list<string>
 */
function auhArchiveContains(string $archive, string $flags, array $paths): array
{
    $wanted = array_fill_keys($paths, true);
    $found  = [];
    $list   = popen("tar -t {$flags} --quoting-style=literal -f " . escapeshellarg($archive) . ' 2>/dev/null', 'r');
    if ($list === false) {
        return [];
    }
    while (count($found) < count($wanted) && ($line = fgets($list)) !== false) {
        $entry = rtrim($line, "/\n");
        if (isset($wanted[$entry])) {
            $found[$entry] = true;
        }
    }
    pclose($list);
    return array_values(array_filter($paths, fn (string $p): bool => isset($found[$p])));
}

/**
 * Puts appdata folders moved aside by a restore back in place, and removes
 * folders the restore created where none existed before.
 *
 * @param array<string, string> $moved   original path => where it was moved to
 * @param list<string>          $created
 */
function auhUndoMoves(array $moved, array $created, string $logFile): void
{
    foreach ($created as $path) {
        auhRun('rm -rf ' . escapeshellarg($path), $logFile);
    }
    foreach ($moved as $from => $to) {
        auhRun('rm -rf ' . escapeshellarg($from) . ' && mv ' . escapeshellarg($to) . ' ' . escapeshellarg($from), $logFile);
    }
}

/**
 * Brings back the appdata of a backup and the image the container ran when
 * the backup was made. Current appdata is moved aside first and put back if
 * anything fails.
 *
 * @param array<string, string> $config
 */
function auhRestore(string $name, string $archiveName, array $config, string $logFile): void
{
    $archive = auhResolveArchive($config, $name, $archiveName);
    if ($archive === null) {
        throw new AuhJobFailed("Backup {$archiveName} not found.");
    }
    $meta   = auhReadBackupMeta($archive);
    $image  = $meta['image']  ?? '';
    $digest = $meta['digest'] ?? '';

    $extract = match (true) {
        str_ends_with($archive, '.tar.zst') => '-I ' . escapeshellarg('zstd -T0'),
        str_ends_with($archive, '.tar.gz')  => '-z',
        default                             => '',
    };

    $info = auhInspectContainer($name);
    if ($info === null) {
        throw new AuhJobFailed("Container {$name} not found.");
    }
    $withImage       = $image !== '' && $digest !== '';
    $currentImageId  = is_string($info['Image'] ?? null) ? $info['Image'] : '';
    $createCommand   = '';
    $previousImageId = '';

    // The container is recreated from its current template, so moving the tag
    // of an image it no longer uses would not restore anything.
    $currentImage = is_array($info['Config'] ?? null) && is_string($info['Config']['Image'] ?? null) ? $info['Config']['Image'] : '';
    if ($withImage && $currentImage !== $image) {
        throw new AuhJobFailed("This backup was made with image {$image}, but {$name} now uses {$currentImage}. Change the template back to {$image} to restore this backup.");
    }

    // Do everything that can fail without side effects first: pick the volumes
    // the backup holds, build the create command and pull the old image (the
    // tag is only moved once appdata is restored).
    // Volumes missing on disk are included: bringing those back is the point.
    $inArchivePath = [];
    foreach (auhAppdataVolumes($info, auhConfigList($config['appdata_roots'] ?? ''), false) as $volume) {
        $inArchivePath[$volume] = auhArchivePath($volume);
    }
    $inArchive = auhArchiveContains($archive, $extract, array_values($inArchivePath));
    $volumes   = [];
    foreach ($inArchivePath as $volume => $path) {
        if (in_array($path, $inArchive, true)) {
            $volumes[$volume] = $path;
        } else {
            auhLog($logFile, "Skipping {$volume}: not in this backup.");
        }
    }
    if ($volumes === []) {
        throw new AuhJobFailed('None of the container\'s appdata volumes are in this backup (checked roots: ' . ($config['appdata_roots'] ?? '') . ').');
    }

    if ($withImage) {
        $createCommand = auhCreateCommand($name);
        auhLog($logFile, "Pulling previous image {$digest}...");
        $previousImageId = auhPullImage($digest, $logFile);
        if ($previousImageId === '') {
            throw new AuhJobFailed("Could not pull {$digest}; the registry no longer serves it.");
        }
    } else {
        auhLog($logFile, 'This backup has no image information: restoring appdata only.');
    }

    if (auhIsRunning($name)) {
        auhLog($logFile, "Stopping {$name}...");
        if (auhDockerControl('stop', $name, $logFile) !== null) {
            throw new AuhJobFailed("Failed to stop {$name}.");
        }
    }

    // Move current appdata aside, extract the backup in place, undo on failure.
    $suffix  = '.pre-restore-' . date('Ymd_His');
    $moved   = [];
    $created = [];
    foreach (array_keys($volumes) as $volume) {
        if ( ! file_exists($volume)) {
            $created[] = $volume;
            continue;
        }
        if ( ! rename($volume, $volume . $suffix)) {
            auhUndoMoves($moved, [], $logFile);
            throw new AuhJobFailed("Cannot move {$volume} aside.");
        }
        $moved[$volume] = $volume . $suffix;
    }

    // Extract only the members of the selected volumes, each at its full path.
    auhLog($logFile, "Extracting {$archiveName}...");
    $members = implode(' ', array_map('escapeshellarg', $volumes));
    if (auhRun("tar -x {$extract} -f " . escapeshellarg($archive) . ' -C / ' . $members, $logFile) !== 0) {
        auhUndoMoves($moved, $created, $logFile);
        throw new AuhJobFailed('Extracting the backup failed; previous appdata was put back.');
    }

    if ($withImage) {
        try {
            if ( ! auhTagImage($previousImageId, $image, $logFile)) {
                throw new AuhJobFailed("Could not tag {$digest} as {$image}.");
            }
            auhRecreate($name, $createCommand, $logFile);
        } catch (Throwable $e) {
            auhUndoMoves($moved, $created, $logFile);
            // Point the tag back at the image the container ran and make sure it exists again.
            if ($currentImageId !== '') {
                auhTagImage($currentImageId, $image, $logFile);
            }
            if (auhInspectContainer($name) === null) {
                auhLog($logFile, "Recreating the original {$name}...");
                if (auhRun($createCommand, $logFile) !== 0) {
                    throw new AuhJobFailed($e->getMessage() . " Recreating the original {$name} also failed, so the container no longer exists; its appdata was put back. Re-add it from its template (Docker > Add Container > my-{$name}).", 0, $e);
                }
            }
            throw $e;
        }
    }
    if ($moved !== []) {
        auhLog($logFile, 'Previous appdata is kept as *' . $suffix . ' next to the restored folders; remove it when you are satisfied.');
    }
}

/**
 * The `docker create` command Unraid builds from the container's template.
 * Built before a restore changes anything, so a failure here costs nothing.
 */
function auhCreateCommand(string $name): string
{
    // xmlToCommand() lives in dockerMan's Helpers.php, which DockerClient.php may not pull in.
    $helpers = '/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php';
    if ( ! function_exists('xmlToCommand') && is_file($helpers)) {
        require_once $helpers;
    }
    if ( ! function_exists('xmlToCommand')) {
        throw new AuhJobFailed('Unraid\'s xmlToCommand() is not available; cannot recreate the container.');
    }

    $template = AUH_TEMPLATES_DIR . "/my-{$name}.xml";
    // Globals xmlToCommand()/xmlToVar() read: the docker script path, TZ and
    // HOST_HOSTNAME, the network drivers and the known networks.
    global $docroot, $var, $driver, $custom, $subnet;
    $docroot = '/usr/local/emhttp';
    $var     = @parse_ini_file('/var/local/emhttp/var.ini') ?: [];
    $driver  = DockerUtil::driver();
    $custom  = DockerUtil::custom();
    $subnet  = DockerUtil::network($custom);
    $opts    = xmlToCommand($template);
    $cmd     = is_array($opts) && is_string($opts[0] ?? null) ? $opts[0] : '';
    if ($cmd === '') {
        throw new AuhJobFailed("Could not build the docker command from {$template}.");
    }
    return $cmd;
}

/**
 * Replaces the container by a new one made with $createCommand, without
 * pulling, so it uses the image currently tagged locally (like update_container, minus the pull).
 */
function auhRecreate(string $name, string $createCommand, string $logFile): void
{
    auhLog($logFile, "Recreating {$name} from its template...");
    $client = new DockerClient();
    $result = $client->removeContainer($name);
    if ($result !== true) {
        throw new AuhJobFailed("Failed to remove {$name}: " . (is_string($result) ? $result : 'no response from Docker'));
    }
    if (auhRun($createCommand, $logFile) !== 0) {
        throw new AuhJobFailed("docker create for {$name} failed.");
    }
}

/**
 * Starts or stops a container through Unraid's DockerClient (same code path as
 * the Docker tab). Returns an error message, or null on success.
 */
function auhDockerControl(string $action, string $name, string $logFile): ?string
{
    $client = new DockerClient();
    $result = $action === 'start' ? $client->startContainer($name) : $client->stopContainer($name);
    if ($result === true || $result === 'Container already started') {
        return null;
    }
    $error = is_string($result) ? $result : 'no response from Docker';
    auhLog($logFile, "ERROR: docker {$action} {$name}: {$error}");
    return $error;
}

function auhUpdate(string $name, string $logFile): void
{
    auhLog($logFile, "Updating {$name} via Unraid's update_container...");
    $exitCode = auhRun(AUH_UPDATE_SCRIPT . ' ' . escapeshellarg($name), $logFile);
    if ($exitCode !== 0) {
        throw new AuhJobFailed("update_container exited with code {$exitCode}.");
    }
    if (auhInspectContainer($name) === null) {
        throw new AuhJobFailed("Container {$name} no longer exists after the update.");
    }
}

/**
 * @param Job $job
 * @param array<string, string> $config
 */
function auhProcessJob(array $job, array $config): void
{
    $name    = $job['container'];
    $logFile = auhJobLogPath($job['id']);
    $label   = match ($job['mode']) {
        'backup_update' => 'Backup & update',
        'restore'       => 'Restore',
        default         => 'Update',
    };

    auhLog($logFile, "{$label} of {$name} started.");
    $wasRunning = auhIsRunning($name);

    try {
        if ( ! auhHasUserTemplate($name)) {
            throw new AuhJobFailed("{$name} has no Unraid Docker template (my-{$name}.xml); cannot update.");
        }
        if ($job['mode'] === 'restore') {
            auhRestore($name, $job['archive'], $config, $logFile);
        } else {
            if ($job['mode'] === 'backup_update') {
                auhBackup($name, $config, $logFile);
            }
            auhUpdate($name, $logFile);
        }
        $status  = AUH_STATUS_DONE;
        $message = '';
    } catch (Throwable $e) {
        $status  = AUH_STATUS_FAILED;
        $message = $e->getMessage();
        auhLog($logFile, "ERROR: {$message}");
    }

    // update_container only restarts containers that were running when it was
    // called, so restart one we stopped for the backup (or that a failure left down).
    if ($wasRunning && ! auhIsRunning($name) && auhInspectContainer($name) !== null) {
        auhLog($logFile, "Starting {$name}...");
        if (auhDockerControl('start', $name, $logFile) !== null) {
            $status  = AUH_STATUS_FAILED;
            $message = trim("{$message} Failed to restart {$name}.");
        }
    }

    if ($status === AUH_STATUS_DONE) {
        auhLog($logFile, "{$label} of {$name} finished successfully.");
    } else {
        auhNotifyFailure("{$label} of {$name} failed", $message);
    }
    auhFinishJob($job['id'], $status, $message);
}

/**
 * Tries to become the single worker. Retries for ~2s because auhWorkerRunning()
 * briefly holds the same lock to probe it; a real worker holds it far longer.
 *
 * @return resource|null
 */
function auhAcquireWorkerLock()
{
    $lock = fopen(AUH_WORKER_LOCK, 'c');
    if ($lock === false) {
        return null;
    }
    for ($attempt = 0; $attempt < 10; $attempt++) {
        if (flock($lock, LOCK_EX | LOCK_NB)) {
            return $lock;
        }
        usleep(200000);
    }
    fclose($lock);
    return null;
}

// --- MAIN EXECUTION ---

auhEnsureStateDir();

while (true) {
    $lock = auhAcquireWorkerLock();
    if ($lock === null) {
        exit(0);
    }

    auhFailInterruptedJobs();
    while (($job = auhClaimNextJob()) !== null) {
        auhProcessJob($job, auhLoadConfig());
    }

    flock($lock, LOCK_UN);
    fclose($lock);

    // A job may have been queued after the last claim but before the lock was
    // released (its worker would have exited on the held lock), so check again.
    if ( ! auhHasQueuedJobs()) {
        break;
    }
}
