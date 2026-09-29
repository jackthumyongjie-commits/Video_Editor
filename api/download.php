<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
install_api_error_handler();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Invalid request.', 405);
}

$id = (string) ($_GET['job'] ?? '');
if (!is_valid_id($id)) {
    json_error('Export job not found.', 404);
}

$job = load_job($id);
if ($job === null || ($job['status'] ?? '') !== 'done') {
    json_error('Export job not found.', 404);
}

try {
    $path = resolved_output_file($id);
} catch (RuntimeException) {
    json_error('Export job not found.', 404);
}

$name = safe_download_name((string) ($job['name'] ?? 'video'));
serve_file($path, 'video/mp4', $name, true);
