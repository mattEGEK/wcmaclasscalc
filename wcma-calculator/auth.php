<?php
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';

date_default_timezone_set('America/Denver');

$pdo = db_connect();
db_init($pdo);

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function renderAuthPage(string $title, string $bodyHtml): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — WCMA Calculator</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
<style>
  body.auth-body { display: flex; }
  .container.auth-container { display: flex; align-items: center; justify-content: center; min-height: calc(100vh - 2rem); }
</style>
</head>
<body class="auth-body">
<div class="container auth-container">
  <div class="auth-box">
    <h1><?= h($title) ?></h1>
    <?= $bodyHtml ?>
  </div>
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

    case 'google-login':
        handleGoogleLogin();
        break;

    case 'google-callback':
        handleGoogleCallback($pdo);
        break;

    case 'forgot-password':
        handleForgotPassword($pdo);
        break;

    case 'reset-password':
        handleResetPassword($pdo);
        break;

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
            $linked = db_link_submissions_by_email($pdo, $userId, $email);
            $user = db_find_user_by_id($pdo, $userId);
            login_user($user);
            if ($linked > 0) {
                $plural = $linked === 1 ? 'submission' : 'submissions';
                setFlash("Welcome — we found {$linked} past {$plural} under this email and added them to My Cars.", 'success');
            }
            header('Location: car-classing.html');
            exit;
        }
    }

    $body = '';
    if ($error) $body .= '<div class="form-messages show error">' . h($error) . '</div>';
    $body .= '<form method="post" action="auth.php?action=register">';
    $body .= '<label for="name">Name</label><input type="text" id="name" name="name" required value="' . h($_POST['name'] ?? '') . '">';
    $body .= '<label for="email">Email</label><input type="email" id="email" name="email" required value="' . h($_POST['email'] ?? $_GET['email'] ?? '') . '">';
    $body .= '<label for="password">Password</label><input type="password" id="password" name="password" required autocomplete="new-password">';
    $body .= '<label for="password_confirm">Confirm Password</label><input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">';
    $body .= '<button type="submit" class="btn btn-primary btn-block">Create Account</button>';
    $body .= '</form>';
    $body .= '<div class="auth-links">Already have an account? <a href="auth.php?action=login">Sign in</a></div>';

    renderAuthPage('Create Account', $body);
}

function handleLogin(PDO $pdo, string $ip): void {
    if (current_user() !== null) {
        header('Location: car-classing.html');
        exit;
    }

    $error = '';
    $flash = getFlash();

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
    if ($flash) $body .= '<div class="form-messages show ' . h($flash['type']) . '">' . h($flash['message']) . '</div>';
    if ($error) $body .= '<div class="form-messages show error">' . h($error) . '</div>';
    $body .= '<form method="post" action="auth.php?action=login">';
    $body .= '<label for="email">Email</label><input type="email" id="email" name="email" required autofocus value="' . h($_POST['email'] ?? '') . '">';
    $body .= '<label for="password">Password</label><input type="password" id="password" name="password" required autocomplete="current-password">';
    $body .= '<button type="submit" class="btn btn-primary btn-block">Sign In</button>';
    $body .= '</form>';
    $body .= '<a href="auth.php?action=google-login" class="btn btn-google btn-block">Sign in with Google</a>';
    $body .= '<div class="auth-links"><a href="auth.php?action=forgot-password">Forgot password?</a> &middot; <a href="auth.php?action=register">Create an account</a></div>';

    renderAuthPage('Sign In', $body);
}

function handleGoogleLogin(): void {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    $params = http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account',
    ]);

    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

function googleCurlPost(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?? [];
}

function googleCurlGet(string $url, string $bearerToken): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $bearerToken]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?? [];
}

