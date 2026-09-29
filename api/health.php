<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
install_api_error_handler();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Invalid request.', 405);
}

$ffmpegReady = ffmpeg_available();
$ffprobeReady = ffprobe_available();
$writable = directories_writable();

if (!$writable) {
    $message = 'Upload folder is not writable.';
} elseif ($ffmpegReady && $ffprobeReady) {
    $message = 'FFmpeg: Ready';
} else {
    $message = 'FFmpeg: Not Available';
}

json_response([
    'ok' => true,
    'message' => $message,
    'ffmpeg' => $ffmpegReady,
    'ffprobe' => $ffprobeReady,
    'writable' => $writable,
    'limits' => [
        'video' => MAX_VIDEO_SIZE,
        'image' => MAX_IMAGE_SIZE,
        'audio' => MAX_AUDIO_SIZE,
    ],
    'extensions' => allowed_extensions(),
    'composition' => [
        'width' => COMPOSITION_WIDTH,
        'height' => COMPOSITION_HEIGHT,
        'fps' => COMPOSITION_FPS,
    ],
]);
