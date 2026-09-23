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
</head>
<body>
<div class="container auth-page">
  <div class="auth-box">
    <a href="car-classing.html" class="logo-home-link">
      <img src="https://www.wcma.ca/wp-content/uploads/WCMA-Logo.png" alt="WCMA Logo" class="wcma-logo-sm auth-logo">
    </a>
    <nav class="account-nav auth-nav"><?= renderCommonNav('auth') ?></nav>
    <h1><?= h($title) ?></h1>
    <?= $bodyHtml ?>
  </div>
</div>
<script src="js/auth.js" defer></script>
<script src="js/feedback.js" defer></script>
</body>
</html><?php
}

/**
 * Whitelist redirect targets to same-site pages we actually link users back
 * to (never an absolute/external URL) to avoid an open-redirect via
 * ?redirect=. Falls back to the calculator.
 */
function safeRedirectTarget(?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') return 'car-classing.html';
    if (preg_match('/^(car-classing\.html(\?draft=\d+)?|account\.php|admin\.php)$/', $raw)) {
        return $raw;
    }
    return 'car-classing.html';
}

/**
 * Renders a password input with a show/hide toggle button (wired up by
 * js/auth.js) instead of a bare <input type="password">.
 */
function passwordFieldHtml(string $id, string $name, string $autocomplete): string {
    return '<div class="password-field">'
        . '<input type="password" id="' . h($id) . '" name="' . h($name) . '" required autocomplete="' . h($autocomplete) . '">'
        . '<button type="button" class="password-toggle" data-target="' . h($id) . '" aria-label="Show password">' . eyeIconSvg() . '</button>'
        . '</div>';
}

function eyeIconSvg(): string {
    return <<<SVG
<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
SVG;
}

$action = $_GET['action'] ?? 'login';
$redirect = safeRedirectTarget($_GET['redirect'] ?? $_POST['redirect'] ?? '');

switch ($action) {
    case 'register':
        handleRegister($pdo, $redirect);
        break;

    case 'login':
        handleLogin($pdo, $_SERVER['REMOTE_ADDR'], $redirect);
        break;

    case 'logout':
        $_SESSION = [];
        session_destroy();
        header('Location: auth.php?action=login');
        exit;

    case 'google-login':
        handleGoogleLogin($redirect);
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

function handleRegister(PDO $pdo, string $redirect): void {
    if (current_user() !== null) {
        header('Location: ' . $redirect);
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
            header('Location: ' . $redirect);
            exit;
        }
    }

    $body = '';
    if ($error) $body .= '<div class="form-messages show error">' . h($error) . '</div>';
    $body .= '<form method="post" action="auth.php?action=register">';
    $body .= '<input type="hidden" name="redirect" value="' . h($redirect) . '">';
    $body .= '<label for="name">Name</label><input type="text" id="name" name="name" required value="' . h($_POST['name'] ?? '') . '">';
    $body .= '<label for="email">Email</label><input type="email" id="email" name="email" required value="' . h($_POST['email'] ?? $_GET['email'] ?? '') . '">';
    $body .= '<label for="password">Password</label>' . passwordFieldHtml('password', 'password', 'new-password');
    $body .= '<label for="password_confirm">Confirm Password</label>' . passwordFieldHtml('password_confirm', 'password_confirm', 'new-password');
    $body .= '<button type="submit" class="btn btn-primary btn-block">Create Account</button>';
    $body .= '</form>';
    $body .= '<div class="auth-links">Already have an account? <a href="auth.php?action=login&redirect=' . urlencode($redirect) . '">Sign in</a></div>';

    renderAuthPage('Create Account', $body);
}

function handleLogin(PDO $pdo, string $ip, string $redirect): void {
    if (current_user() !== null) {
        header('Location: ' . $redirect);
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
                if ((int)$user['active'] === 0) {
                    $error = 'This account has been deactivated. Contact an administrator.';
                } else {
                    db_clear_login_attempts($pdo, $ip);
                    login_user($user);
                    if (!empty($_POST['remember'])) {
                        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
                        setcookie(session_name(), session_id(), time() + 30 * 24 * 3600, '/', '', $secure, true);
                    }
                    header('Location: ' . $redirect);
                    exit;
                }
            } else {
                db_record_failed_attempt($pdo, $ip);
                $lockout = db_is_locked_out($pdo, $ip);
                $error = $lockout['locked']
                    ? "Too many failed attempts. Try again in {$lockout['remaining']} minute(s)."
                    : 'Incorrect email or password.';
            }
        }
    }

    $body = '';
    if ($flash) $body .= '<div class="form-messages show ' . h($flash['type']) . '">' . h($flash['message']) . '</div>';
    if ($error) $body .= '<div class="form-messages show error">' . h($error) . '</div>';
    $body .= '<form method="post" action="auth.php?action=login">';
    $body .= '<input type="hidden" name="redirect" value="' . h($redirect) . '">';
    $body .= '<label for="email">Email</label><input type="email" id="email" name="email" required autofocus value="' . h($_POST['email'] ?? '') . '">';
    $body .= '<label for="password">Password</label>' . passwordFieldHtml('password', 'password', 'current-password');
    $body .= '<label class="checkbox-label"><input type="checkbox" name="remember" value="1"> Remember me for 30 days</label>';
    $body .= '<button type="submit" class="btn btn-primary btn-block">Sign In</button>';
    $body .= '</form>';
    $body .= '<a href="auth.php?action=google-login&redirect=' . urlencode($redirect) . '" class="btn btn-google btn-block">' . googleIconSvg() . 'Sign in with Google</a>';
    $body .= '<div class="auth-links"><a href="auth.php?action=forgot-password">Forgot password?</a> &middot; <a href="auth.php?action=register&redirect=' . urlencode($redirect) . '">Create an account</a></div>';

    renderAuthPage('Sign In', $body);
}

function googleIconSvg(): string {
    return <<<SVG
<svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true"><path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.91c1.7-1.57 2.69-3.88 2.69-6.62z"/><path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.91-2.26c-.81.54-1.85.86-3.05.86-2.34 0-4.32-1.58-5.03-3.71H.96v2.33A9 9 0 0 0 9 18z"/><path fill="#FBBC05" d="M3.97 10.71a5.4 5.4 0 0 1 0-3.42V4.96H.96a9 9 0 0 0 0 8.08l3.01-2.33z"/><path fill="#EA4335" d="M9 3.58c1.32 0 2.51.46 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.96l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/></svg>
SVG;
}

function handleGoogleLogin(string $redirect): void {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_redirect'] = $redirect;

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

    if ((int)$user['active'] === 0) {
        setFlash('This account has been deactivated. Contact an administrator.', 'error');
        header('Location: auth.php?action=login');
        exit;
    }

    login_user($user);
    $callbackRedirect = safeRedirectTarget($_SESSION['oauth_redirect'] ?? '');
    unset($_SESSION['oauth_redirect']);
    header('Location: ' . $callbackRedirect);
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
    $body .= '<label for="password">New Password</label>' . passwordFieldHtml('password', 'password', 'new-password');
    $body .= '<label for="password_confirm">Confirm Password</label>' . passwordFieldHtml('password_confirm', 'password_confirm', 'new-password');
    $body .= '<button type="submit" class="btn btn-primary btn-block">Reset Password</button>';
    $body .= '</form>';

    renderAuthPage('Reset Password', $body);
}
