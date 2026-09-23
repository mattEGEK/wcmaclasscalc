<?php
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/view_helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$user = current_user();
if ($user === null) {
    echo json_encode(['loggedIn' => false, 'csrfToken' => generateCsrfToken()]);
} else {
    $pdo = db_connect();
    $row = db_find_user_by_id($pdo, (int)$user['id']);
    echo json_encode([
        'loggedIn'  => true,
        'name'      => $user['name'],
        'role'      => $user['role'],
        'email'     => $row['email'] ?? '',
        'csrfToken' => generateCsrfToken(),
    ]);
}
