<?php
// wcma-calculator/profile.php — User profile page for name and password
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/view_helpers.php';

date_default_timezone_set('America/Denver');

// ── Validators ────────────────────────────────────────────────────────────────

/**
 * Validate a user's name: trims and collapses whitespace, requires 1–100 characters.
 * Returns an array with 'ok', 'error', and 'name' keys.
 */
function profileValidateName(string $name): array {
    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    if (strlen($name) === 0 || strlen($name) > 100) {
        return ['ok' => false, 'error' => 'Name must be 1–100 characters.', 'name' => ''];
    }
    return ['ok' => true, 'error' => null, 'name' => $name];
}

/**
 * Validate a password change: at least 8 characters and must match confirmation.
 * Returns null on success, or an error message on failure.
 */
function profileValidatePassword(string $new, string $confirm): ?string {
    if (strlen($new) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if ($new !== $confirm) {
        return 'Passwords do not match.';
    }
    return null;
}

// ── Main logic ─────────────────────────────────────────────────────────────────

$pdo = db_connect();
db_init($pdo);

$user = require_role('user');

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'name') {
        $validation = profileValidateName($_POST['name'] ?? '');
        if ($validation['ok']) {
            db_set_user_name($pdo, (int)$user['id'], $validation['name']);
            $_SESSION['user_name'] = $validation['name'];
            setFlash('Name updated.', 'success');
        } else {
            setFlash($validation['error'], 'error');
        }
        header('Location: profile.php');
        exit;
    }

    if ($action === 'password') {
        $userRow = db_find_user_by_id($pdo, (int)$user['id']);
        $error = null;

        // If the account has a password, require verification of current password
        if (!empty($userRow['password_hash'])) {
            $currentPassword = $_POST['current_password'] ?? '';
            if (!password_verify($currentPassword, (string)$userRow['password_hash'])) {
                $error = 'Current password is incorrect.';
            }
        }

        // Validate new password
        if ($error === null) {
            $error = profileValidatePassword($_POST['new_password'] ?? '', $_POST['confirm_password'] ?? '');
        }

        if ($error === null) {
            $hash = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
            db_set_user_password($pdo, (int)$user['id'], $hash);
            setFlash('Password updated.', 'success');
        } else {
            setFlash($error, 'error');
        }
        header('Location: profile.php');
        exit;
    }
}

// ── Render page ───────────────────────────────────────────────────────────────

$userRow = db_find_user_by_id($pdo, (int)$user['id']);
$flash = getFlash();

renderPageStart('Profile', '', ['flash' => $flash]);
?>

<div class="hub-card">
    <h2>Your name</h2>
    <form method="post" action="profile.php">
        <input type="hidden" name="action" value="name">
        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
        <div class="form-field">
            <label for="profile-name">Name</label>
            <input type="text" id="profile-name" name="name" value="<?= h((string)$user['name']) ?>" required>
        </div>
        <button type="submit" class="hub-btn">Update name</button>
    </form>
</div>

<div class="hub-card">
    <h2>Email</h2>
    <p>
        <strong><?= h((string)$userRow['email']) ?></strong><br>
        <small>Contact the club to change your email.</small>
    </p>
</div>

<div class="hub-card">
    <h2>Password</h2>
    <?php if ($userRow['google_id'] !== null && empty($userRow['password_hash'])): ?>
        <p>You sign in with Google.</p>
    <?php else: ?>
        <form method="post" action="profile.php">
            <input type="hidden" name="action" value="password">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
            <?php if (!empty($userRow['password_hash'])): ?>
                <div class="form-field">
                    <label for="profile-current">Current password</label>
                    <input type="password" id="profile-current" name="current_password" required>
                </div>
            <?php endif; ?>
            <div class="form-field">
                <label for="profile-new">New password</label>
                <input type="password" id="profile-new" name="new_password" required>
            </div>
            <div class="form-field">
                <label for="profile-confirm">Confirm password</label>
                <input type="password" id="profile-confirm" name="confirm_password" required>
            </div>
            <button type="submit" class="hub-btn">Update password</button>
        </form>
    <?php endif; ?>
</div>

<div class="hub-card">
    <h2>Your driver profile</h2>
    <p>Your name is also your driver name on tech sheets and gear.<br>
    <a href="gear.php">Go to drivers</a></p>
</div>

<?php
renderPageEnd();
