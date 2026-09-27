<?php
// wcma-calculator/index.php — the hub front door: landing page when signed out, Home when signed in.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/cars-lib.php';
require __DIR__ . '/gear-lib.php';
require __DIR__ . '/events-lib.php';
require __DIR__ . '/reminders-lib.php';
require __DIR__ . '/readiness-lib.php';
require __DIR__ . '/media-lib.php';
require __DIR__ . '/home-page.php';

date_default_timezone_set('America/Denver');
$pdo = db_connect();
db_init($pdo);
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_role('user');
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
    $uid = (int)$user['id'];
    switch ($_POST['action'] ?? '') {
        case 'tag':
            $r = eventsTagCar($pdo, $uid, (int)($_POST['event_id'] ?? 0), (int)($_POST['car_id'] ?? 0));
            $extra = $r['ok'] ? remindersRecordTagChoice($pdo, $uid, $_POST) : '';
            setFlash($r['ok'] ? 'Added to your events. ' . EVENTS_NOT_REGISTERING . $extra : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            break;
        case 'untag':
            $r = eventsUntagCar($pdo, $uid, (int)($_POST['event_id'] ?? 0), (int)($_POST['car_id'] ?? 0));
            setFlash($r['ok'] ? 'Removed from your events.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            break;
        case 'at-track':
            $r = eventsSetAtTrack($pdo, $uid, (string)($_POST['subject_type'] ?? ''), (int)($_POST['subject_id'] ?? 0), (int)($_POST['season'] ?? 0));
            setFlash($r['ok'] ? 'Noted: you\'ll get it checked at the track.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            break;
        case 'media-prompt-dismiss':
            db_dismiss_media_prompt($pdo, $uid);
            setFlash('OK. You can add one any time from the Drivers page.', 'success');
            break;
    }
    header('Location: index.php');
    exit;
}

if ($user === null) {
    renderPageStart('Welcome', 'home');
    echo renderLandingHtml(db_get_season_links($pdo, true));
    renderPageEnd();
    exit;
}

$uid = (int)$user['id'];
$in = loadReadinessInputs($pdo, $uid, date('Y-m-d'));
$season = gearSeasonNow();

$garage = [];
foreach ($in['cars'] as $carId => $car) {
    $status = techCarStatus(db_get_identity_sheets($pdo, $carId, $season));
    $garage[] = ['car' => $car, 'declaration' => $in['declarations'][$carId] ?? null,
                 'techLabel' => techCarStatusLabel($status, $season), 'techState' => $status['state']];
}
$drivers = [];
foreach ($in['drivers'] as $did => $d) {
    $g = $in['gear']["$did:$season"] ?? null;
    $st = $g !== null ? gearStatus($g) : ['state' => 'none', 'via' => null];
    $drivers[] = ['name' => (string)$d['name'], 'isSelf' => $did === $in['selfDriverId'],
                  'gearLabel' => gearStatusLabel($st, $season), 'gearState' => $st['state']];
}

$userRow = db_find_user_by_id($pdo, $uid);
$selfDriver = db_get_self_driver($pdo, $uid);
// Only offer the prompt when the driver has never made a media consent choice at all: once they
// withdraw, that withdrawal is a deliberate choice (a consent row with media off), not "not set up".
$mediaPrompt = $selfDriver !== null && (int)($userRow['media_prompt_dismissed'] ?? 0) === 0
    && db_get_latest_media_consent($pdo, (int)$selfDriver['id']) === null;

renderPageStart('Home', 'home', ['flash' => getFlash()]);
echo renderHomeHtml([
    'name' => (string)$user['name'], 'readiness' => buildReadiness($in), 'cars' => $in['cars'],
    'garage' => $garage, 'drivers' => $drivers, 'seasonLinks' => db_get_season_links($pdo, true),
    'csrf' => generateCsrfToken(), 'offerReminders' => remindersShouldOffer($userRow),
    'mediaPrompt' => $mediaPrompt,
]);
renderPageEnd();
