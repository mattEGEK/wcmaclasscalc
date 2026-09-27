<?php
// wcma-calculator/hub-db-tools.php
//
// The app is not live, so the hub model ships with a reset instead of a data migration.
// hubSeed() fills an empty database with realistic test data for local runs and e2e harnesses.
// Callers must have loaded db.php and gear-lib.php (or let this file load them).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/gear-lib.php';
require_once __DIR__ . '/tech-sheet-data.php';

function hubResetDatabase(string $dbPath, string $uploadsDir): void {
    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) {
        if (is_file($f)) unlink($f);
    }
    if (!is_dir($uploadsDir)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    $keep = realpath($uploadsDir . '/.htaccess');
    foreach ($items as $item) {
        if ($item->getRealPath() === $keep) continue;
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
}

/** @return array<string, int> */
function hubSeed(PDO $pdo, string $password): array {
    // Every page that records a submitted_at (car-classing.php, tech-sheets.php, ...) sets this first;
    // hubSeed()'s date() calls must use the same timezone or its rows sort ahead of/behind real ones.
    // Set here (not at file scope) so including this file doesn't change the timezone for every
    // includer (reset-hub-db.php, PHPUnit).
    date_default_timezone_set('America/Denver');
    if ((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0) {
        throw new RuntimeException('The database already has users. Reset it first.');
    }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $user = fn(string $email, string $name): int => db_create_user($pdo, ['email' => $email, 'name' => $name, 'password_hash' => $hash, 'google_id' => null]);

    $admin = $user(BOOTSTRAP_ADMIN_EMAIL, 'Site Admin');           // bootstrap email is made admin on creation
    $inspector = $user('inspector@example.com', 'Ivy Inspector');
    db_set_user_role($pdo, $inspector, 'inspector');
    $jordan = $user('jordan@example.com', 'Jordan Lee');
    $media = $user('media@example.com', 'Mia Media');
    db_set_user_media($pdo, $media, true);

    $year = (int)date('Y');
    $fall = db_create_event($pdo, 'Fall Sprint', date('Y-m-d', strtotime('+17 days')), 'Castrol Raceway');
    db_create_event($pdo, 'Season Finale', date('Y-m-d', strtotime('+31 days')), 'Castrol Raceway');
    db_create_event($pdo, 'NASCC Ice Race #1', date('Y-m-d', strtotime('+45 days')), 'Lake Wabamun', 'ice', 'NASCC');
    db_create_event($pdo, 'WSCC Fire on Ice #1', date('Y-m-d', strtotime('+52 days')), 'Lake Shirley', 'ice', 'WSCC');

    $s2000 = db_create_car($pdo, $jordan, ['car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'colour' => 'Silver', 'engine_cc' => '1997']);
    $miata = db_create_car($pdo, $jordan, ['car_number' => '17', 'year' => '1999', 'make' => 'Mazda', 'model' => 'Miata', 'colour' => 'Red', 'engine_cc' => '1839']);
    $declare = function (int $carId, string $make, string $model, string $carYear, int $weight, int $hp, string $class) use ($pdo, $jordan): int {
        return db_insert_submission($pdo, [
            ':submitted_at' => date('Y-m-d H:i:s'), ':name' => 'Jordan Lee', ':email' => 'jordan@example.com',
            ':year' => $carYear, ':make' => $make, ':model' => $model, ':comments' => null,
            ':competition_weight' => $weight, ':declared_hp' => $hp, ':dyno_hp' => null,
            ':chassis_display' => null, ':body_mods_display' => null, ':transmission_display' => null,
            ':drivetrain_display' => null, ':tires_display' => null, ':brake_suspension' => '[]',
            ':chassis_value' => 0, ':body_mods_value' => 0, ':transmission_value' => 0,
            ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
            ':weight_factor' => 0, ':modification_factor' => 0, ':base_ratio' => round($weight / $hp, 2),
            ':modified_ratio' => round($weight / $hp, 2), ':calculated_class' => $class,
            ':user_id' => $jordan, ':car_id' => $carId,
        ]);
    };
    $declare($s2000, 'Honda', 'S2000', '2004', 2860, 240, 'GT3');
    $miataDecl = $declare($miata, 'Mazda', 'Miata', '1999', 2400, 140, 'IT1');

    db_tag_event($pdo, $jordan, $fall, $s2000);   // on the roster with no sheet yet

    gearCreate($pdo, $jordan, 'Jordan Lee', 'WCMA-0412', $year);   // the self profile
    db_create_driver($pdo, $jordan, 'Sam Patel');

    $checklist = [];
    foreach (TECH_CHECKLIST_SECTIONS as $section) {
        foreach ($section['items'] as $key => $label) {
            $checklist[$key] = ['status' => 'ok'];
        }
    }
    $equipment = [];
    foreach (TECH_DRIVER_EQUIPMENT_ITEMS as $key => $def) {
        $equipment[$key] = [
            'competitor_confirmed' => true,
            'value' => $def['has_rating'] ? 'FIA 8860-2018' : null,
        ];
    }
    db_insert_tech_sheet($pdo, [
        'submission_id' => $miataDecl, 'user_id' => $jordan, 'event_id' => $fall, 'sheet_type' => 'standard',
        'entrant_name' => 'Jordan Lee', 'driver_name' => 'Jordan Lee', 'car_make' => 'Mazda', 'car_model' => 'Miata',
        'car_colour' => 'Red', 'car_number' => '17', 'class' => 'IT1', 'engine_cc' => '1839', 'engine_hp' => '140',
        'car_weight' => 2400, 'checklist_json' => json_encode($checklist), 'driver1_equipment_json' => json_encode($equipment),
        'log_book_turned_in' => 1,
    ]);

    db_create_season_link($pdo, $year . ' Annual Waiver / Hardcard', 'https://www.motorsportreg.com/orgs/western-canada-motorsport-associati', 1);
    db_create_season_link($pdo, $year . ' Race Licences', 'https://www.motorsportreg.com/orgs/western-canada-motorsport-associati', 2);
    db_create_season_link($pdo, 'Car Classing & Number Reservation', 'https://www.motorsportreg.com/orgs/western-canada-motorsport-associati', 3);

    // Media profiles: Jordan fully consented and waiting for public review; Sam a minor, club use only,
    // consent confirmed by Jordan on Sam's behalf. The inspector's own profile has no consent.
    $jordanDriver = (int)db_get_self_driver($pdo, $jordan)['id'];
    db_save_media_profile($pdo, $jordanDriver, ['blurb' => 'Jordan has raced the S2000 at Castrol since 2015 and still brakes too late into turn 1.',
        'pronunciation' => null, 'hometown' => 'Red Deer, AB', 'racing_since' => 2015, 'social_handle' => 'jordanlee42',
        'photo_path' => null, 'public_status' => 'pending_review']);
    db_replace_sponsors($pdo, $jordanDriver, [['name' => 'Acme Tires', 'url' => 'https://example.com'], ['name' => "Bob's Garage", 'url' => null]]);
    db_insert_media_consent($pdo, ['driver_id' => $jordanDriver, 'consent_media' => 1, 'consent_public' => 1, 'is_minor' => 0,
        'guardian_name' => null, 'given_by_user_id' => $jordan, 'on_behalf' => 0, 'wording_version' => 1]);
    $samDriver = (int)db_find_driver($pdo, $jordan, 'Sam Patel')['id'];
    db_save_media_profile($pdo, $samDriver, ['blurb' => 'Sam is 16 and in a first season moving up from karts.', 'pronunciation' => null,
        'hometown' => 'Olds, AB', 'racing_since' => (int)date('Y'), 'social_handle' => null, 'photo_path' => null, 'public_status' => 'none']);
    db_insert_media_consent($pdo, ['driver_id' => $samDriver, 'consent_media' => 1, 'consent_public' => 0, 'is_minor' => 1,
        'guardian_name' => 'Priya Patel', 'given_by_user_id' => $jordan, 'on_behalf' => 1, 'wording_version' => 1]);

    return ['users' => 4, 'cars' => 2, 'events' => 4, 'tech_sheets' => 1, 'season_links' => 3, 'event_plans' => 1, 'media_profiles' => 2];
}
