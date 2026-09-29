<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
install_api_error_handler();
assert_same_origin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_error('Invalid request.', 405);
}

try {
    $body = read_json_body();
    $project = sanitize_project($body);
} catch (ValidationException $e) {
    json_error($e->getMessage(), 400);
}

$created = create_export_job($project);
$payload = [
    'ok' => true,
    'message' => 'Export started',
    'job' => $created['id'],
    'status' => 'queued',
];

if ($created['spawned']) {
    json_response($payload);
}

ignore_user_abort(true);
@set_time_limit(0);
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    json_error('Export failed.', 500);
}

if (!headers_sent()) {
    send_security_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
}
echo $json;
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

run_export_job($created['id']);
exit;
