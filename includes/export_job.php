<?php
declare(strict_types=1);

function job_file(string $id): string
{
    if (!is_valid_id($id)) {
        throw new RuntimeException('Export job not found.');
    }
    return export_dir() . '/jobs/' . $id . '.json';
}

function job_dir(string $id): string
{
    if (!is_valid_id($id)) {
        throw new RuntimeException('Export job not found.');
    }
    return export_dir() . '/jobs/' . $id;
}

function job_progress_file(string $id): string
{
    return job_dir($id) . '/progress.txt';
}

function job_log_file(string $id): string
{
    return job_dir($id) . '/export.log';
}

function job_output_file(string $id): string
{
    if (!is_valid_id($id)) {
        throw new RuntimeException('Export job not found.');
    }
    return export_dir() . '/output/' . $id . '.mp4';
}

function load_job(string $id): ?array
{
    if (!is_valid_id($id)) {
        return null;
    }
    $file = job_file($id);
    if (!is_file($file)) {
        return null;
    }
    $raw = file_get_contents($file);
    if ($raw === false) {
        return null;
    }
    try {
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    return is_array($data) ? $data : null;
}

function save_job(array $job): void
{
    $id = (string) ($job['id'] ?? '');
    write_json_atomic(job_file($id), $job);
}

/** @return array{id: string, spawned: bool} */
function create_export_job(array $project): array
{
    if (!ffmpeg_available()) {
        throw new RuntimeException('FFmpeg is unavailable.');
    }
    if (!directories_writable()) {
        throw new RuntimeException('Upload folder is not writable.');
    }
    cleanup_old_jobs();

    $id = new_id();
    $dir = job_dir($id);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Export failed.');
    }

    $job = [
        'id' => $id,
        'status' => 'queued',
        'progress' => 0,
        'createdAt' => time(),
        'startedAt' => null,
        'finishedAt' => null,
        'error' => '',
        'duration' => round(project_duration($project), 3),
        'name' => (string) $project['name'],
        'project' => $project,
    ];
    save_job($job);

    return [
        'id' => $id,
        'spawned' => spawn_export_worker($id),
    ];
}

function spawn_export_worker(string $jobId): bool
{
    if (!function_exists('popen') || function_is_disabled('popen')) {
        app_log('popen is disabled; export will run in the request.');
        return false;
    }
    $php = resolve_php_cli();
    if ($php === '' || !is_file($php)) {
        app_log('PHP CLI was not found.');
        return false;
    }
    $worker = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'export_worker.php';
    if (!is_file($worker)) {
        return false;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'start "" /B ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($jobId);
    } else {
        $command = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($jobId) . ' > /dev/null 2>&1 &';
    }

    $handle = @popen($command, 'r');
    if (!is_resource($handle)) {
        app_log('Could not start export worker.');
        return false;
    }
    pclose($handle);
    return true;
}

function run_export_job(string $jobId): void
{
    $job = load_job($jobId);
    if ($job === null) {
        return;
    }
    if (($job['status'] ?? '') !== 'queued') {
        return;
    }

    $job['status'] = 'running';
    $job['startedAt'] = time();
    $job['error'] = '';
    save_job($job);

    $output = job_output_file($jobId);
    $progress = job_progress_file($jobId);
    $log = job_log_file($jobId);

    try {
        if (is_file($output)) {
            @unlink($output);
        }
        $args = build_export_command($job['project'], job_dir($jobId), $output, $progress);
        $logged = implode(' ', array_map(static fn ($part): string => escapeshellarg((string) $part), $args));
        file_put_contents($log, $logged . PHP_EOL, FILE_APPEND);

        $result = run_logged_process($args, $log);
        if ($result['code'] !== 0 || !is_file($output) || filesize($output) === 0) {
            @unlink($output);
            throw new RuntimeException(classify_export_error($result['stderr']));
        }

        $fresh = load_job($jobId) ?? $job;
        $fresh['status'] = 'done';
        $fresh['progress'] = 100;
        $fresh['finishedAt'] = time();
        $fresh['error'] = '';
        $fresh['bytes'] = filesize($output);
        save_job($fresh);
    } catch (Throwable $e) {
        app_log('Export job failed: ' . $e->getMessage());
        $fresh = load_job($jobId) ?? $job;
        $fresh['status'] = 'error';
        $fresh['finishedAt'] = time();
        $fresh['error'] = safe_user_message($e->getMessage());
        if ($fresh['error'] === 'An unexpected error occurred.') {
            $fresh['error'] = 'Export failed.';
        }
        save_job($fresh);
    }
}

function run_logged_process(array $command, string $logFile): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $logFile, 'a'],
        2 => ['file', $logFile, 'a'],
    ];
    $process = proc_open($command, $descriptors, $pipes, app_root(), null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['code' => 1, 'stdout' => '', 'stderr' => 'Failed to start process'];
    }
    fclose($pipes[0]);
    $code = proc_close($process);
    $stderr = '';
    if (is_file($logFile)) {
        $stderr = (string) file_get_contents($logFile);
    }
    return ['code' => $code, 'stdout' => '', 'stderr' => $stderr];
}

