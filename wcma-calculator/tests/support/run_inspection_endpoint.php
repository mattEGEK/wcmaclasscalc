<?php
// Test-only harness: runs inspection.php in a fresh process and prints
// {status, headers, body} as JSON. Not a test itself (only *Test.php files are run).
// Note: headers_list() is always empty under the CLI SAPI, so 'headers' is not
// meaningful here; header assertions use inspection_router.php over HTTP instead.
// Usage: php run_inspection_endpoint.php <payload.json>
// Payload: {db_path, method, get, post, session: {user_id, user_name, user_role, csrf_token}|null}

$payload = json_decode(file_get_contents($argv[1]), true);
define('DB_PATH', $payload['db_path']);

session_start();
if (!empty($payload['session'])) {
    foreach ($payload['session'] as $k => $v) $_SESSION[$k] = $v;
}
$_SERVER['REQUEST_METHOD'] = $payload['method'];
$_GET = $payload['get'] ?? [];
$_POST = $payload['post'] ?? [];
$_FILES = [];

ob_start();
register_shutdown_function(function () {
    $body = ob_get_clean();
    // Emit on the real stdout, delimited so stray output cannot corrupt the JSON.
    fwrite(STDOUT, "\n@@RESULT@@" . json_encode([
        // In CLI http_response_code() is false until a code is set explicitly; that means 200.
        'status' => http_response_code() ?: 200,
        'headers' => headers_list(),
        'body' => base64_encode((string)$body),
    ]));
    session_destroy();
});

require __DIR__ . '/../../inspection.php';
