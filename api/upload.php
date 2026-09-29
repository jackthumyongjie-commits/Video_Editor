<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
install_api_error_handler();
assert_same_origin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_error('Invalid request.', 405);
}

if (!directories_writable()) {
    json_error('Upload folder is not writable.', 500);
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    json_error('Upload failed.', 400);
}

$file = $_FILES['file'];
$error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
    json_error('File is too large.', 413);
}
if ($error === UPLOAD_ERR_PARTIAL) {
    json_error('File was only partially uploaded.', 400);
}
if ($error !== UPLOAD_ERR_OK) {
    json_error('Upload failed.', 400);
}

$tmp = (string) ($file['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    json_error('Upload failed.', 400);
}

$original = safe_original_name((string) ($file['name'] ?? 'media'));
$ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
$kind = null;
foreach (allowed_extensions() as $candidate => $extensions) {
    if (in_array($ext, $extensions, true)) {
        $kind = $candidate;
        break;
    }
}
if ($kind === null) {
    json_error('Unsupported file type.', 415);
}

$size = (int) ($file['size'] ?? 0);
$limits = max_upload_sizes();
if ($size <= 0 || $size > $limits[$kind]) {
    json_error('File is too large.', 413);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$detected = (string) $finfo->file($tmp);
if (!mime_is_allowed($kind, $ext, $detected)) {
    json_error('Unsupported file type.', 415);
}

if ($kind === 'video' || $kind === 'audio') {
    if (!ffprobe_available()) {
        json_error('FFprobe is unavailable.', 503);
    }
}

@set_time_limit(0);

$id = new_id();
$dest = upload_dir() . '/' . kind_folder($kind) . '/' . $id . '.' . $ext;
if (!move_uploaded_file($tmp, $dest)) {
    json_error('Upload failed.', 500);
}

try {
    $meta = [
        'id' => $id,
        'kind' => $kind,
        'originalName' => $original,
        'ext' => $ext,
        'mime' => mime_for_extension($ext),
        'size' => filesize($dest) ?: $size,
        'duration' => 0.0,
        'width' => 0,
        'height' => 0,
        'hasAudio' => false,
        'hasVideo' => false,
        'videoCodec' => '',
        'audioCodec' => '',
        'browserPlayable' => false,
        'previewAvailable' => false,
        'thumbAvailable' => false,
        'createdAt' => date('c'),
    ];

    if ($kind === 'image') {
        $info = @getimagesize($dest);
        if ($info === false) {
            throw new RuntimeException('Unsupported file type.');
        }
        $meta['width'] = (int) ($info[0] ?? 0);
        $meta['height'] = (int) ($info[1] ?? 0);
        $meta['hasVideo'] = false;
        if ($meta['width'] < 1 || $meta['height'] < 1) {
            throw new RuntimeException('Media could not be analysed.');
        }
        $thumb = thumb_file($id);
        if (create_image_thumbnail($dest, $thumb, $ext)) {
            $meta['thumbAvailable'] = true;
        }
    } else {
        $probe = probe_media_file($dest);
        if ($kind === 'video' && !$probe['hasVideo']) {
            throw new RuntimeException('Media could not be analysed.');
        }
        if ($kind === 'audio' && !$probe['hasAudio']) {
            throw new RuntimeException('Media could not be analysed.');
        }
        if ($probe['duration'] < MIN_CLIP_DURATION) {
            throw new RuntimeException('Media is too short.');
        }
        $meta['duration'] = round($probe['duration'], 3);
        $meta['width'] = $probe['width'];
        $meta['height'] = $probe['height'];
        $meta['hasAudio'] = $probe['hasAudio'];
        $meta['hasVideo'] = $probe['hasVideo'];
        $meta['videoCodec'] = $probe['videoCodec'];
        $meta['audioCodec'] = $probe['audioCodec'];

        if ($kind === 'video') {
            $meta['browserPlayable'] = browser_can_play_video($ext, $probe['videoCodec']);
            $thumb = thumb_file($id);
            if (create_video_thumbnail($dest, $thumb, $probe['duration'])) {
                $meta['thumbAvailable'] = true;
            }
            if (!$meta['browserPlayable']) {
                $preview = preview_file($id);
                if (create_browser_preview($dest, $preview, $probe['hasAudio'])) {
                    $meta['previewAvailable'] = true;
                }
            }
        }
    }

    save_meta($meta);
    json_response([
        'ok' => true,
        'message' => 'Upload successful',
        'media' => public_media($meta),
    ]);
} catch (Throwable $e) {
    if (is_file($dest)) {
        @unlink($dest);
    }
    $thumb = upload_dir() . '/thumbs/' . $id . '_thumb.jpg';
    $preview = upload_dir() . '/videos/' . $id . '_preview.mp4';
    if (is_file($thumb)) {
        @unlink($thumb);
    }
    if (is_file($preview)) {
        @unlink($preview);
    }
    $message = safe_user_message($e->getMessage());
    if ($message === 'An unexpected error occurred.') {
        app_log('Upload failed: ' . $e->getMessage());
        json_error('Upload failed.', 500);
    }
    json_error($message, 400);
}

function mime_is_allowed(string $kind, string $ext, string $mime): bool
{
    $allowed = [
        'video' => ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-ms-wmv', 'video/x-ms-asf', 'application/octet-stream'],
        'image' => ['image/jpeg', 'image/png', 'image/webp', 'application/octet-stream'],
        'audio' => ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/aac', 'audio/x-aac', 'audio/mp4', 'audio/x-m4a', 'application/octet-stream'],
    ];
    if (!isset($allowed[$kind]) || !in_array($mime, $allowed[$kind], true)) {
        return false;
    }
    if ($mime === 'application/octet-stream') {
        return in_array($ext, allowed_extensions()[$kind], true);
    }
    return true;
}
