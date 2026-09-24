<?php
// Test-only router for `php -S`, used where real response headers are needed
// (headers_list() is empty under the CLI SAPI). Configured through env vars:
// WCMA_TEST_DB (sqlite path) and WCMA_TEST_SESSION (JSON of $_SESSION values).
define('DB_PATH', getenv('WCMA_TEST_DB'));
session_start();
foreach (json_decode(getenv('WCMA_TEST_SESSION'), true) ?: [] as $k => $v) $_SESSION[$k] = $v;
require __DIR__ . '/../../inspection.php';
