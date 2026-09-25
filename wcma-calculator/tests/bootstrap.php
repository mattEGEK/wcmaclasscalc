<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../feedback-lib.php';

if (!function_exists('current_user')) {
    function current_user(): ?array { return $GLOBALS['TEST_CURRENT_USER'] ?? null; }
}
require_once __DIR__ . '/../roles.php';

function make_temp_pdo(): PDO {
    $path = sys_get_temp_dir() . '/wcma_test_' . uniqid() . '.db';
    if (!defined('DB_PATH')) {
        define('DB_PATH', $path);
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    db_init($pdo);
    return $pdo;
}

/** The user's car with this (normalised) number, created if missing. */
function test_make_car(PDO $pdo, int $userId, string $number = '42'): int {
    foreach (db_get_user_cars($pdo, $userId, true) as $car) {
        if ($car['car_number_norm'] === techCarNumberNorm($number)) return (int)$car['id'];
    }
    return db_create_car($pdo, $userId, ['car_number' => $number, 'year' => '2020', 'make' => 'Mazda', 'model' => 'MX-5']);
}

/** A complete db_insert_submission() parameter array for the user's car $number. */
function test_declaration_data(PDO $pdo, int $userId, string $number = '42', array $overrides = []): array {
    return array_merge([
        ':submitted_at' => date('Y-m-d H:i:s'), ':name' => 'Test Driver', ':email' => 't@example.com',
        ':year' => '2020', ':make' => 'Mazda', ':model' => 'MX-5', ':comments' => null,
        ':competition_weight' => 2200, ':declared_hp' => 150, ':dyno_hp' => null,
        ':chassis_display' => null, ':body_mods_display' => null, ':transmission_display' => null,
        ':drivetrain_display' => null, ':tires_display' => null, ':brake_suspension' => '[]',
        ':chassis_value' => 0, ':body_mods_value' => 0, ':transmission_value' => 0,
        ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
        ':weight_factor' => 0, ':modification_factor' => 0, ':base_ratio' => 14.67, ':modified_ratio' => 14.67,
        ':calculated_class' => 'IT1', ':user_id' => $userId, ':car_id' => test_make_car($pdo, $userId, $number),
    ], $overrides);
}
