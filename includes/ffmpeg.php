<?php
declare(strict_types=1);

function ffmpeg_binary(): ?string
{
    static $cached = false;
    static $path = null;
    if ($cached) {
        return $path;
    }
    $cached = true;
    $path = find_tool('ffmpeg', FFMPEG_PATH);
    return $path;
}

function ffprobe_binary(): ?string
{
    static $cached = false;
    static $path = null;
    if ($cached) {
        return $path;
    }
    $cached = true;
    $path = find_tool('ffprobe', FFPROBE_PATH);
    return $path;
}

function find_tool(string $name, string $configured): ?string
{
    if ($configured !== '') {
        if (is_file($configured)) {
            return $configured;
        }
        app_log('Configured ' . $name . ' path does not exist.');
        return null;
    }

    $candidates = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $candidates[] = app_root() . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $name . '.exe';
    }
    $candidates[] = app_root() . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $name;
    if (PHP_OS_FAMILY !== 'Windows') {
        $candidates[] = '/usr/bin/' . $name;
        $candidates[] = '/usr/local/bin/' . $name;
    }

    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        if (!is_executable($candidate)) {
            @chmod($candidate, 0755);
        }
        return $candidate;
    }

    if (function_is_disabled('shell_exec') || !function_exists('shell_exec')) {
        return null;
    }

    $lookup = PHP_OS_FAMILY === 'Windows'
        ? 'where ' . $name . ' 2>NUL'
        : 'command -v ' . escapeshellcmd($name) . ' 2>/dev/null';
    $output = shell_exec($lookup);
    if (!is_string($output) || trim($output) === '') {
        return null;
    }
    $line = trim(strtok($output, "\r\n") ?: '');
    if ($line !== '' && is_file($line)) {
        return $line;
    }
    return null;
}

function tool_works(string $path, string $needle): bool
{
    $result = run_process([$path, '-hide_banner', '-version']);
    $text = strtolower($result['stdout'] . $result['stderr']);
    return $result['code'] === 0 && str_contains($text, strtolower($needle));
}

function ffmpeg_available(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $bin = ffmpeg_binary();
    $ok = $bin !== null && tool_works($bin, 'ffmpeg');
    return $ok;
}

function ffprobe_available(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $bin = ffprobe_binary();
    $ok = $bin !== null && tool_works($bin, 'ffprobe');
    return $ok;
}

function resolve_php_cli(): string
{
    if (PHP_CLI_PATH !== '' && is_file(PHP_CLI_PATH)) {
        return PHP_CLI_PATH;
    }
    if (PHP_OS_FAMILY === 'Windows' && is_file('C:\\xampp\\php\\php.exe')) {
        return 'C:\\xampp\\php\\php.exe';
    }
    if (PHP_BINARY !== '' && is_file(PHP_BINARY) && preg_match('/php(?:-cgi|-fpm)?(?:\.exe)?$/i', PHP_BINARY)) {
        return PHP_BINARY;
    }
    if (!function_is_disabled('shell_exec') && function_exists('shell_exec')) {
        $lookup = PHP_OS_FAMILY === 'Windows' ? 'where php 2>NUL' : 'command -v php 2>/dev/null';
        $output = shell_exec($lookup);
        if (is_string($output)) {
            $line = trim(strtok($output, "\r\n") ?: '');
            if ($line !== '' && is_file($line)) {
                return $line;
            }
        }
    }
    return PHP_BINARY;
}

