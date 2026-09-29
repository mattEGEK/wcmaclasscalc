<?php
// wcma-calculator/events-lib.php
//
// Competitors tag the events they are going to, per car. Tagging only drives their checklist and
// reminders: it does not register them with the host club. Callers must have loaded db.php.

require_once __DIR__ . '/ice-rules.php';
require_once __DIR__ . '/ta-drift-lib.php';

const EVENTS_NOT_REGISTERING = 'This doesn\'t register you. Register with the host club.';

/**
 * Tags the car for the event (TA/Drift spec §3 Entry). With $formats, they are validated first and
 * stored, with the regulations tick. Without, an entry that already exists is left alone (so the
 * auto-tag on sheet submit never resets a choice) and a new one gets eventsDefaultFormats().
 *
 * @param ?string[] $formats
 * @return array{ok: bool, error: ?string}
 */
function eventsTagCar(PDO $pdo, int $userId, int $eventId, int $carId, ?array $formats = null, bool $suppsAck = false): array {
    $car = db_get_user_car($pdo, $userId, $carId);
    if ($car === null || $car['archived_at'] !== null) return ['ok' => false, 'error' => 'Choose one of your cars.'];
    $event = db_get_event($pdo, $eventId);
    if ($event === null || (int)$event['active'] !== 1) return ['ok' => false, 'error' => 'That event is not open.'];
    if ($formats !== null) {
        $v = entryFormatsValidate($event, $formats);
        if (!$v['ok']) return ['ok' => false, 'error' => $v['error']];
    }
    $added = db_tag_event($pdo, $userId, $eventId, $carId);
    if ($formats !== null) {
        eventsStoreFormats($pdo, $userId, $eventId, $carId, $v['formats'], $suppsAck);
    } elseif ($added) {
        db_set_entry_formats($pdo, $userId, $eventId, $carId, entryFormatsStore(eventsDefaultFormats($pdo, $car, $event)), null);
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Change the formats of an entry the user already has (the event card).
 * @param mixed $formats the posted list
 * @return array{ok: bool, error: ?string}
 */
function eventsSetFormats(PDO $pdo, int $userId, int $eventId, int $carId, $formats, bool $suppsAck): array {
    if (db_get_entry($pdo, $userId, $eventId, $carId) === null) return ['ok' => false, 'error' => 'Add this car to the event first.'];
    $event = db_get_event($pdo, $eventId);
    if ($event === null) return ['ok' => false, 'error' => 'That event is not open.'];
    $v = entryFormatsValidate($event, $formats);
    if (!$v['ok']) return ['ok' => false, 'error' => $v['error']];
    eventsStoreFormats($pdo, $userId, $eventId, $carId, $v['formats'], $suppsAck);
    return ['ok' => true, 'error' => null];
}

/**
 * Stores validated formats. The regulations tick counts only for a TA/Drift entry; it keeps the time
 * it was first ticked while it stays ticked, and is cleared otherwise.
 */
function eventsStoreFormats(PDO $pdo, int $userId, int $eventId, int $carId, array $formats, bool $suppsAck): void {
    $ack = null;
    if ($suppsAck && entryTechTier($formats) === TECH_TIER_TA_DRIFT) {
        $ack = (db_get_entry($pdo, $userId, $eventId, $carId)['supps_ack_at'] ?? null) ?: date('Y-m-d H:i:s');
    }
    db_set_entry_formats($pdo, $userId, $eventId, $carId, entryFormatsStore($formats), $ack);
}

/**
 * The formats a new entry starts with: the car's last summer entry, else Time Attack for a
 * TA/Drift-only car, else Race. Ice events and summer events with no host club are always Race.
 * @return string[]
 */
function eventsDefaultFormats(PDO $pdo, array $car, array $event): array {
    if (($event['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE || trim((string)($event['host_club'] ?? '')) === '') {
        return ['race'];
    }
    $last = db_get_car_last_summer_formats($pdo, (int)$car['id'], (int)$event['id']);
    if ($last !== null) return entryFormatsParse($last);
    return ($car['disciplines'] ?? null) === 'ta_drift' ? ['ta'] : ['race'];
}

/** @return array{ok: bool, error: ?string} */
function eventsUntagCar(PDO $pdo, int $userId, int $eventId, int $carId): array {
    if (db_get_user_car($pdo, $userId, $carId) === null) return ['ok' => false, 'error' => 'Choose one of your cars.'];
    db_untag_event($pdo, $userId, $eventId, $carId);
    return ['ok' => true, 'error' => null];
}

/** "I'll do it at the track": planning only, it never accepts anything. $discipline is summer, ice, or TECH_TIER_TA_DRIFT (car tech at one host club). @return array{ok: bool, error: ?string} */
function eventsSetAtTrack(PDO $pdo, int $userId, string $subjectType, int $subjectId, int $season,
                          string $discipline = DISCIPLINE_SUMMER, string $club = ''): array {
    $unknown = ['ok' => false, 'error' => 'Unknown item.'];
    $stored = $discipline;
    if ($discipline === DISCIPLINE_SUMMER) {
        $club = '';
    } elseif ($discipline === TECH_TIER_TA_DRIFT) {
        // TA/Drift car tech is per host club: stored as a summer choice that carries the club.
        if ($subjectType !== 'car' || !preg_match('/^[A-Z0-9-]{2,12}$/', $club)) return $unknown;
        $stored = DISCIPLINE_SUMMER;
    } elseif ($discipline !== DISCIPLINE_ICE) {
        return $unknown;
    } elseif ($subjectType === 'car' && !in_array($club, iceClubCodes(), true)) {
        return $unknown;
    } elseif ($subjectType === 'driver') {
        $club = '';   // ice gear covers both clubs
    }
    if ($subjectType === 'car') {
        $owned = db_get_user_car($pdo, $userId, $subjectId) !== null;
    } elseif ($subjectType === 'driver') {
        $driver = db_get_driver($pdo, $subjectId);
        $owned = $driver !== null && (int)$driver['owner_user_id'] === $userId;
    } else {
        return $unknown;
    }
    if (!$owned) return $unknown;
    db_set_at_track($pdo, $subjectType, $subjectId, $season, $stored, $club);
    return ['ok' => true, 'error' => null];
}
