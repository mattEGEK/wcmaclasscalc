<?php
// wcma-calculator/events-lib.php
//
// Competitors tag the events they are going to, per car. Tagging only drives their checklist and
// reminders: it does not register them with the host club. Callers must have loaded db.php.

require_once __DIR__ . '/ice-rules.php';

const EVENTS_NOT_REGISTERING = 'This doesn\'t register you. Register with the host club.';

/** @return array{ok: bool, error: ?string} */
function eventsTagCar(PDO $pdo, int $userId, int $eventId, int $carId): array {
    $car = db_get_user_car($pdo, $userId, $carId);
    if ($car === null || $car['archived_at'] !== null) return ['ok' => false, 'error' => 'Choose one of your cars.'];
    $event = db_get_event($pdo, $eventId);
    if ($event === null || (int)$event['active'] !== 1) return ['ok' => false, 'error' => 'That event is not open.'];
    db_tag_event($pdo, $userId, $eventId, $carId);
    return ['ok' => true, 'error' => null];
}

/** @return array{ok: bool, error: ?string} */
function eventsUntagCar(PDO $pdo, int $userId, int $eventId, int $carId): array {
    if (db_get_user_car($pdo, $userId, $carId) === null) return ['ok' => false, 'error' => 'Choose one of your cars.'];
    db_untag_event($pdo, $userId, $eventId, $carId);
    return ['ok' => true, 'error' => null];
}

/** "I'll do it at the track": planning only, it never accepts anything. @return array{ok: bool, error: ?string} */
function eventsSetAtTrack(PDO $pdo, int $userId, string $subjectType, int $subjectId, int $season,
                          string $discipline = DISCIPLINE_SUMMER, string $club = ''): array {
    if ($discipline === DISCIPLINE_SUMMER) {
        $club = '';
    } elseif ($discipline !== DISCIPLINE_ICE) {
        return ['ok' => false, 'error' => 'Unknown item.'];
    } elseif ($subjectType === 'car' && !in_array($club, iceClubCodes(), true)) {
        return ['ok' => false, 'error' => 'Unknown item.'];
    } elseif ($subjectType === 'driver') {
        $club = '';   // ice gear covers both clubs
    }
    if ($subjectType === 'car') {
        $owned = db_get_user_car($pdo, $userId, $subjectId) !== null;
    } elseif ($subjectType === 'driver') {
        $driver = db_get_driver($pdo, $subjectId);
        $owned = $driver !== null && (int)$driver['owner_user_id'] === $userId;
    } else {
        return ['ok' => false, 'error' => 'Unknown item.'];
    }
    if (!$owned) return ['ok' => false, 'error' => 'Unknown item.'];
    db_set_at_track($pdo, $subjectType, $subjectId, $season, $discipline, $club);
    return ['ok' => true, 'error' => null];
}
