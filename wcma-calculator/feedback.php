<?php
/**
 * Feedback / bug report endpoint. POST only, returns JSON.
 * Stores the report, pushes it to GitHub best-effort, and emails the
 * configured feedback recipient. See feedback-lib.php for the logic.
 */
use PHPMailer\PHPMailer\PHPMailer;

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';
require __DIR__ . '/db.php';
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/feedback-lib.php';

date_default_timezone_set('America/Denver');
header('Content-Type: application/json');

function feedbackFail(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'errors' => [$message]]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    feedbackFail(405, 'Method not allowed.');
}
if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
    feedbackFail(403, 'Your session expired. Please reload the page and try again.');
}

$pdo = db_connect();
db_init($pdo);

$sessionUser = current_user();
$user = null;
if ($sessionUser !== null) {
    $row  = db_find_user_by_id($pdo, (int)$sessionUser['id']);
    $user = ['id' => $sessionUser['id'], 'name' => $sessionUser['name'], 'email' => $row['email'] ?? null];
}

$base      = feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', ''));
$recipient = feedbackRecipient($pdo);

$notify = function (array $row, ?string $issueUrl) use ($recipient, $base): void {
    $content = feedbackBuildEmail($row, $issueUrl, feedbackAdminUrl($base, (int)$row['id']));

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = (SMTP_PORT === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(FROM_EMAIL, FROM_NAME);
    $mail->addAddress($recipient['email'], $recipient['name']);
    if (!empty($row['email'])) {
        $mail->addReplyTo($row['email'], (string)($row['name'] ?? ''));
    }
    $mail->isHTML(true);
    $mail->Subject = $content['subject'];
    $mail->Body    = $content['html'];
    $mail->AltBody = $content['text'];
    $mail->send();
};

$result = feedbackHandleSubmission($pdo, $_POST, [
    'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
    'now'        => time(),
    'user'       => $user,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'rate_limit' => 5,
    'rate_window'=> 3600,
    'github'     => feedbackGithubConfig($base),
], 'feedbackGithubHttp', $notify);

http_response_code($result['status']);
echo json_encode($result['body']);
