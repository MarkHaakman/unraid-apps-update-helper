<?php

// Shared helpers for the page files, api.php, jobs.php and scripts/worker.php.

const AUH_PLUGIN         = 'app-update-helper';
const AUH_PLUGIN_DIR     = '/usr/local/emhttp/plugins/app-update-helper';
const AUH_CONFIG_FILE    = '/boot/config/plugins/app-update-helper/app-update-helper.cfg';
const AUH_STATE_DIR      = '/tmp/app-update-helper';
const AUH_JOBS_FILE      = AUH_STATE_DIR . '/jobs.json';
const AUH_JOBS_LOCK      = AUH_STATE_DIR . '/jobs.lock';
const AUH_WORKER_LOCK    = AUH_STATE_DIR . '/worker.lock';
const AUH_LOG_DIR        = AUH_STATE_DIR . '/logs';
const AUH_TEMPLATES_DIR  = '/boot/config/plugins/dockerMan/templates-user';
const AUH_MAX_FINISHED   = 20;
const AUH_MODES          = ['update', 'backup_update'];
const AUH_STATUS_QUEUED  = 'queued';
const AUH_STATUS_RUNNING = 'running';
const AUH_STATUS_DONE    = 'done';
const AUH_STATUS_FAILED  = 'failed';

/**
 * Plugin settings: default.cfg merged with the user's saved config on the flash drive.
 *
 * @return array<string, string>
 */
function auhLoadConfig(): array
{
    $config = [];
    foreach ([AUH_PLUGIN_DIR . '/default.cfg', AUH_CONFIG_FILE] as $file) {
        if ( ! is_file($file)) {
            continue;
        }
        $values = parse_ini_file($file);
        if ( ! is_array($values)) {
            continue;
        }
        foreach ($values as $key => $value) {
            if (is_scalar($value)) {
                $config[(string)$key] = (string)$value;
            }
        }
    }
    return $config;
}

/**
 * Splits a comma-separated config value into trimmed, non-empty entries.
 *
 * @return list<string>
 */
function auhConfigList(string $value): array
{
    return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $v): bool => $v !== ''));
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

/**
 * Whether the configured backup destination is usable.
 *
 * @param array<string, string> $config
 */
function auhBackupDestinationValid(array $config): bool
{
    $destination = $config['backup_destination'] ?? '';
    return $destination !== '' && is_dir($destination) && is_writable($destination);
}

/**
 * The webGUI CSRF token, needed for POST requests (validated by Unraid's local_prepend.php).
 */
function auhCsrfToken(): string
{
    $file = '/var/local/emhttp/var.ini';
    if ( ! is_file($file)) {
        return '';
    }
    $var = parse_ini_file($file);
    return is_array($var) && is_string($var['csrf_token'] ?? null) ? $var['csrf_token'] : '';
}

/**
 * Containers can only be updated through Unraid's update_container script when
 * they were created from a Docker Manager user template.
 */
function auhHasUserTemplate(string $name): bool
{
    return $name !== '' && is_file(AUH_TEMPLATES_DIR . "/my-{$name}.xml");
}

function auhEnsureStateDir(): void
{
    if ( ! is_dir(AUH_LOG_DIR)) {
        mkdir(AUH_LOG_DIR, 0755, true);
    }
}

function auhJobLogPath(string $jobId): string
{
    return AUH_LOG_DIR . '/' . basename($jobId) . '.log';
}

/**
 * @return Job|null
 */
function auhNormalizeJob(mixed $raw): ?array
{
    if ( ! is_array($raw) || ! is_string($raw['id'] ?? null) || ! is_string($raw['container'] ?? null)) {
        return null;
    }
    return [
        'id'        => $raw['id'],
        'container' => $raw['container'],
        'mode'      => is_string($raw['mode'] ?? null) ? $raw['mode'] : 'update',
        'status'    => is_string($raw['status'] ?? null) ? $raw['status'] : AUH_STATUS_FAILED,
        'created'   => is_int($raw['created'] ?? null) ? $raw['created'] : 0,
        'started'   => is_int($raw['started'] ?? null) ? $raw['started'] : 0,
        'finished'  => is_int($raw['finished'] ?? null) ? $raw['finished'] : 0,
        'message'   => is_string($raw['message'] ?? null) ? $raw['message'] : '',
    ];
}

/**
 * @return list<Job>
 */
function auhReadJobsFile(): array
{
    $raw     = is_file(AUH_JOBS_FILE) ? file_get_contents(AUH_JOBS_FILE) : false;
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $jobs    = [];
    if (is_array($decoded)) {
        foreach ($decoded as $entry) {
            $job = auhNormalizeJob($entry);
            if ($job !== null) {
                $jobs[] = $job;
            }
        }
    }
    return $jobs;
}

