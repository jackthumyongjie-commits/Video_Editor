<?php
declare(strict_types=1);

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex');
}

function json_response(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        send_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $json === false ? '{"ok":false,"message":"An unexpected error occurred."}' : $json;
    exit;
}

function json_error(string $message, int $status = 400, array $extra = []): never
{
    json_response(array_merge(['ok' => false, 'message' => $message], $extra), $status);
}

function app_log(string $message): void
{
    $dir = log_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = date('c') . ' ' . str_replace(["\r", "\n"], ' ', $message) . PHP_EOL;
    @file_put_contents($dir . DIRECTORY_SEPARATOR . 'app.log', $line, FILE_APPEND | LOCK_EX);
}

function ensure_directories(): void
{
    $dirs = [
        upload_dir() . '/videos',
        upload_dir() . '/images',
        upload_dir() . '/audio',
        upload_dir() . '/meta',
        upload_dir() . '/thumbs',
        export_dir() . '/jobs',
        export_dir() . '/output',
        log_dir(),
    ];
    foreach ($dirs as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            app_log('Could not create directory.');
        }
    }
}

function read_json_body(int $maxBytes = MAX_PROJECT_BYTES): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false) {
        throw new ValidationException('Invalid project data.');
    }
    if (strlen($raw) > $maxBytes) {
        throw new ValidationException('Invalid project data.');
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    if (trim($raw) === '') {
        throw new ValidationException('Invalid project data.');
    }
    try {
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new ValidationException('Invalid project data.');
    }
    if (!is_array($data)) {
        throw new ValidationException('Invalid project data.');
    }
    return $data;
}

function is_valid_id(string $id): bool
{
    return (bool) preg_match('/^[a-f0-9]{32}$/', $id);
}

function new_id(): string
{
    return bin2hex(random_bytes(16));
}

function safe_original_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $clean = preg_replace('/[^\p{L}\p{N}\s._()\-]/u', '', $name);
    if (!is_string($clean) || trim($clean) === '') {
        $clean = 'media';
    }
    $clean = trim($clean);
    if (function_exists('mb_substr')) {
        return mb_substr($clean, 0, 120);
    }
    return substr($clean, 0, 120);
}

function safe_download_name(string $name): string
{
    $name = safe_original_name($name);
    $name = preg_replace('/\s+/', '-', $name) ?? 'video';
    $name = preg_replace('/[^A-Za-z0-9._\-]/', '', $name) ?? 'video';
    $name = trim($name, '.-');
    if ($name === '') {
        $name = 'video';
    }
    return $name . '.mp4';
}

function assert_inside(string $root, string $path): string
{
    $rootReal = realpath($root);
    $real = realpath($path);
    if ($rootReal === false || $real === false || !is_file($real)) {
        throw new RuntimeException('Invalid media path.');
    }
    $rootNorm = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
    $realNorm = str_replace('\\', '/', $real);
    if (!str_starts_with($realNorm, $rootNorm)) {
        throw new RuntimeException('Invalid media path.');
    }
    return $real;
}

function kind_folder(string $kind): string
{
    return match ($kind) {
        'video' => 'videos',
        'image' => 'images',
        'audio' => 'audio',
        default => throw new RuntimeException('Invalid media path.'),
    };
}

function meta_file(string $id): string
{
    if (!is_valid_id($id)) {
        throw new RuntimeException('Invalid media path.');
    }
    return upload_dir() . '/meta/' . $id . '.json';
}

function load_meta(string $id): ?array
{
    if (!is_valid_id($id)) {
        return null;
    }
    $file = meta_file($id);
    if (!is_file($file)) {
        return null;
    }
    $raw = file_get_contents($file);
    if ($raw === false) {
        return null;
    }
    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    return is_array($data) ? $data : null;
}

function save_meta(array $meta): void
{
    $id = (string) ($meta['id'] ?? '');
    if (!is_valid_id($id)) {
        throw new RuntimeException('Invalid media path.');
    }
    write_json_atomic(meta_file($id), $meta);
}

function write_json_atomic(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Could not store data.');
    }
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Could not store data.');
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not store data.');
    }
}

