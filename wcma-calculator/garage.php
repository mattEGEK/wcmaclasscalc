<?php
// wcma-calculator/garage.php — the competitor's Garage (spec §4): the car list, Add a car, the car
// page, and class declarations. Views live in garage-page.php; view models in garage-lib.php.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/cars-lib.php';
require __DIR__ . '/events-lib.php';
require __DIR__ . '/gear-lib.php';
require __DIR__ . '/gear-chips.php';
require __DIR__ . '/garage-lib.php';
require __DIR__ . '/home-page.php';
require __DIR__ . '/garage-page.php';

date_default_timezone_set('America/Denver');
$pdo = db_connect();
db_init($pdo);
$user = require_role('user');
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
    handleGaragePost($pdo, $uid, (string)($_POST['action'] ?? ''));
    exit;
}

if (($_GET['action'] ?? '') === 'add') {
    garageRenderAdd($pdo, [], null);
    exit;
}
if (isset($_GET['car'])) {
    garageShowCar($pdo, $uid, (int)$_GET['car']);
    exit;
}
garageShowList($pdo, $uid);

function garageShowList(PDO $pdo, int $uid): void {
    $all = db_get_user_cars($pdo, $uid, true);
    $active = array_values(array_filter($all, fn(array $c): bool => $c['archived_at'] === null));
    $archived = array_values(array_filter($all, fn(array $c): bool => $c['archived_at'] !== null));
    $sheets = db_get_user_tech_sheets($pdo, $uid);
    $tagged = [];
    foreach (db_get_user_event_plans($pdo, $uid) as $p) $tagged[(int)$p['car_id']][] = (int)$p['event_id'];
    $events = db_get_active_events($pdo);

    $cards = [];
    foreach ($active as $car) {
        $cid = (int)$car['id'];
        $carSheets = array_values(array_filter($sheets, fn(array $s): bool => (int)$s['car_id'] === $cid));
        $cards[] = garageCard($car, db_get_car_declarations($pdo, $cid), $carSheets, $tagged[$cid] ?? [], $events, gearSeasonNow(), date('Y-m-d'));
    }

    // js/ui-controller.js fetches account.php (which redirects here) and scrapes this meta tag for
    // the calculator's draft actions, so it must be on the list page for every signed-in user.
    $csrf = generateCsrfToken();
    renderPageStart('Garage', 'garage', ['flash' => getFlash(), 'extraHead' => '<meta name="csrf-token" content="' . h($csrf) . '">']);
    echo renderGarageListHtml(['cards' => $cards, 'archived' => $archived, 'csrf' => $csrf]);
    renderPageEnd(['scripts' => '<script src="js/confirm-modal.js"></script>']);
}

function garageRenderAdd(PDO $pdo, array $values, ?string $error): void {
    renderPageStart('Add a car', 'garage', ['subnav' => '<a href="garage.php">&larr; Back to Garage</a>']);
    echo renderAddCarHtml([
        'csrf' => generateCsrfToken(), 'values' => $values, 'error' => $error,
        'msrLink' => seasonLinkMatching(db_get_season_links($pdo, true), 'Classing'),
    ]);
    renderPageEnd();
}

function garageShowCar(PDO $pdo, int $uid, int $carId, ?array $detailsForm = null): void {
    $car = db_get_user_car($pdo, $uid, $carId);
    if ($car === null) { setFlash('Car not found.', 'error'); header('Location: garage.php'); exit; }

    $season = gearSeasonNow();
    $sheets = array_values(array_filter(db_get_user_tech_sheets($pdo, $uid), fn(array $s): bool => (int)$s['car_id'] === $carId));
    $tagged = [];
    foreach (db_get_user_event_plans($pdo, $uid) as $p) {
        if ((int)$p['car_id'] === $carId) $tagged[] = (int)$p['event_id'];
    }
    $eventNames = [];
    foreach (db_get_all_events($pdo) as $e) $eventNames[(int)$e['id']] = (string)$e['name'];
    $events = garageCarEvents($sheets, $tagged, db_get_active_events($pdo), $eventNames, date('Y-m-d'));

    $ownerGear = db_get_user_gear_records($pdo, $uid);
    $driversBySheet = db_get_drivers_for_sheets($pdo, array_map(fn(array $s): int => (int)$s['id'], $sheets));
    foreach ($events['tagged'] as $i => $row) {
        $events['tagged'][$i]['gearLinks'] = $row['sheet'] !== null
            ? gearLinksForSheet($row['sheet'], $driversBySheet[(int)$row['sheet']['id']] ?? [], $ownerGear)
            : [];
    }

    $seasonSheets = array_values(array_filter($sheets, fn(array $s): bool => (int)$s['season'] === $season));
    $status = techCarStatus($seasonSheets);
    $declarations = db_get_car_declarations($pdo, $carId);

    renderPageStart(carDisplayName($car), 'garage', ['flash' => getFlash(), 'subnav' => '<a href="garage.php">&larr; Back to Garage</a>']);
    echo renderGarageCarHtml([
        'car' => $car, 'class' => garageClassLine($declarations), 'declarations' => $declarations,
        'season' => $season, 'techState' => $status['state'], 'techLabel' => techCarStatusLabel($status, $season),
        'techAction' => garageTechPhotosAction($seasonSheets, $status),
        'events' => $events, 'csrf' => generateCsrfToken(), 'detailsForm' => $detailsForm,
    ]);
    renderPageEnd(['scripts' => '<script src="js/confirm-modal.js"></script>']);
}

function handleGaragePost(PDO $pdo, int $uid, string $action): void {
    $carId = (int)($_POST['car_id'] ?? 0);
    switch ($action) {
        case 'add':
            $v = carsValidateDetails($_POST);
            if (!$v['ok']) { garageRenderAdd($pdo, $v['data'], $v['error']); return; }
            $id = db_create_car($pdo, $uid, $v['data']);
            setFlash('Car added. Next, declare its class.', 'success');
            header('Location: garage.php?car=' . $id);
            return;
        case 'archive':
            $ok = db_archive_car($pdo, $uid, $carId);
            setFlash($ok ? 'Car archived. Its history is kept.' : 'Car not found.', $ok ? 'success' : 'error');
            header('Location: garage.php');
            return;
        case 'restore':
            $ok = db_restore_car($pdo, $uid, $carId);
            setFlash($ok ? 'Car restored.' : 'Car not found.', $ok ? 'success' : 'error');
            header('Location: garage.php' . ($ok ? '?car=' . $carId : ''));
            return;
        case 'update-car':
            if (db_get_user_car($pdo, $uid, $carId) === null) break;
            $v = carsValidateDetails($_POST);
            if (!$v['ok']) { garageShowCar($pdo, $uid, $carId, ['values' => $v['data'], 'error' => $v['error']]); return; }
            db_update_car($pdo, $carId, $v['data']);
            setFlash('Car details saved.', 'success');
            header('Location: garage.php?car=' . $carId);
            return;
        case 'tag':
            $r = eventsTagCar($pdo, $uid, (int)($_POST['event_id'] ?? 0), $carId);
            setFlash($r['ok'] ? 'Added to your events. ' . EVENTS_NOT_REGISTERING : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            header('Location: garage.php?car=' . $carId);
            return;
        case 'untag':
            $r = eventsUntagCar($pdo, $uid, (int)($_POST['event_id'] ?? 0), $carId);
            setFlash($r['ok'] ? 'Removed from your events.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            header('Location: garage.php?car=' . $carId);
            return;
    }
    setFlash('Car not found.', 'error');
    header('Location: garage.php');
}
