<?php
session_start();
date_default_timezone_set('America/Denver');

require __DIR__ . '/db.php';
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Configuration ─────────────────────────────────────────────────────────────
// Generate with: php -r "echo password_hash('YOUR_PIN', PASSWORD_BCRYPT) . PHP_EOL;"
define('ADMIN_PIN_HASH', '$2y$10$PLACEHOLDER_REPLACE_ON_SERVER');
define('SMTP_HOST',      'smtp.ionos.com');
define('SMTP_PORT',      587);
define('SMTP_USER',      'noreply@yourdomain.com');     // ← match car-classing.php
define('SMTP_PASS',      'YOUR_SMTP_PASSWORD');          // ← match car-classing.php
define('FROM_EMAIL',     'noreply@yourdomain.com');      // ← match car-classing.php
define('FROM_NAME',      'WCMA Calculator');
define('TECH_EMAIL',     'matt.sinfield@gmail.com');
define('TECH_NAME',      'Matt Sinfield');

$pdo = db_connect();
db_init($pdo);

// ── Auth helpers ──────────────────────────────────────────────────────────────
function requireAuth(): void {
    if (!isset($_SESSION['admin_authenticated'])) {
        header('Location: admin.php?action=login');
        exit;
    }
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

// ── Router ────────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? 'list';
$ip     = $_SERVER['REMOTE_ADDR'];

switch ($action) {
    case 'login':
        handleLogin($pdo, $ip);
        break;

    case 'logout':
        $_SESSION = [];
        session_destroy();
        header('Location: admin.php?action=login');
        exit;

    case 'list':
        requireAuth();
        handleList($pdo);
        break;

    case 'view':
        requireAuth();
        handleView($pdo, (int)($_GET['id'] ?? 0));
        break;

    case 'file':
        requireAuth();
        handleFile($pdo, (int)($_GET['id'] ?? 0), $_GET['field'] ?? '');
        break;

    case 'resend':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleResend($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'delete':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleDelete($pdo, (int)($_POST['id'] ?? 0));
        break;

    default:
        requireAuth();
        handleList($pdo);
}

// ── Login ─────────────────────────────────────────────────────────────────────
function handleLogin(PDO $pdo, string $ip): void {
    if (isset($_SESSION['admin_authenticated'])) {
        header('Location: admin.php');
        exit;
    }

    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $lockout = db_is_locked_out($pdo, $ip);
        if ($lockout['locked']) {
            $error = "Too many failed attempts. Try again in {$lockout['remaining']} minute(s).";
        } else {
            $pin = $_POST['pin'] ?? '';
            if (password_verify($pin, ADMIN_PIN_HASH)) {
                db_clear_login_attempts($pdo, $ip);
                session_regenerate_id(true);
                $_SESSION['admin_authenticated'] = true;
                header('Location: admin.php');
                exit;
            } else {
                db_record_failed_attempt($pdo, $ip);
                $lockout = db_is_locked_out($pdo, $ip);
                $error = $lockout['locked']
                    ? "Too many failed attempts. Try again in {$lockout['remaining']} minute(s)."
                    : 'Incorrect PIN.';
            }
        }
    }

    renderLoginPage($error);
}

function renderLoginPage(string $error = ''): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Login — WCMA Calculator</title>
<style>
  body { font-family: Arial, sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
  .login-box { background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.15); padding: 2rem; width: 100%; max-width: 340px; }
  h1 { margin: 0 0 1.5rem; font-size: 1.3rem; color: #1a5490; text-align: center; }
  label { display: block; font-size: .9rem; font-weight: bold; margin-bottom: .3rem; }
  input[type=password] { width: 100%; padding: .6rem .8rem; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; box-sizing: border-box; }
  button { margin-top: 1rem; width: 100%; padding: .7rem; background: #1a5490; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
  button:hover { background: #154070; }
  .error { background: #fde; border: 1px solid #e88; border-radius: 4px; padding: .6rem .8rem; margin-bottom: 1rem; font-size: .9rem; color: #900; }
</style>
</head>
<body>
<div class="login-box">
  <h1>WCMA Admin</h1>
  <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
  <form method="post" action="admin.php?action=login">
    <label for="pin">PIN</label>
    <input type="password" id="pin" name="pin" autofocus autocomplete="current-password">
    <button type="submit">Sign in</button>
  </form>
</div>
</body>
</html><?php
}

// ── Placeholder stubs (replaced in Tasks 5–7) ─────────────────────────────────
function handleList(PDO $pdo): void {
    $sort = $_GET['sort'] ?? 'submitted_at';
    $dir  = $_GET['dir']  ?? 'desc';
    $submissions = db_get_submissions($pdo, $sort, $dir);
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderListPage($submissions, $sort, $dir, $csrf, $flash);
}

function renderListPage(array $submissions, string $sort, string $dir, string $csrf, ?array $flash): void {
    $flip = $dir === 'asc' ? 'desc' : 'asc';

    function sortLink(string $col, string $label, string $currentSort, string $currentDir, string $flip): string {
        $arrow = ($currentSort === $col) ? ($currentDir === 'asc' ? ' ▲' : ' ▼') : '';
        $nextDir = ($currentSort === $col) ? $flip : 'asc';
        $url = h('admin.php?sort=' . $col . '&dir=' . $nextDir);
        return "<a href=\"{$url}\" style=\"color:inherit;text-decoration:none;\">" . h($label) . $arrow . "</a>";
    }
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Submissions — WCMA Admin</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; margin: 0; background: #f0f2f5; }
  header { background: #1a5490; color: #fff; padding: .8rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
  header h1 { margin: 0; font-size: 1.2rem; }
  header a { color: #cde; font-size: .9rem; }
  main { padding: 1.5rem; }
  .flash { padding: .7rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: .9rem; }
  .flash.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
  .flash.error   { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
  th { background: #1a5490; color: #fff; padding: .7rem 1rem; text-align: left; font-size: .85rem; white-space: nowrap; }
  td { padding: .65rem 1rem; border-bottom: 1px solid #eee; font-size: .9rem; vertical-align: middle; }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: #f7f9fc; }
  .badge-ok   { color: #155724; font-weight: bold; }
  .badge-fail { color: #721c24; font-weight: bold; }
  .actions a  { color: #1a5490; margin-right: .6rem; font-size: .85rem; }
  .btn-delete { background: none; border: none; color: #c00; cursor: pointer; font-size: .85rem; padding: 0; }
  .btn-delete:hover { text-decoration: underline; }
  .empty { text-align: center; color: #888; padding: 2rem; }
</style>
</head>
<body>
<header>
  <h1>WCMA Submissions</h1>
  <a href="admin.php?action=logout">Logout</a>
</header>
<main>
  <?php if ($flash): ?>
  <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <table>
    <thead>
      <tr>
        <th><?= sortLink('submitted_at', 'Submitted', $sort, $dir, $flip) ?></th>
        <th><?= sortLink('name', 'Name', $sort, $dir, $flip) ?></th>
        <th>Vehicle</th>
        <th><?= sortLink('competition_weight', 'Weight', $sort, $dir, $flip) ?></th>
        <th><?= sortLink('declared_hp', 'HP', $sort, $dir, $flip) ?></th>
        <th><?= sortLink('calculated_class', 'Class', $sort, $dir, $flip) ?></th>
        <th>Email</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($submissions)): ?>
      <tr><td colspan="8" class="empty">No submissions yet.</td></tr>
    <?php else: foreach ($submissions as $s): ?>
      <tr>
        <td><?= h(date('M j, Y H:i', strtotime($s['submitted_at']))) ?></td>
        <td><?= h($s['name']) ?></td>
        <td><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></td>
        <td><?= h((string)$s['competition_weight']) ?></td>
        <td><?= h((string)$s['declared_hp']) ?></td>
        <td><strong><?= h($s['calculated_class'] ?? '—') ?></strong></td>
        <td class="<?= $s['email_sent'] ? 'badge-ok' : 'badge-fail' ?>">
          <?= $s['email_sent'] ? '✓' : '⚠ Failed' ?>
        </td>
        <td class="actions">
          <a href="admin.php?action=view&id=<?= (int)$s['id'] ?>">View</a>
          <form method="post" action="admin.php?action=delete" style="display:inline"
                onsubmit="return confirm('Permanently delete this submission and its files?')">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="btn-delete">Delete</button>
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
function handleView(PDO $pdo, int $id): void {
    $sub = db_get_submission($pdo, $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: admin.php');
        exit;
    }
    $csrf  = generateCsrfToken();
    $flash = getFlash();
    renderDetailPage($sub, $csrf, $flash);
}

function renderDetailPage(array $s, string $csrf, ?array $flash): void {
    $brake_list = [];
    $brake_raw = json_decode($s['brake_suspension'] ?? '[]', true);
    if (is_array($brake_raw)) $brake_list = $brake_raw;

    function modRow(string $label, ?string $display, float $value): string {
        if (!$display && $value == 0) return '';
        $sign = $value >= 0 ? '+' : '';
        $disp = $display ? h($display) : '—';
        return "<tr><td>{$label}</td><td style='text-align:right;font-family:monospace'>{$sign}" . number_format($value, 2) . "</td><td style='color:#666;font-size:.85rem'>{$disp}</td></tr>";
    }
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Submission #<?= (int)$s['id'] ?> — WCMA Admin</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; margin: 0; background: #f0f2f5; }
  header { background: #1a5490; color: #fff; padding: .8rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
  header h1 { margin: 0; font-size: 1.1rem; }
  header a { color: #cde; font-size: .9rem; }
  main { padding: 1.5rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
  @media (max-width: 700px) { main { grid-template-columns: 1fr; } }
  .card { background: #fff; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,.1); padding: 1.2rem; }
  .card h2 { margin: 0 0 1rem; font-size: 1rem; color: #1a5490; border-bottom: 2px solid #1a5490; padding-bottom: .4rem; }
  table.data td { padding: .35rem .5rem; font-size: .9rem; vertical-align: top; }
  table.data td:first-child { font-weight: bold; width: 160px; color: #444; }
  .calc-table { width: 100%; border-collapse: collapse; font-family: monospace; font-size: .95rem; }
  .calc-table td { padding: .3rem .4rem; }
  .calc-table tr.total td { border-top: 2px solid #333; font-weight: bold; font-size: 1.05rem; padding-top: .5rem; }
  .class-badge { font-size: 1.4rem; font-weight: bold; color: #1a5490; }
  .flash { padding: .7rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: .9rem; }
  .flash.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
  .flash.error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
  .actions { margin-bottom: 1rem; }
  .btn { display: inline-block; padding: .5rem 1.1rem; border-radius: 4px; font-size: .9rem; cursor: pointer; border: none; text-decoration: none; }
  .btn-primary { background: #1a5490; color: #fff; }
  .btn-secondary { background: #6c757d; color: #fff; }
  .btn-primary:hover { background: #154070; }
  .file-thumb { max-width: 100%; max-height: 200px; border-radius: 4px; margin-top: .5rem; display: block; }
  .file-link { display: inline-block; margin-top: .4rem; color: #1a5490; }
</style>
</head>
<body>
<header>
  <h1>Submission #<?= (int)$s['id'] ?> — <?= h($s['name']) ?></h1>
  <a href="admin.php">← Back to list</a>
</header>
<main>
  <?php if ($flash): ?>
  <div class="flash <?= h($flash['type']) ?>" style="grid-column:1/-1"><?= h($flash['message']) ?></div>
  <?php endif; ?>

  <!-- LEFT: Calculation + details -->
  <div>
    <div class="card" style="margin-bottom:1.5rem">
      <h2>Calculation Breakdown</h2>
      <table class="calc-table">
        <tr><td>Base Ratio</td>
            <td style="text-align:right"><?= number_format((float)$s['base_ratio'], 2) ?></td><td></td></tr>
        <tr><td>Weight Factor</td>
            <td style="text-align:right"><?= ($s['weight_factor'] >= 0 ? '+' : '') . number_format((float)$s['weight_factor'], 2) ?></td><td></td></tr>
        <?= modRow('Chassis', $s['chassis_display'], (float)$s['chassis_value']) ?>
        <?= modRow('Body Mods', $s['body_mods_display'], (float)$s['body_mods_value']) ?>
        <?= modRow('Transmission', $s['transmission_display'], (float)$s['transmission_value']) ?>
        <?= modRow('Drivetrain', $s['drivetrain_display'], (float)$s['drivetrain_value']) ?>
        <?= modRow('Tires', $s['tires_display'], (float)$s['tires_value']) ?>
        <?php if ((float)$s['brake_suspension_value'] != 0): ?>
        <tr><td>Brake &amp; Susp.</td>
            <td style="text-align:right;font-family:monospace"><?= ($s['brake_suspension_value'] >= 0 ? '+' : '') . number_format((float)$s['brake_suspension_value'], 2) ?></td>
            <td style="color:#666;font-size:.85rem"><?= h(implode(', ', $brake_list)) ?></td></tr>
        <?php endif; ?>
        <tr class="total">
          <td>Modified Ratio</td>
          <td style="text-align:right"><?= number_format((float)$s['modified_ratio'], 2) ?></td>
          <td class="class-badge"><?= h($s['calculated_class'] ?? '—') ?></td>
        </tr>
      </table>
    </div>

    <div class="card">
      <h2>Contact &amp; Vehicle</h2>
      <table class="data">
        <tr><td>Name</td><td><?= h($s['name']) ?></td></tr>
        <tr><td>Email</td><td><?= h($s['email']) ?></td></tr>
        <tr><td>Vehicle</td><td><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></td></tr>
        <?php if ($s['comments']): ?><tr><td>Comments</td><td><?= nl2br(h($s['comments'])) ?></td></tr><?php endif; ?>
        <tr><td>Weight</td><td><?= h((string)$s['competition_weight']) ?> lbs</td></tr>
        <tr><td>Declared HP</td><td><?= h((string)$s['declared_hp']) ?></td></tr>
        <?php if ($s['dyno_hp']): ?><tr><td>Dyno HP</td><td><?= h((string)$s['dyno_hp']) ?></td></tr><?php endif; ?>
        <tr><td>Submitted</td><td><?= h(date('F j, Y \a\t g:i A', strtotime($s['submitted_at']))) ?></td></tr>
        <tr><td>Email Sent</td><td><?= $s['email_sent'] ? '✓ Yes' : '⚠ Failed' ?></td></tr>
      </table>
    </div>
  </div>

  <!-- RIGHT: Files + actions -->
  <div>
    <div class="card" style="margin-bottom:1.5rem">
      <h2>Actions</h2>
      <div class="actions">
        <form method="post" action="admin.php?action=resend" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <button type="submit" class="btn btn-primary">Re-email Tech Sheet</button>
        </form>
      </div>
    </div>

    <div class="card">
      <h2>Uploaded Files</h2>
      <?php
      $files = [
          'car_image'   => ['label' => 'Car Image',   'field' => 'car_image',   'path' => $s['car_image_path']],
          'dyno_chart'  => ['label' => 'Dyno Chart',  'field' => 'dyno_chart',  'path' => $s['dyno_chart_path']],
          'dyno_table'  => ['label' => 'Dyno Table',  'field' => 'dyno_table',  'path' => $s['dyno_table_path']],
      ];
      $any = false;
      foreach ($files as $f):
          if (!$f['path']) continue;
          $any = true;
          $ext = strtolower(pathinfo($f['path'], PATHINFO_EXTENSION));
          $is_image = in_array($ext, ['jpg', 'jpeg', 'png']);
          $url = h('admin.php?action=file&id=' . (int)$s['id'] . '&field=' . $f['field']);
      ?>
      <p style="font-weight:bold;margin:.8rem 0 .2rem"><?= h($f['label']) ?></p>
      <?php if ($is_image): ?>
        <img src="<?= $url ?>" class="file-thumb" alt="<?= h($f['label']) ?>">
      <?php else: ?>
        <a href="<?= $url ?>" target="_blank" class="file-link">Open <?= h(basename($f['path'])) ?></a>
      <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$any): ?><p style="color:#888;font-size:.9rem">No files uploaded.</p><?php endif; ?>
    </div>
  </div>
</main>
</body>
</html><?php
}

function handleFile(PDO $pdo, int $id, string $field): void {
    $field_map = [
        'dyno_chart' => 'dyno_chart_path',
        'dyno_table' => 'dyno_table_path',
        'car_image'  => 'car_image_path',
    ];

    if (!isset($field_map[$field])) { http_response_code(404); exit; }

    $sub = db_get_submission($pdo, $id);
    $db_field = $field_map[$field];

    if (!$sub || !$sub[$db_field]) { http_response_code(404); exit; }

    $path = __DIR__ . '/' . $sub[$db_field];
    if (!file_exists($path)) { http_response_code(404); exit; }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt'  => 'text/plain',
    ];

    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
function handleResend(PDO $pdo, int $id): void { header('Location: admin.php'); }
function handleDelete(PDO $pdo, int $id): void {
    $sub = db_get_submission($pdo, $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: admin.php');
        exit;
    }

    // Delete uploaded files
    $upload_dir = __DIR__ . '/uploads/' . $id;
    if (is_dir($upload_dir)) {
        foreach (glob($upload_dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($upload_dir);
    }

    db_delete_submission($pdo, $id);
    setFlash('Submission deleted.', 'success');
    header('Location: admin.php');
    exit;
}
