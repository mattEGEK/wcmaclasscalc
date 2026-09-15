<?php
require __DIR__ . '/session_bootstrap.php';

header('Content-Type: application/json');

$user = current_user();
if ($user === null) {
    echo json_encode(['loggedIn' => false]);
} else {
    echo json_encode([
        'loggedIn' => true,
        'name'     => $user['name'],
        'role'     => $user['role'],
    ]);
}
