<?php
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';

date_default_timezone_set('America/Denver');

$pdo = db_connect();
db_init($pdo);

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

function renderAuthPage(string $title, string $bodyHtml): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — WCMA Calculator</title>
<style>
  body { font-family: Arial, sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
  .box { background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.15); padding: 2rem; width: 100%; max-width: 380px; }
  h1 { margin: 0 0 1.5rem; font-size: 1.3rem; color: #1a5490; text-align: center; }
  label { display: block; font-size: .9rem; font-weight: bold; margin-bottom: .3rem; margin-top: .8rem; }
  input[type=text], input[type=email], input[type=password] { width: 100%; padding: .6rem .8rem; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; box-sizing: border-box; }
  button.primary { margin-top: 1.2rem; width: 100%; padding: .7rem; background: #1a5490; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
  button.primary:hover { background: #154070; }
  .google-btn { margin-top: .8rem; width: 100%; padding: .7rem; background: #fff; color: #444; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; cursor: pointer; text-align: center; text-decoration: none; display: block; }
  .google-btn:hover { background: #f5f5f5; }
  .error { background: #fde; border: 1px solid #e88; border-radius: 4px; padding: .6rem .8rem; margin-bottom: 1rem; font-size: .9rem; color: #900; }
  .success { background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; padding: .6rem .8rem; margin-bottom: 1rem; font-size: .9rem; color: #155724; }
  .links { margin-top: 1rem; font-size: .85rem; text-align: center; }
  .links a { color: #1a5490; }
</style>
</head>
<body>
<div class="box">
  <h1><?= h($title) ?></h1>
  <?= $bodyHtml ?>
</div>
</body>
</html><?php
}

$action = $_GET['action'] ?? 'login';

switch ($action) {
    case 'register':
        handleRegister($pdo);
        break;

    case 'login':
        handleLogin($pdo, $_SERVER['REMOTE_ADDR']);
        break;

    case 'logout':
        $_SESSION = [];
        session_destroy();
        header('Location: auth.php?action=login');
        exit;

    default:
        header('Location: auth.php?action=login');
        exit;
}

function handleRegister(PDO $pdo): void {
    if (current_user() !== null) {
        header('Location: car-classing.html');
        exit;
    }

    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        if ($name === '' || $email === '' || $password === '') {
            $error = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif (db_find_user_by_email($pdo, $email) !== null) {
            $error = 'An account with that email already exists.';
        } else {
            $userId = db_create_user($pdo, [
                'email' => $email,
                'name' => $name,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'google_id' => null,
            ]);
            $user = db_find_user_by_id($pdo, $userId);
            login_user($user);
            header('Location: car-classing.html');
            exit;
        }
    }

    $body = '';
    if ($error) $body .= '<div class="error">' . h($error) . '</div>';
    $body .= '<form method="post" action="auth.php?action=register">';
    $body .= '<label for="name">Name</label><input type="text" id="name" name="name" required value="' . h($_POST['name'] ?? '') . '">';
    $body .= '<label for="email">Email</label><input type="email" id="email" name="email" required value="' . h($_POST['email'] ?? '') . '">';
    $body .= '<label for="password">Password</label><input type="password" id="password" name="password" required autocomplete="new-password">';
    $body .= '<label for="password_confirm">Confirm Password</label><input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">';
    $body .= '<button type="submit" class="primary">Create Account</button>';
    $body .= '</form>';
    $body .= '<div class="links">Already have an account? <a href="auth.php?action=login">Sign in</a></div>';

    renderAuthPage('Create Account', $body);
}

function handleLogin(PDO $pdo, string $ip): void {
    if (current_user() !== null) {
        header('Location: car-classing.html');
        exit;
    }

    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $lockout = db_is_locked_out($pdo, $ip);
        if ($lockout['locked']) {
            $error = "Too many failed attempts. Try again in {$lockout['remaining']} minute(s).";
        } else {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $user = db_find_user_by_email($pdo, $email);

            if ($user && $user['password_hash'] && password_verify($password, $user['password_hash'])) {
                db_clear_login_attempts($pdo, $ip);
                login_user($user);
                header('Location: car-classing.html');
                exit;
            }

            db_record_failed_attempt($pdo, $ip);
            $lockout = db_is_locked_out($pdo, $ip);
            $error = $lockout['locked']
                ? "Too many failed attempts. Try again in {$lockout['remaining']} minute(s)."
                : 'Incorrect email or password.';
        }
    }

    $body = '';
    if ($error) $body .= '<div class="error">' . h($error) . '</div>';
    $body .= '<form method="post" action="auth.php?action=login">';
    $body .= '<label for="email">Email</label><input type="email" id="email" name="email" required autofocus value="' . h($_POST['email'] ?? '') . '">';
    $body .= '<label for="password">Password</label><input type="password" id="password" name="password" required autocomplete="current-password">';
    $body .= '<button type="submit" class="primary">Sign In</button>';
    $body .= '</form>';
    $body .= '<a href="auth.php?action=google-login" class="google-btn">Sign in with Google</a>';
    $body .= '<div class="links"><a href="auth.php?action=forgot-password">Forgot password?</a> &middot; <a href="auth.php?action=register">Create an account</a></div>';

    renderAuthPage('Sign In', $body);
}