function media_file(array $meta, string $suffix = ''): string
{
    $id = (string) ($meta['id'] ?? '');
    $ext = strtolower((string) ($meta['ext'] ?? ''));
    $kind = (string) ($meta['kind'] ?? '');
    if (!is_valid_id($id) || !preg_match('/^[a-z0-9]{1,5}$/', $ext)) {
        throw new RuntimeException('Invalid media path.');
    }
    $folder = upload_dir() . '/' . kind_folder($kind);
    $name = $suffix === '' ? ($id . '.' . $ext) : ($id . $suffix);
    return assert_inside_or_missing($folder, $folder . '/' . $name);
}

function assert_inside_or_missing(string $root, string $path): string
{
    $rootReal = realpath($root);
    if ($rootReal === false) {
        throw new RuntimeException('Invalid media path.');
    }
    $rootNorm = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
    $candidate = str_replace('\\', '/', $path);
    $real = realpath($path);
    if ($real !== false) {
        $realNorm = str_replace('\\', '/', $real);
        if (!str_starts_with($realNorm, $rootNorm)) {
            throw new RuntimeException('Invalid media path.');
        }
        return $real;
    }
    $parent = realpath(dirname($path));
    if ($parent === false) {
        throw new RuntimeException('Invalid media path.');
    }
    $parentNorm = rtrim(str_replace('\\', '/', $parent), '/') . '/';
    if (!str_starts_with($parentNorm, $rootNorm) && rtrim($parentNorm, '/') !== rtrim($rootNorm, '/')) {
        throw new RuntimeException('Invalid media path.');
    }
    $base = basename($path);
    if (!preg_match('/^[a-f0-9]{32}(\.[a-z0-9]{1,5}|_preview\.mp4|_thumb\.jpg)$/', $base)) {
        throw new RuntimeException('Invalid media path.');
    }
    return $parent . DIRECTORY_SEPARATOR . $base;
}

function thumb_file(string $id): string
{
    if (!is_valid_id($id)) {
        throw new RuntimeException('Invalid media path.');
    }
    return assert_inside_or_missing(upload_dir() . '/thumbs', upload_dir() . '/thumbs/' . $id . '_thumb.jpg');
}

function preview_file(string $id): string
{
    if (!is_valid_id($id)) {
        throw new RuntimeException('Invalid media path.');
    }
    return assert_inside_or_missing(upload_dir() . '/videos', upload_dir() . '/videos/' . $id . '_preview.mp4');
}

/** @return list<array<string, mixed>> */
function list_media(): array
{
    $dir = upload_dir() . '/meta';
    $items = [];
    if (!is_dir($dir)) {
        return [];
    }
    $files = scandir($dir);
    if ($files === false) {
        return [];
    }
    foreach ($files as $file) {
        if (!preg_match('/^([a-f0-9]{32})\.json$/', $file, $m)) {
            continue;
        }
        $meta = load_meta($m[1]);
        if ($meta === null) {
            continue;
        }
        $items[] = public_media($meta);
    }
    usort($items, static function (array $a, array $b): int {
        return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
    });
    return $items;
}

function public_media(array $meta): array
{
    $id = (string) ($meta['id'] ?? '');
    $thumb = !empty($meta['thumbAvailable']);
    $preview = !empty($meta['previewAvailable']);
    return [
        'id' => $id,
        'kind' => (string) ($meta['kind'] ?? ''),
        'originalName' => (string) ($meta['originalName'] ?? 'media'),
        'ext' => (string) ($meta['ext'] ?? ''),
        'mime' => (string) ($meta['mime'] ?? 'application/octet-stream'),
        'size' => (int) ($meta['size'] ?? 0),
        'duration' => round((float) ($meta['duration'] ?? 0), 3),
        'width' => (int) ($meta['width'] ?? 0),
        'height' => (int) ($meta['height'] ?? 0),
        'hasAudio' => (bool) ($meta['hasAudio'] ?? false),
        'hasVideo' => (bool) ($meta['hasVideo'] ?? false),
        'browserPlayable' => (bool) ($meta['browserPlayable'] ?? false),
        'previewAvailable' => $preview,
        'thumbAvailable' => $thumb,
        'createdAt' => (string) ($meta['createdAt'] ?? ''),
        'url' => 'api/stream.php?id=' . rawurlencode($id),
        'thumbUrl' => $thumb ? 'api/stream.php?id=' . rawurlencode($id) . '&thumb=1' : null,
        'previewUrl' => $preview ? 'api/stream.php?id=' . rawurlencode($id) . '&preview=1' : null,
    ];
}

