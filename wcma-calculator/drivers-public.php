<?php
// wcma-calculator/drivers-public.php — the public driver list. Signed out and indexable. Shows only live
// public pages (public consent, accepted by Media staff, not hidden): everyone, or the drivers planning to
// attend one event (the same roster rules as the Announcer).
require __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/view_helpers.php';
require_once __DIR__ . '/photo-requirements.php';
require_once __DIR__ . '/inspection-lib.php';
require __DIR__ . '/media-lib.php';
require __DIR__ . '/media-service.php';
require __DIR__ . '/media-page.php';

date_default_timezone_set('America/Denver');
$pdo = db_connect();
db_init($pdo);
$season = null;
$events = mediaPickerEvents(db_get_all_events($pdo), date('Y-m-d'));
$eventParam = is_scalar($_GET['event'] ?? null) ? (int)$_GET['event'] : 0;
$eventId = in_array($eventParam, array_map(fn(array $e): int => (int)$e['id'], $events), true) ? $eventParam : 0;

renderPageStart('Drivers', '', ['extraHead' => '<meta name="description" content="Drivers racing with the Western Canada Motorsport Association.">']);
echo renderPublicDirectoryHtml(['events' => $events, 'eventId' => $eventId, 'entries' => mediaPublicDirectory($pdo, $eventId, $season)]);
renderPageEnd();
