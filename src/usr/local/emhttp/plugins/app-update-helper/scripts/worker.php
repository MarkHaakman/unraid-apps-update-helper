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
 *
 * @param array<mixed> $info    docker inspect output
 * @param list<string> $roots
 * @return list<string>
 */
function auhAppdataVolumes(array $info, array $roots): array
{
    $roots  = array_map(fn (string $r): string => rtrim($r, '/'), $roots);
    $paths  = [];
    $mounts = is_array($info['Mounts'] ?? null) ? $info['Mounts'] : [];
    foreach ($mounts as $mount) {
        if ( ! is_array($mount) || ($mount['Type'] ?? null) !== 'bind' || ! is_string($mount['Source'] ?? null)) {
            continue;
        }
        $source = rtrim($mount['Source'], '/');
        if ($source === '' || ! file_exists($source)) {
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
    $archive = "{$targetDir}/{$name}_" . date('Ymd_His') . ".{$extension}";

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
    $command = "tar -c -P {$flags}{$excludes} -f " . escapeshellarg($archive) . ' '
        . implode(' ', array_map('escapeshellarg', $volumes));
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

    $retention = (int)($config['backup_retention'] ?? '0');
    if ($retention > 0) {
        $existing = glob("{$targetDir}/{$name}_*.tar*") ?: [];
        rsort($existing);
        foreach (array_slice($existing, $retention) as $old) {
            auhLog($logFile, 'Removing old backup ' . basename($old));
            @unlink($old);
        }
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
    $label   = $job['mode'] === 'backup_update' ? 'Backup & update' : 'Update';

    auhLog($logFile, "{$label} of {$name} started.");
    $wasRunning = auhIsRunning($name);

    try {
        if ( ! auhHasUserTemplate($name)) {
            throw new AuhJobFailed("{$name} has no Unraid Docker template (my-{$name}.xml); cannot update.");
        }
        if ($job['mode'] === 'backup_update') {
            auhBackup($name, $config, $logFile);
        }
        auhUpdate($name, $logFile);
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
