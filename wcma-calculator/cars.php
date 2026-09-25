<?php
// wcma-calculator/cars.php — JSON: the signed-in competitor's active cars (for the calculator's car picker).
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/cars-lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$user = current_user();
if ($user === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not signed in.']);
    exit;
}

$pdo = db_connect();
db_init($pdo);

if (($_GET['action'] ?? 'list') === 'declaration') {
    echo json_encode(['success' => true, 'form_data' => db_get_car_declaration_form($pdo, (int)$user['id'], (int)($_GET['car_id'] ?? 0))]);
    exit;
}

$current = db_get_user_current_declarations($pdo, (int)$user['id']);
$cars = array_map(
    fn(array $c): array => carsPublicShape($c, $current[(int)$c['id']] ?? null),
    db_get_user_cars($pdo, (int)$user['id'])
);
echo json_encode(['success' => true, 'cars' => $cars]);