/**
 * Runs $fn with exclusive access to the job list and persists whatever it leaves in $jobs.
 *
 * @template T
 * @param callable(list<Job> &$jobs): T $fn
 * @return T
 */
function auhWithJobs(callable $fn): mixed
{
    auhEnsureStateDir();
    $lock = fopen(AUH_JOBS_LOCK, 'c');
    if ($lock === false) {
        throw new RuntimeException('Cannot open ' . AUH_JOBS_LOCK);
    }
    flock($lock, LOCK_EX);
    try {
        $jobs   = auhReadJobsFile();
        $result = $fn($jobs);

        // Keep every active job but only the most recent finished ones.
        $finished = array_filter($jobs, fn (array $j): bool => in_array($j['status'], [AUH_STATUS_DONE, AUH_STATUS_FAILED], true));
        $drop     = array_slice(array_keys($finished), 0, max(0, count($finished) - AUH_MAX_FINISHED));
        foreach ($drop as $index) {
            @unlink(auhJobLogPath($jobs[$index]['id']));
            unset($jobs[$index]);
        }

        file_put_contents(AUH_JOBS_FILE, json_encode(array_values($jobs)));
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * @return list<Job>
 */
function auhReadJobs(): array
{
    return auhWithJobs(fn (array &$jobs): array => $jobs);
}

/**
 * @return Job
 */
function auhEnqueueJob(string $container, string $mode): array
{
    return auhWithJobs(function (array &$jobs) use ($container, $mode): array {
        $job = [
            'id'        => date('Ymd-His') . '-' . bin2hex(random_bytes(3)),
            'container' => $container,
            'mode'      => $mode,
            'status'    => AUH_STATUS_QUEUED,
            'created'   => time(),
            'started'   => 0,
            'finished'  => 0,
            'message'   => '',
        ];
        $jobs[] = $job;
        file_put_contents(auhJobLogPath($job['id']), '');
        return $job;
    });
}

/**
 * Marks the oldest queued job as running and returns it.
 *
 * @return Job|null
 */
function auhClaimNextJob(): ?array
{
    return auhWithJobs(function (array &$jobs): ?array {
        foreach ($jobs as $index => $job) {
            if ($job['status'] === AUH_STATUS_QUEUED) {
                $jobs[$index]['status']  = AUH_STATUS_RUNNING;
                $jobs[$index]['started'] = time();
                return $jobs[$index];
            }
        }
        return null;
    });
}

function auhFinishJob(string $jobId, string $status, string $message = ''): void
{
    auhWithJobs(function (array &$jobs) use ($jobId, $status, $message): void {
        foreach ($jobs as $index => $job) {
            if ($job['id'] === $jobId) {
                $jobs[$index]['status']   = $status;
                $jobs[$index]['finished'] = time();
                $jobs[$index]['message']  = $message;
            }
        }
    });
}

/**
 * Jobs left in "running" state without a worker holding the lock were interrupted.
 */
function auhFailInterruptedJobs(): void
{
    auhWithJobs(function (array &$jobs): void {
        foreach ($jobs as $index => $job) {
            if ($job['status'] === AUH_STATUS_RUNNING) {
                $jobs[$index]['status']   = AUH_STATUS_FAILED;
                $jobs[$index]['finished'] = time();
                $jobs[$index]['message']  = 'Interrupted';
            }
        }
    });
}

function auhHasQueuedJobs(): bool
{
    foreach (auhReadJobs() as $job) {
        if ($job['status'] === AUH_STATUS_QUEUED) {
            return true;
        }
    }
    return false;
}

function auhWorkerRunning(): bool
{
    auhEnsureStateDir();
    $lock = fopen(AUH_WORKER_LOCK, 'c');
    if ($lock === false) {
        return false;
    }
    $free = flock($lock, LOCK_EX | LOCK_NB);
    if ($free) {
        flock($lock, LOCK_UN);
    }
    fclose($lock);
    return ! $free;
}

function auhStartWorker(): void
{
    exec('nohup /usr/bin/php -q ' . escapeshellarg(AUH_PLUGIN_DIR . '/scripts/worker.php') . ' > /dev/null 2>&1 &');
}

/**
 * Parsed `docker inspect` output for a single container, or null if it doesn't exist.
 *
 * @return array<mixed>|null
 */
function auhInspectContainer(string $name): ?array
{
    $output  = shell_exec('docker inspect ' . escapeshellarg($name) . ' 2>/dev/null');
    $decoded = is_string($output) ? json_decode($output, true) : null;
    if ( ! is_array($decoded) || ! is_array($decoded[0] ?? null)) {
        return null;
    }
    return $decoded[0];
}