/** @return array{duration: float, width: int, height: int, hasAudio: bool, hasVideo: bool, videoCodec: string, audioCodec: string} */
function probe_media_file(string $path): array
{
    if (!ffprobe_available()) {
        throw new RuntimeException('FFprobe is unavailable.');
    }
    $ffprobe = ffprobe_binary();
    if ($ffprobe === null) {
        throw new RuntimeException('FFprobe is unavailable.');
    }
    $result = run_process([
        $ffprobe,
        '-v', 'error',
        '-print_format', 'json',
        '-show_format',
        '-show_streams',
        $path,
    ]);
    if ($result['code'] !== 0 || trim($result['stdout']) === '') {
        app_log('FFprobe failed.');
        throw new RuntimeException('Media could not be analysed.');
    }
    try {
        $data = json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Media could not be analysed.');
    }
    if (!is_array($data)) {
        throw new RuntimeException('Media could not be analysed.');
    }

    $duration = (float) ($data['format']['duration'] ?? 0);
    $width = 0;
    $height = 0;
    $hasAudio = false;
    $hasVideo = false;
    $videoCodec = '';
    $audioCodec = '';

    $streams = is_array($data['streams'] ?? null) ? $data['streams'] : [];
    foreach ($streams as $stream) {
        if (!is_array($stream)) {
            continue;
        }
        $type = (string) ($stream['codec_type'] ?? '');
        if ($type === 'video' && !$hasVideo) {
            $hasVideo = true;
            $videoCodec = strtolower((string) ($stream['codec_name'] ?? ''));
            $width = (int) ($stream['width'] ?? 0);
            $height = (int) ($stream['height'] ?? 0);
            if ($duration <= 0 && isset($stream['duration'])) {
                $duration = (float) $stream['duration'];
            }
        }
        if ($type === 'audio' && !$hasAudio) {
            $hasAudio = true;
            $audioCodec = strtolower((string) ($stream['codec_name'] ?? ''));
            if ($duration <= 0 && isset($stream['duration'])) {
                $duration = (float) $stream['duration'];
            }
        }
    }

    return [
        'duration' => max(0.0, $duration),
        'width' => $width,
        'height' => $height,
        'hasAudio' => $hasAudio,
        'hasVideo' => $hasVideo,
        'videoCodec' => $videoCodec,
        'audioCodec' => $audioCodec,
    ];
}

function browser_can_play_video(string $ext, string $codec): bool
{
    $codecs = ['h264', 'avc1', 'vp8', 'vp9', 'av1'];
    $extensions = ['mp4', 'webm', 'mov'];
    return in_array(strtolower($codec), $codecs, true) && in_array(strtolower($ext), $extensions, true);
}

function create_video_thumbnail(string $source, string $dest, float $duration): bool
{
    if (!ffmpeg_available()) {
        return false;
    }
    $ffmpeg = ffmpeg_binary();
    if ($ffmpeg === null) {
        return false;
    }
    $ss = $duration > 0.75 ? '0.500' : '0';
    $result = run_process([
        $ffmpeg, '-y', '-hide_banner',
        '-ss', $ss,
        '-i', $source,
        '-frames:v', '1',
        '-vf', 'scale=320:-2',
        $dest,
    ]);
    return $result['code'] === 0 && is_file($dest) && filesize($dest) > 0;
}

function create_browser_preview(string $source, string $dest, bool $hasAudio): bool
{
    if (!ffmpeg_available()) {
        return false;
    }
    $ffmpeg = ffmpeg_binary();
    if ($ffmpeg === null) {
        return false;
    }
    $args = [
        $ffmpeg, '-y', '-hide_banner',
        '-i', $source,
        '-vf', 'scale=960:-2',
        '-c:v', 'libx264',
        '-preset', 'veryfast',
        '-crf', '30',
        '-pix_fmt', 'yuv420p',
        '-movflags', '+faststart',
    ];
    if ($hasAudio) {
        array_push($args, '-c:a', 'aac', '-b:a', '128k', '-ac', '2');
    } else {
        $args[] = '-an';
    }
    $args[] = $dest;
    $result = run_process($args);
    if ($result['code'] !== 0 || !is_file($dest) || filesize($dest) === 0) {
        @unlink($dest);
        app_log('Preview transcode failed.');
        return false;
    }
    return true;
}