function delete_media_files(array $meta): void
{
    $paths = [];
    try {
        $paths[] = media_file($meta);
    } catch (Throwable) {
    }
    $id = (string) ($meta['id'] ?? '');
    if (is_valid_id($id)) {
        $paths[] = thumb_file($id);
        $paths[] = preview_file($id);
        $paths[] = meta_file($id);
    }
    foreach ($paths as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function ffnum(float $value): string
{
    $formatted = sprintf('%.3f', $value);
    if ($formatted === '-0.000') {
        return '0.000';
    }
    return $formatted;
}

function directories_writable(): bool
{
    $checks = [
        upload_dir() . '/videos',
        upload_dir() . '/meta',
        export_dir() . '/jobs',
        export_dir() . '/output',
        log_dir(),
    ];
    foreach ($checks as $dir) {
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }
    }
    return true;
}

function assert_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }
    $originHost = parse_url($origin, PHP_URL_HOST);
    if (!is_string($originHost) || $originHost === '') {
        json_error('Invalid request.', 403);
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $hostOnly = preg_replace('/:\d+$/', '', $host) ?? $host;
    if (strcasecmp($originHost, $hostOnly) !== 0) {
        json_error('Invalid request.', 403);
    }
}

function mime_for_extension(string $ext): string
{
    return match ($ext) {
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'wmv' => 'video/x-ms-wmv',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'aac' => 'audio/aac',
        'm4a' => 'audio/mp4',
        default => 'application/octet-stream',
    };
}

function install_api_error_handler(): void
{
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    set_exception_handler(static function (Throwable $e): void {
        app_log($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        if ($e instanceof ValidationException) {
            json_error($e->getMessage(), 400);
        }
        $safe = safe_user_message($e->getMessage());
        json_error($safe, $safe === 'An unexpected error occurred.' ? 500 : 400);
    });
}

function safe_user_message(string $message): string
{
    $allowed = [
        'Unsupported file type.',
        'File is too large.',
        'Upload failed.',
        'FFmpeg is unavailable.',
        'FFprobe is unavailable.',
        'Media could not be analysed.',
        'Export failed.',
        'Export job not found.',
        'Invalid project data.',
        'Invalid media path.',
        'Missing media.',
        'Add media to the timeline before exporting.',
        'Project is too long to export.',
        'Project is too complex to export.',
        'Media is too short.',
        'A required font is not available on the server.',
        'Upload folder is not writable.',
        'Export could not be started.',
        'Invalid playback speed.',
        'Invalid request.',
        'File was only partially uploaded.',
    ];
    foreach ($allowed as $ok) {
        if ($message === $ok) {
            return $ok;
        }
    }
    return 'An unexpected error occurred.';
}

function run_process(array $command): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, app_root(), null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['code' => 1, 'stdout' => '', 'stderr' => 'Failed to start process'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return [
        'code' => $code,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function disabled_functions(): array
{
    $raw = (string) ini_get('disable_functions');
    if ($raw === '') {
        return [];
    }
    return array_map(static fn (string $name): string => strtolower(trim($name)), explode(',', $raw));
}

function function_is_disabled(string $name): bool
{
    return in_array(strtolower($name), disabled_functions(), true);
}

function serve_file(string $path, string $mime, string $downloadName = '', bool $attachment = false): never
{
    if (!is_file($path)) {
        json_error('Missing media.', 404);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $size = filesize($path);
    if ($size === false) {
        json_error('Missing media.', 404);
    }

    $start = 0;
    $end = $size > 0 ? $size - 1 : 0;
    $length = $size;

    send_security_headers();
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    if ($attachment) {
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Cache-Control: no-store');
    } else {
        header('Content-Disposition: inline');
        header('Cache-Control: public, max-age=86400');
    }

    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if (!$attachment && is_string($range) && preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
        if ($matches[1] === '' && $matches[2] !== '') {
            $suffix = (int) $matches[2];
            $start = max(0, $size - $suffix);
        } else {
            $start = $matches[1] === '' ? 0 : (int) $matches[1];
            $end = $matches[2] === '' ? $size - 1 : min($size - 1, (int) $matches[2]);
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $length = $end - $start + 1;
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    } else {
        http_response_code(200);
    }

    header('Content-Length: ' . $length);
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        exit;
    }
    if ($start > 0) {
        fseek($handle, $start);
    }
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $read = (int) min(1048576, $remaining);
        $chunk = fread($handle, $read);
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
        if (connection_aborted()) {
            break;
        }
    }
    fclose($handle);
    exit;
}
