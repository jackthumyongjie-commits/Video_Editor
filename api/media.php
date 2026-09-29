<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
install_api_error_handler();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    json_response([
        'ok' => true,
        'message' => 'OK',
        'media' => list_media(),
    ]);
}

if ($method !== 'POST') {
    json_error('Invalid request.', 405);
}

assert_same_origin();
$body = read_json_body();
$action = (string) ($body['action'] ?? '');
$id = (string) ($body['id'] ?? '');

if ($action !== 'delete' || !is_valid_id($id)) {
    json_error('Invalid request.', 400);
}

$meta = load_meta($id);
if ($meta === null) {
    json_error('Missing media.', 404);
}

delete_media_files($meta);
json_response([
    'ok' => true,
    'message' => 'Media deleted',
    'id' => $id,
]);