function classify_export_error(string $stderr): string
{
    $text = strtolower($stderr);
    if (str_contains($text, 'no such file') || str_contains($text, 'does not exist')) {
        return 'Missing media.';
    }
    if (str_contains($text, 'font file') || str_contains($text, 'cannot find a valid font')) {
        return 'A required font is not available on the server.';
    }
    if (str_contains($text, 'unknown encoder') || str_contains($text, 'encoder') && str_contains($text, 'not found')) {
        return 'Export failed.';
    }
    return 'Export failed.';
}

function public_job_status(string $id): array
{
    $job = load_job($id);
    if ($job === null) {
        throw new RuntimeException('Export job not found.');
    }

    if (($job['status'] ?? '') === 'queued' && (time() - (int) ($job['createdAt'] ?? time())) > 20) {
        $job['status'] = 'error';
        $job['error'] = 'Export could not be started.';
        $job['finishedAt'] = time();
        save_job($job);
    }

    $status = (string) ($job['status'] ?? 'queued');
    $progress = 0;
    if ($status === 'running') {
        $rendered = read_ffmpeg_progress(job_progress_file($id));
        $duration = max(0.001, (float) ($job['duration'] ?? 1));
        $progress = (int) min(99, max(1, (int) round(($rendered / $duration) * 100)));
    } elseif ($status === 'done') {
        $progress = 100;
    }

    $started = (int) ($job['startedAt'] ?? $job['createdAt'] ?? time());
    $finished = (int) ($job['finishedAt'] ?? time());
    $elapsed = max(0, $finished - $started);
    $error = (string) ($job['error'] ?? '');

    return [
        'ok' => $status !== 'error',
        'message' => $status === 'error' ? ($error !== '' ? $error : 'Export failed.') : 'OK',
        'job' => $id,
        'status' => $status,
        'progress' => $progress,
        'elapsed' => $elapsed,
        'error' => $error,
        'download' => $status === 'done' ? 'api/download.php?job=' . rawurlencode($id) : null,
    ];
}

function read_ffmpeg_progress(string $file): float
{
    if (!is_file($file)) {
        return 0.0;
    }
    $text = file_get_contents($file);
    if (!is_string($text) || $text === '') {
        return 0.0;
    }
    if (preg_match_all('/out_time_us=(\d+)/', $text, $matches) && $matches[1] !== []) {
        return ((int) $matches[1][count($matches[1]) - 1]) / 1000000;
    }
    if (preg_match_all('/out_time_ms=(\d+)/', $text, $matches) && $matches[1] !== []) {
        $raw = (int) $matches[1][count($matches[1]) - 1];
        return $raw > 100000 ? $raw / 1000000 : $raw / 1000;
    }
    if (preg_match_all('/out_time=(\d+):(\d+):([0-9.]+)/', $text, $matches) && $matches[0] !== []) {
        $i = count($matches[0]) - 1;
        return ((int) $matches[1][$i]) * 3600 + ((int) $matches[2][$i]) * 60 + (float) $matches[3][$i];
    }
    return 0.0;
}

function cleanup_old_jobs(): void
{
    $dir = export_dir() . '/jobs';
    if (!is_dir($dir)) {
        return;
    }
    $files = scandir($dir);
    if ($files === false) {
        return;
    }
    $now = time();
    foreach ($files as $file) {
        if (!preg_match('/^([a-f0-9]{32})\.json$/', $file, $m)) {
            continue;
        }
        $path = $dir . '/' . $file;
        $job = load_job($m[1]);
        $created = (int) ($job['createdAt'] ?? filemtime($path));
        $status = (string) ($job['status'] ?? '');
        if ($status === 'running' || $status === 'queued') {
            continue;
        }
        if (($now - $created) < EXPORT_JOB_TTL) {
            continue;
        }
        remove_job_files($m[1]);
    }
}

function remove_job_files(string $id): void
{
    if (!is_valid_id($id)) {
        return;
    }
    $output = job_output_file($id);
    if (is_file($output)) {
        @unlink($output);
    }
    $folder = job_dir($id);
    if (is_dir($folder)) {
        $items = scandir($folder);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $target = $folder . DIRECTORY_SEPARATOR . $item;
                if (is_file($target)) {
                    @unlink($target);
                }
            }
        }
        @rmdir($folder);
    }
    $json = job_file($id);
    if (is_file($json)) {
        @unlink($json);
    }
}

function resolved_output_file(string $id): string
{
    $path = job_output_file($id);
    $root = realpath(export_dir() . '/output');
    $real = realpath($path);
    if ($root === false || $real === false || !is_file($real)) {
        throw new RuntimeException('Export job not found.');
    }
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $realNorm = str_replace('\\', '/', $real);
    if (!str_starts_with($realNorm, $rootNorm)) {
        throw new RuntimeException('Invalid media path.');
    }
    return $real;
}
