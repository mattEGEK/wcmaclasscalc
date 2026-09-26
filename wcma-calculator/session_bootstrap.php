<?php
require_once __DIR__ . '/roles.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id'   => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'],
        'role' => $_SESSION['user_role'],
    ];
}

function is_admin(): bool {
    return user_has_role(current_user(), 'admin');
}

/** Inspectors and admins: classing, car tech and gear. */
function is_inspector(): bool {
    return user_has_role(current_user(), 'inspector');
}

/**
 * Gates a page to signed-in users with at least $min role. Signed out goes to the login page;
 * signed in but under-privileged gets a 403. Either way this does not return. On success it
 * returns the current user, same as current_user().
 */
function require_role(string $min): array {
    $user = current_user();
    switch (roleGateOutcome($user, $min)) {
        case 'login':
            header('Location: auth.php?action=login');
            exit;
        case 'forbidden':
            http_response_code(403);
            require_once __DIR__ . '/view_helpers.php';   // h(), and layout.php for renderPageStart()
            hubRenderForbidden();
            exit;
    }
    return $user;
}

function login_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
}
