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

try {
    $status = public_job_status($id);
} catch (RuntimeException $e) {
    json_error(safe_user_message($e->getMessage()), 404);
}

json_response($status);
