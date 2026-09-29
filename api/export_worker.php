<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$jobId = $argv[1] ?? '';
if (!is_valid_id($jobId)) {
    fwrite(STDERR, "Export job not found.\n");
    exit(1);
}

@set_time_limit(0);
run_export_job($jobId);
exit(0);
