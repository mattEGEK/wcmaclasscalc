<?php
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('America/Denver');

$pdo = db_connect();
db_init($pdo);

const SUBMISSION_SOFT_CAP = 20;

function requireLogin(): array {
    $user = current_user();
    if ($user === null) {
        header('Location: auth.php?action=login');
        exit;
    }
    return $user;
}

function generateCsrfToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function setFlash(string $message, string $type): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$user = requireLogin();
$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'view':
        handleAccountView($pdo, $user, (int)($_GET['id'] ?? 0));
        break;

    case 'resend':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: account.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleAccountResend($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

    case 'delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: account.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleAccountDelete($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

    case 'file':
        handleAccountFile($pdo, $user, (int)($_GET['id'] ?? 0), $_GET['field'] ?? '');
        break;

    case 'list':
    default:
        handleAccountList($pdo, $user);
}

function handleAccountList(PDO $pdo, array $user): void {
    $submissions = db_get_user_submissions($pdo, $user['id']);
    $count = db_count_user_submissions($pdo, $user['id']);
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderAccountListPage($submissions, $count, $csrf, $flash);
}

function renderAccountListPage(array $submissions, int $count, string $csrf, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Submissions — WCMA Calculator</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; margin: 0; background: #f0f2f5; }
  header { background: #1a5490; color: #fff; padding: .8rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
  header h1 { margin: 0; font-size: 1.2rem; }
  header a { color: #cde; font-size: .9rem; }
  main { padding: 1.5rem; }
  .flash, .banner { padding: .7rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: .9rem; }
  .flash.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
  .flash.error   { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
  .banner { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
  th { background: #1a5490; color: #fff; padding: .7rem 1rem; text-align: left; font-size: .85rem; }
  td { padding: .65rem 1rem; border-bottom: 1px solid #eee; font-size: .9rem; }
  tr:last-child td { border-bottom: none; }
  .actions a { color: #1a5490; }
  .empty { text-align: center; color: #888; padding: 2rem; }
</style>
</head>
<body>
<header>
  <h1>My Submissions</h1>
  <a href="car-classing.html">← Back to calculator</a>
</header>
<main>
  <?php if ($flash): ?>
  <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <?php if ($count > SUBMISSION_SOFT_CAP): ?>
  <div class="banner">You have <?= (int)$count ?> saved submissions — consider deleting some older ones.</div>
  <?php endif; ?>
  <table>
    <thead>
      <tr><th>Submitted</th><th>Vehicle</th><th>Class</th><th>Email</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php if (empty($submissions)): ?>
      <tr><td colspan="5" class="empty">No submissions yet.</td></tr>
    <?php else: foreach ($submissions as $s): ?>
      <tr>
        <td><?= h(date('M j, Y H:i', strtotime($s['submitted_at']))) ?></td>
        <td><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></td>
        <td><strong><?= h($s['calculated_class'] ?? '—') ?></strong></td>
        <td><?= $s['email_sent'] ? '✓' : '⚠ Failed' ?></td>
        <td class="actions">
          <a href="account.php?action=view&id=<?= (int)$s['id'] ?>">View</a>
          <form method="post" action="account.php?action=delete" style="display:inline"
                onsubmit="return confirm('Permanently delete this submission and its files?')">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button type="submit" style="background:none;border:none;color:#c00;cursor:pointer;font-size:.85rem;padding:0;margin-left:.6rem">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</main>
</body>
</html><?php
}

function buildAccountMailer(): PHPMailer {
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
    return $mail;
}

function handleAccountView(PDO $pdo, array $user, int $id): void {
    $sub = db_get_user_submission($pdo, $user['id'], $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: account.php');
        exit;
    }
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderAccountViewPage($sub, $csrf, $flash);
}

function renderAccountViewPage(array $s, string $csrf, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Submission #<?= (int)$s['id'] ?> — My Submissions</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; margin: 0; background: #f0f2f5; }
  header { background: #1a5490; color: #fff; padding: .8rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
  header a { color: #cde; font-size: .9rem; }
  main { padding: 1.5rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
  @media (max-width: 700px) { main { grid-template-columns: 1fr; } }
  .card { background: #fff; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,.1); padding: 1.2rem; }
  .card h2 { margin: 0 0 1rem; font-size: 1rem; color: #1a5490; border-bottom: 2px solid #1a5490; padding-bottom: .4rem; }
  table.data td { padding: .35rem .5rem; font-size: .9rem; }
  table.data td:first-child { font-weight: bold; width: 160px; }
  .flash { padding: .7rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: .9rem; grid-column: 1/-1; }
  .flash.success { background: #d4edda; color: #155724; }
  .flash.error { background: #f8d7da; color: #721c24; }
  .btn { display: inline-block; padding: .5rem 1.1rem; border-radius: 4px; font-size: .9rem; cursor: pointer; border: none; }
  .btn-primary { background: #1a5490; color: #fff; }
  .btn-delete { background: #c00; color: #fff; }
  .file-thumb { max-width: 100%; max-height: 200px; border-radius: 4px; margin-top: .5rem; display: block; }
</style>
</head>
<body>
<header>
  <h1>Submission #<?= (int)$s['id'] ?></h1>
  <a href="account.php">← Back to My Submissions</a>
</header>
<main>
  <?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <div class="card">
    <h2>Vehicle &amp; Class</h2>
    <table class="data">
      <tr><td>Vehicle</td><td><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></td></tr>
      <tr><td>Weight</td><td><?= h((string)$s['competition_weight']) ?> lbs</td></tr>
      <tr><td>Declared HP</td><td><?= h((string)$s['declared_hp']) ?></td></tr>
      <tr><td>Calculated Class</td><td><strong><?= h($s['calculated_class'] ?? '—') ?></strong></td></tr>
      <tr><td>Submitted</td><td><?= h(date('F j, Y \a\t g:i A', strtotime($s['submitted_at']))) ?></td></tr>
    </table>
  </div>
  <div class="card">
    <h2>Actions</h2>
    <form method="post" action="account.php?action=resend">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <button type="submit" class="btn btn-primary">Resend Confirmation to My Email</button>
    </form>
    <h2 style="margin-top:1.5rem">Uploaded Files</h2>
    <?php
    $files = [
        'car_image'  => ['label' => 'Car Image',  'path' => $s['car_image_path']],
        'dyno_chart' => ['label' => 'Dyno Chart', 'path' => $s['dyno_chart_path']],
        'dyno_table' => ['label' => 'Dyno Table', 'path' => $s['dyno_table_path']],
    ];
    $any = false;
    foreach ($files as $field => $f):
        if (!$f['path']) continue;
        $any = true;
        $ext = strtolower(pathinfo($f['path'], PATHINFO_EXTENSION));
        $is_image = in_array($ext, ['jpg', 'jpeg', 'png']);
        $url = h('account.php?action=file&id=' . (int)$s['id'] . '&field=' . $field);
    ?>
    <p style="font-weight:bold;margin:.8rem 0 .2rem"><?= h($f['label']) ?></p>
    <?php if ($is_image): ?>
      <img src="<?= $url ?>" class="file-thumb" alt="<?= h($f['label']) ?>">
    <?php else: ?>
      <a href="<?= $url ?>" target="_blank"><?= h(basename($f['path'])) ?></a>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!$any): ?><p style="color:#888">No files uploaded.</p><?php endif; ?>
  </div>
</main>
</body>
</html><?php
}

function handleAccountResend(PDO $pdo, array $user, int $id): void {
    $sub = db_get_user_submission($pdo, $user['id'], $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: account.php');
        exit;
    }

    $attachments = [];
    foreach (['dyno_chart_path', 'dyno_table_path', 'car_image_path'] as $col) {
        if ($sub[$col]) {
            $path = __DIR__ . '/' . $sub[$col];
            if (file_exists($path)) $attachments[] = ['path' => $path, 'name' => basename($path)];
        }
    }

    $sent = false;
    try {
        $mail = buildAccountMailer();
        $mail->addAddress($sub['email'], $sub['name']);
        $mail->Subject = 'Your WCMA Classing Calculator Submission';
        $mail->isHTML(true);
        $mail->Body = '<p>Class: <strong>' . htmlspecialchars($sub['calculated_class'] ?? '') . '</strong></p><p>Vehicle: ' . htmlspecialchars(trim($sub['year'] . ' ' . $sub['make'] . ' ' . $sub['model'])) . '</p>';
        $mail->AltBody = 'Class: ' . ($sub['calculated_class'] ?? '') . "\nVehicle: " . trim($sub['year'] . ' ' . $sub['make'] . ' ' . $sub['model']);
        foreach ($attachments as $att) $mail->addAttachment($att['path'], $att['name']);
        $mail->send();
        $sent = true;
    } catch (Exception $e) {
        error_log('Account resend error: ' . $e->getMessage());
    }

    db_update_email_sent($pdo, $id, $sent ? 1 : 0);
    setFlash($sent ? 'Confirmation re-sent to your email.' : 'Failed to send email. Please try again later.', $sent ? 'success' : 'error');
    header('Location: account.php?action=view&id=' . $id);
    exit;
}

function handleAccountDelete(PDO $pdo, array $user, int $id): void {
    $sub = db_get_user_submission($pdo, $user['id'], $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: account.php');
        exit;
    }

    $upload_dir = __DIR__ . '/uploads/' . $id;
    if (is_dir($upload_dir)) {
        foreach (glob($upload_dir . '/*') as $file) unlink($file);
        rmdir($upload_dir);
    }

    db_delete_submission($pdo, $id);
    setFlash('Submission deleted.', 'success');
    header('Location: account.php');
    exit;
}

function handleAccountFile(PDO $pdo, array $user, int $id, string $field): void {
    $field_map = ['dyno_chart' => 'dyno_chart_path', 'dyno_table' => 'dyno_table_path', 'car_image' => 'car_image_path'];
    if (!isset($field_map[$field])) { http_response_code(404); exit; }

    $sub = db_get_user_submission($pdo, $user['id'], $id);
    $db_field = $field_map[$field];
    if (!$sub || !$sub[$db_field]) { http_response_code(404); exit; }

    $path = __DIR__ . '/' . $sub[$db_field];
    if (!file_exists($path)) { http_response_code(404); exit; }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'txt' => 'text/plain'];

    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
