<?php
// wcma-calculator/unsubscribe.php — turns reminder emails off from the link in a reminder email
// (spec §7). No sign-in needed: the link carries the account id and an HMAC token
// (reminderUnsubscribeUrl()), which stands in for a CSRF token. A GET only shows a confirm button, so
// link scanners that open every URL in an email can't unsubscribe anyone. A POST with a valid token
// turns reminders off; mail apps' one-click unsubscribe (List-Unsubscribe-Post) POSTs to the same URL.
require __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/view_helpers.php';
require_once __DIR__ . '/reminders-lib.php';

date_default_timezone_set('America/Denver');
$pdo = db_connect();
db_init($pdo);

$param = fn(string $k): string => is_string($_GET[$k] ?? null) ? $_GET[$k] : (is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
$uid = ctype_digit($param('u')) ? (int)$param('u') : 0;
$token = $param('t');
$valid = reminderTokenValid($uid, $token, reminderSecret($pdo)) && db_find_user_by_id($pdo, $uid) !== null;

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    db_set_user_reminders($pdo, $uid, false);
    $state = 'done';
} elseif ($valid) {
    $state = 'confirm';
} else {
    http_response_code(400);
    $state = 'invalid';
}

renderPageStart('Reminder emails', '');
echo renderUnsubscribeHtml($state, $uid, $token);
renderPageEnd();
