<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
install_api_error_handler();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Invalid request.', 405);
}

$id = (string) ($_GET['id'] ?? '');
if (!is_valid_id($id)) {
    json_error('Missing media.', 404);
}

$meta = load_meta($id);
if ($meta === null) {
    json_error('Missing media.', 404);
}

try {
    if (isset($_GET['thumb'])) {
        $thumb = thumb_file($id);
        if (!is_file($thumb)) {
            json_error('Missing media.', 404);
        }
        serve_file($thumb, 'image/jpeg');
    }

    if (isset($_GET['preview'])) {
        $preview = preview_file($id);
        if (!is_file($preview)) {
            json_error('Missing media.', 404);
        }
        serve_file($preview, 'video/mp4');
    }

    $path = media_file($meta);
    if (!is_file($path)) {
        json_error('Missing media.', 404);
    }
    $ext = strtolower((string) ($meta['ext'] ?? ''));
    serve_file($path, mime_for_extension($ext));
} catch (RuntimeException $e) {
    $message = safe_user_message($e->getMessage());
    json_error($message === 'An unexpected error occurred.' ? 'Missing media.' : $message, 404);
}