function create_image_thumbnail(string $source, string $dest, string $ext): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }
    $image = match ($ext) {
        'jpg', 'jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($source) : false,
        'png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($source) : false,
        'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
        default => false,
    };
    if (!$image) {
        return false;
    }
    $srcW = imagesx($image);
    $srcH = imagesy($image);
    if ($srcW < 1 || $srcH < 1) {
        imagedestroy($image);
        return false;
    }
    $thumbW = 320;
    $thumbH = max(1, (int) round($srcH * ($thumbW / $srcW)));
    $thumb = imagecreatetruecolor($thumbW, $thumbH);
    if ($thumb === false) {
        imagedestroy($image);
        return false;
    }
    imagecopyresampled($thumb, $image, 0, 0, 0, 0, $thumbW, $thumbH, $srcW, $srcH);
    $ok = imagejpeg($thumb, $dest, 82);
    imagedestroy($image);
    imagedestroy($thumb);
    return $ok && is_file($dest);
}

/** @return array{0:int,1:int} */
function export_pixel_size(string $mode): array
{
    return $mode === 'high' ? [1920, 1080] : [1280, 720];
}

function amix_supports_normalize(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $ffmpeg = ffmpeg_binary();
    if ($ffmpeg === null) {
        $cached = false;
        return false;
    }
    $result = run_process([$ffmpeg, '-hide_banner', '-h', 'filter=amix']);
    $cached = str_contains(strtolower($result['stdout'] . $result['stderr']), 'normalize');
    return $cached;
}

/** @return array<string, array{regular: string, bold: string, italic: string, boldItalic: string}> */
function font_file_map(): array
{
    return [
        'Arial' => [
            'regular' => 'arial.ttf',
            'bold' => 'arialbd.ttf',
            'italic' => 'ariali.ttf',
            'boldItalic' => 'arialbi.ttf',
        ],
        'Verdana' => [
            'regular' => 'verdana.ttf',
            'bold' => 'verdanab.ttf',
            'italic' => 'verdanai.ttf',
            'boldItalic' => 'verdanaz.ttf',
        ],
        'Georgia' => [
            'regular' => 'georgia.ttf',
            'bold' => 'georgiab.ttf',
            'italic' => 'georgiai.ttf',
            'boldItalic' => 'georgiaz.ttf',
        ],
        'Times New Roman' => [
            'regular' => 'times.ttf',
            'bold' => 'timesbd.ttf',
            'italic' => 'timesi.ttf',
            'boldItalic' => 'timesbi.ttf',
        ],
        'Courier New' => [
            'regular' => 'cour.ttf',
            'bold' => 'courbd.ttf',
            'italic' => 'couri.ttf',
            'boldItalic' => 'courbi.ttf',
        ],
    ];
}

function resolve_font_file(string $family, bool $bold, bool $italic): string
{
    $map = font_file_map();
    $family = isset($map[$family]) ? $family : 'Arial';
    $style = 'regular';
    if ($bold && $italic) {
        $style = 'boldItalic';
    } elseif ($bold) {
        $style = 'bold';
    } elseif ($italic) {
        $style = 'italic';
    }
    $file = $map[$family][$style];
    $names = array_unique([
        $file,
        $map['Arial'][$style],
        'arial.ttf',
        'DejaVuSans.ttf',
        'LiberationSans-Regular.ttf',
    ]);

    $directories = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $directories[] = 'C:/Windows/Fonts';
    }
    $directories[] = '/usr/share/fonts/truetype/dejavu';
    $directories[] = '/usr/share/fonts/truetype/liberation';
    $directories[] = '/usr/share/fonts/truetype/freefont';
    $directories[] = app_root() . '/assets/fonts';

    foreach ($directories as $dir) {
        foreach ($names as $name) {
            $path = rtrim(str_replace('\\', '/', $dir), '/') . '/' . $name;
            if (is_file($path)) {
                return $path;
            }
        }
    }
    throw new RuntimeException('A required font is not available on the server.');
}

function ffmpeg_filter_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = str_replace(':', '\\:', $path);
    $path = str_replace("'", "'\\''", $path);
    return "'" . $path . "'";
}