function handleGoogleCallback(PDO $pdo): void {
    $state = $_GET['state'] ?? '';
    if (!isset($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
        setFlash('Google sign-in failed (invalid state). Please try again.', 'error');
        header('Location: auth.php?action=login');
        exit;
    }
    unset($_SESSION['oauth_state']);

    $code = $_GET['code'] ?? '';
    if ($code === '') {
        setFlash('Google sign-in was cancelled.', 'error');
        header('Location: auth.php?action=login');
        exit;
    }

    $token = googleCurlPost('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]);

    if (!isset($token['access_token'])) {
        setFlash('Google sign-in failed while exchanging the code. Please try again.', 'error');
        header('Location: auth.php?action=login');
        exit;
    }

    $profile = googleCurlGet('https://www.googleapis.com/oauth2/v3/userinfo', $token['access_token']);
    $googleId = $profile['sub'] ?? null;
    $email = $profile['email'] ?? null;
    $name = $profile['name'] ?? $email;

    if (!$googleId || !$email) {
        setFlash('Google did not return the expected profile data.', 'error');
        header('Location: auth.php?action=login');
        exit;
    }

    $user = db_find_user_by_google_id($pdo, $googleId);

    if (!$user) {
        $existingByEmail = db_find_user_by_email($pdo, $email);
        if ($existingByEmail) {
            if (($profile['email_verified'] ?? false) !== true) {
                setFlash('Google sign-in failed: your Google email address is not verified.', 'error');
                header('Location: auth.php?action=login');
                exit;
            }
            db_link_google_id($pdo, $existingByEmail['id'], $googleId);
            $user = db_find_user_by_id($pdo, $existingByEmail['id']);
        } else {
            $userId = db_create_user($pdo, [
                'email' => $email,
                'name' => $name,
                'password_hash' => null,
                'google_id' => $googleId,
            ]);
            $user = db_find_user_by_id($pdo, $userId);
        }
    }

    login_user($user);
    header('Location: car-classing.html');
    exit;
}

function buildAuthMailer(): PHPMailer {
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

function handleForgotPassword(PDO $pdo): void {
    $sent = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim($_POST['email'] ?? '');
        $user = db_find_user_by_email($pdo, $email);

        if ($user && $user['password_hash']) {
            db_delete_password_resets_for_user($pdo, $user['id']);
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + 3600);
            db_create_password_reset($pdo, $user['id'], $tokenHash, $expiresAt);

            $resetUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/auth.php?action=reset-password&token=' . $token;

            try {
                $mail = buildAuthMailer();
                $mail->addAddress($user['email'], $user['name']);
                $mail->Subject = 'Reset your WCMA Calculator password';
                $mail->isHTML(true);
                $mail->Body = '<p>Click the link below to reset your password. This link expires in 1 hour.</p><p><a href="' . htmlspecialchars($resetUrl) . '">' . htmlspecialchars($resetUrl) . '</a></p>';
                $mail->AltBody = "Reset your password: $resetUrl (expires in 1 hour)";
                $mail->send();
            } catch (Exception $e) {
                error_log('Password reset email error: ' . $e->getMessage());
            }
        }

        $sent = true; // Always show the same message, whether or not the email matched
    }

    $body = '';
    if ($sent) {
        $body .= '<div class="form-messages show success">If that email is registered, a reset link has been sent.</div>';
    }
    $body .= '<form method="post" action="auth.php?action=forgot-password">';
    $body .= '<label for="email">Email</label><input type="email" id="email" name="email" required>';
    $body .= '<button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>';
    $body .= '</form>';
    $body .= '<div class="auth-links"><a href="auth.php?action=login">Back to sign in</a></div>';

    renderAuthPage('Forgot Password', $body);
}

function handleResetPassword(PDO $pdo): void {
    $token = $_GET['token'] ?? $_POST['token'] ?? '';
    $tokenHash = hash('sha256', $token);
    $reset = $token !== '' ? db_get_password_reset($pdo, $tokenHash) : null;

    if (!$reset || strtotime($reset['expires_at']) < time()) {
        renderAuthPage('Reset Password', '<div class="form-messages show error">This reset link is invalid or has expired.</div><div class="auth-links"><a href="auth.php?action=forgot-password">Request a new link</a></div>');
        return;
    }

    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id")
                ->execute([':hash' => password_hash($password, PASSWORD_BCRYPT), ':id' => $reset['user_id']]);
            db_delete_password_reset($pdo, $tokenHash);

            $user = db_find_user_by_id($pdo, $reset['user_id']);
            login_user($user);
            header('Location: car-classing.html');
            exit;
        }
    }

    $body = '';
    if ($error) $body .= '<div class="form-messages show error">' . h($error) . '</div>';
    $body .= '<form method="post" action="auth.php?action=reset-password">';
    $body .= '<input type="hidden" name="token" value="' . h($token) . '">';
    $body .= '<label for="password">New Password</label><input type="password" id="password" name="password" required autocomplete="new-password">';
    $body .= '<label for="password_confirm">Confirm Password</label><input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">';
    $body .= '<button type="submit" class="btn btn-primary btn-block">Reset Password</button>';
    $body .= '</form>';

    renderAuthPage('Reset Password', $body);
}
