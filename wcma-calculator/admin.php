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
  <form method="post">
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
    echo '<p style="font-family:sans-serif;padding:2rem;">List view coming in Task 5.</p>';
    echo '<p style="font-family:sans-serif;padding:0 2rem;"><a href="admin.php?action=logout">Logout</a></p>';
}
function handleView(PDO $pdo, int $id): void { echo 'View coming in Task 6.'; }
function handleFile(PDO $pdo, int $id, string $field): void { http_response_code(404); echo 'Not yet implemented.'; }
function handleResend(PDO $pdo, int $id): void { header('Location: admin.php'); }
function handleDelete(PDO $pdo, int $id): void { header('Location: admin.php'); }
