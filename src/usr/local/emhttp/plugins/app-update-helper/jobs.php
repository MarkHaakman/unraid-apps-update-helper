<?php

// Job endpoint for the Docker tab page.
//   POST action=enqueue container=<name> mode=update|backup_update (+ csrf_token)
//   GET  action=status [job=<id> offset=<bytes>]   -> job list and log tail

require_once __DIR__ . '/include/common.php';

header('Content-Type: application/json');

/**
 * @param array<string, mixed> $data
 */
function auhRespond(array $data, int $httpCode = 200): never
{
    http_response_code($httpCode);
    echo json_encode($data);
    exit;
}

function auhParam(string $key): string
{
    $value = $_REQUEST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

$action = auhParam('action');

if ($action === 'enqueue') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        auhRespond(['error' => 'POST required'], 405);
    }

    $container = auhParam('container');
    $mode      = auhParam('mode');

    if ( ! in_array($mode, AUH_MODES, true)) {
        auhRespond(['error' => 'Invalid mode'], 400);
    }
    if ( ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $container) || auhInspectContainer($container) === null) {
        auhRespond(['error' => 'Unknown container'], 400);
    }
    if ( ! auhHasUserTemplate($container)) {
        auhRespond(['error' => 'Not managed by Unraid Docker templates'], 400);
    }
    if ($mode === 'backup_update' && ! auhBackupDestinationValid(auhLoadConfig())) {
        auhRespond(['error' => 'Backup destination is not configured, missing or not writable'], 400);
    }

    $job = auhEnqueueJob($container, $mode);
    auhStartWorker();
    auhRespond(['job' => $job]);
}

if ($action === 'status') {
    $jobs = auhReadJobs();

    // Safety net: restart the worker if jobs are waiting but nothing is processing them.
    foreach ($jobs as $job) {
        if ($job['status'] === AUH_STATUS_QUEUED && ! auhWorkerRunning()) {
            auhStartWorker();
            break;
        }
    }

    $response = ['jobs' => $jobs];

    $jobId = auhParam('job');
    if ($jobId !== '') {
        $offset  = max(0, (int)auhParam('offset'));
        $logFile = auhJobLogPath($jobId);
        $content = '';
        if (is_file($logFile)) {
            $size = filesize($logFile);
            if ($size !== false && $size < $offset) {
                $offset = 0;
            }
            $read = file_get_contents($logFile, false, null, $offset);
            if (is_string($read)) {
                $content = $read;
            }
        }
        $response['log'] = ['job' => $jobId, 'content' => $content, 'offset' => $offset + strlen($content)];
    }

    auhRespond($response);
}

auhRespond(['error' => 'Unknown action'], 400);
