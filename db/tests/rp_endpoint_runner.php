<?php
/**
 * Subprocess runner: includes a PHP page/endpoint in an isolated process
 * so its exit() calls don't kill the parent test harness.
 *
 * Env RP_ARGS = base64(json): {"file": "...", "session": {...}, "post": {...}, "get": {...}}
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', 'C:/Users/Admin/AppData/Local/Temp/opencode/rp_runner_errors.log');

$args = json_decode(base64_decode(getenv('RP_ARGS')), true);
if (!$args || empty($args['file'])) {
    echo json_encode(['runner_error' => 'missing args']);
    exit(1);
}

session_id('RPTESTSESS');
$_SESSION = [];
session_start();
$_SESSION = $args['session'] ?? [];
session_write_close();

$_POST = $args['post'] ?? [];
$_GET = $args['get'] ?? [];

ob_start();
try {
    include $args['file'];
} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode(['runner_error' => $e->getMessage()]);
    exit(1);
}
echo ob_get_clean();
