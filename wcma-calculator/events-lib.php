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
function eventsTagCar(PDO $pdo, int $userId, int $eventId, int $carId, ?array $formats = null, bool $suppsAck = false, ?array $driverIds = null): array {
    $car = db_get_user_car($pdo, $userId, $carId);
    if ($car === null || $car['archived_at'] !== null) return ['ok' => false, 'error' => 'Choose one of your cars.'];
    $event = db_get_event($pdo, $eventId);
    if ($event === null || (int)$event['active'] !== 1) return ['ok' => false, 'error' => 'That event is not open.'];
    if ($formats !== null) {
        $v = entryFormatsValidate($event, $formats);
        if (!$v['ok']) return ['ok' => false, 'error' => $v['error']];
    }
    if ($driverIds !== null) {
        $dv = eventsValidateDriverIds($pdo, $userId, $carId, $driverIds);
        if (!$dv['ok']) return ['ok' => false, 'error' => $dv['error']];
    }
    $added = db_tag_event($pdo, $userId, $eventId, $carId);
    if ($formats !== null) {
        eventsStoreFormats($pdo, $userId, $event, $carId, $v['formats'], $suppsAck);
    } elseif ($added) {
        db_set_entry_formats($pdo, $userId, $eventId, $carId, entryFormatsStore(eventsDefaultFormats($pdo, $car, $event)), null);
    }
    if ($driverIds !== null) {
        db_set_entry_drivers($pdo, (int)db_get_entry($pdo, $userId, $eventId, $carId)['id'], $dv['ids']);
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Change an entry the user already has (the event card): its formats and, when the "Who's driving?"
 * fieldset was posted ($driverIds not null), who's driving. Drivers are checked first, so a bad
 * list stores nothing.
 * @param mixed $formats the posted list
 * @return array{ok: bool, error: ?string}
 */
function eventsSetFormats(PDO $pdo, int $userId, int $eventId, int $carId, $formats, bool $suppsAck, ?array $driverIds = null): array {
    $entry = db_get_entry($pdo, $userId, $eventId, $carId);
    if ($entry === null) return ['ok' => false, 'error' => 'Add this car to the event first.'];
    $event = db_get_event($pdo, $eventId);
    if ($event === null) return ['ok' => false, 'error' => 'That event is not open.'];
    $v = entryFormatsValidate($event, $formats);
    if (!$v['ok']) return ['ok' => false, 'error' => $v['error']];
    if ($driverIds !== null) {
        $dv = eventsValidateDriverIds($pdo, $userId, $carId, $driverIds);
        if (!$dv['ok']) return ['ok' => false, 'error' => $dv['error']];
    }
    eventsStoreFormats($pdo, $userId, $event, $carId, $v['formats'], $suppsAck);
    if ($driverIds !== null) db_set_entry_drivers($pdo, (int)$entry['id'], $dv['ids']);
    return ['ok' => true, 'error' => null];
}

/**
 * Stores validated formats and the regulations tick as the box was left. The tick is stored whatever
 * formats are picked, so switching to Race clears nothing (TA/Drift spec §3; bug list 2026-10-02 #5);
 * only TA/Drift entries use it. It keeps the time it was first ticked while it stays ticked. An event
 * that can't run TA/Drift (ice, or no host club) shows no box and never stores one.
 */
function eventsStoreFormats(PDO $pdo, int $userId, array $event, int $carId, array $formats, bool $suppsAck): void {
    $eventId = (int)$event['id'];
    $hasBox = ($event['discipline'] ?? DISCIPLINE_SUMMER) !== DISCIPLINE_ICE && trim((string)($event['host_club'] ?? '')) !== '';
    $ack = null;
    if ($suppsAck && $hasBox) {
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

/**
 * The entry a submitted tech sheet makes (TA/Drift spec §3). It tags the car if it isn't tagged yet.
 * A new entry gets its default formats, unless they would need other tech than this sheet gives:
 * then a TA/Drift sheet enters Time Attack and a race sheet enters Race. An existing entry keeps its
 * formats. Submitting a TA/Drift sheet also counts as the supplementary-regulations tick for a
 * TA/Drift entry.
 */
function eventsTagForSheet(PDO $pdo, int $userId, array $event, array $car, string $tier): void {
    $eventId = (int)$event['id'];
    $carId = (int)$car['id'];
    $entry = db_get_entry($pdo, $userId, $eventId, $carId);
    if ($entry === null) {
        $formats = eventsDefaultFormats($pdo, $car, $event);
        if (entryTechTier($formats) !== $tier) $formats = $tier === TECH_TIER_TA_DRIFT ? ['ta'] : ['race'];
        eventsTagCar($pdo, $userId, $eventId, $carId, $formats, $tier === TECH_TIER_TA_DRIFT);
        return;
    }
    $formats = entryFormatsParse((string)$entry['formats']);
    if ($tier === TECH_TIER_TA_DRIFT && entryTechTier($formats) === TECH_TIER_TA_DRIFT) {
        eventsStoreFormats($pdo, $userId, $event, $carId, $formats, true);
    }
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

// ── Co-drivers per car and entry (2026-09-30 spec §2–§4) ─────────────────────

const ENTRY_DRIVERS_NONE = 'Tick at least one driver.';
const ENTRY_DRIVERS_OFF_LIST = "Choose drivers from this car's list.";

/** The posted "Who's driving?" ticks, or null when that fieldset wasn't on the form. */
function entryDriversFromPost(array $post): ?array {
    if (!isset($post['drivers_shown'])) return null;
    return is_array($post['drivers'] ?? null) ? array_values($post['drivers']) : [];
}

/** The owner first ("You"), then the car's co-drivers. @return array<int, array{id: int, name: string, isSelf: bool}> */
function eventsCarDriverChoices(PDO $pdo, int $userId, int $carId): array {
    return array_map(fn(array $d): array => ['id' => (int)$d['id'], 'name' => (string)$d['name'],
        'isSelf' => (int)($d['user_id'] ?? 0) === $userId], eventsSheetDriverRows($pdo, $userId, $carId));
}

/** Full drivers rows for the sheet pickers: the owner, then the car's co-drivers. */
function eventsSheetDriverRows(PDO $pdo, int $userId, int $carId): array {
    $self = db_get_self_driver($pdo, $userId);
    return array_merge($self !== null ? [$self] : [], db_get_car_drivers($pdo, $carId));
}

/** @param mixed $picked @return array{ok: bool, ids: int[], error: ?string} */
function eventsValidateDriverIds(PDO $pdo, int $userId, int $carId, $picked): array {
    if (!is_array($picked) || $picked === []) return ['ok' => false, 'ids' => [], 'error' => ENTRY_DRIVERS_NONE];
    $allowed = array_map(fn(array $c): int => $c['id'], eventsCarDriverChoices($pdo, $userId, $carId));
    $ids = [];
    foreach ($picked as $p) {
        if (!(is_int($p) || (is_string($p) && ctype_digit($p))) || !in_array((int)$p, $allowed, true)) {
            return ['ok' => false, 'ids' => [], 'error' => ENTRY_DRIVERS_OFF_LIST];
        }
        $ids[(int)$p] = (int)$p;
    }
    return ['ok' => true, 'ids' => array_values($ids), 'error' => null];
}

/** Car page "Add a co-driver": one of the user's drivers by id, or a new name ('new'). @return array{ok: bool, error: ?string} */
function eventsAddCoDriver(PDO $pdo, int $userId, int $carId, array $post): array {
    $choice = (string)($post['driver_id'] ?? '');
    $name = trim((string)preg_replace('/\s+/', ' ', (string)($post['new_name'] ?? '')));
    if ($choice === 'new' || $name !== '') {
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) return ['ok' => false, 'error' => 'Enter the co-driver\'s name.'];
        $driverId = db_find_or_create_driver($pdo, $userId, $name);
    } else {
        $driverId = ctype_digit($choice) ? (int)$choice : 0;
    }
    return $driverId !== null && $driverId > 0 && db_add_car_driver($pdo, $userId, $carId, $driverId)
        ? ['ok' => true, 'error' => null]
        : ['ok' => false, 'error' => 'Choose one of your co-drivers, or add a name.'];
}

/** @return array{ok: bool, error: ?string} */
function eventsRemoveCoDriver(PDO $pdo, int $userId, int $carId, int $driverId): array {
    return db_remove_car_driver($pdo, $userId, $carId, $driverId, date('Y-m-d'))
        ? ['ok' => true, 'error' => null]
        : ['ok' => false, 'error' => 'Choose one of your cars.'];
}

/**
 * What a new tech sheet starts with (spec §4): driver 1 is the owner when ticked, else the first
 * ticked co-driver; the rest are the added drivers. All empty when the car has no entry there.
 * @return array{driver1: ?int, others: int[], count: int}
 */
function eventsSheetPrefill(PDO $pdo, int $userId, int $carId, int $eventId): array {
    $entry = $eventId > 0 ? db_get_entry($pdo, $userId, $eventId, $carId) : null;
    $ids = $entry !== null ? db_get_entry_driver_ids($pdo, (int)$entry['id']) : [];
    if ($ids === []) return ['driver1' => null, 'others' => [], 'count' => 0];
    $selfId = (int)(db_get_self_driver($pdo, $userId)['id'] ?? 0);
    $first = in_array($selfId, $ids, true) ? $selfId : $ids[0];
    return ['driver1' => $first, 'others' => array_values(array_filter($ids, fn(int $i): bool => $i !== $first)), 'count' => count($ids)];
}

/**
 * After a tech sheet is saved (spec §4): everyone it names joins the car's co-driver list (the owner
 * excepted) and is ticked on that event's entry, if there is one. The sheet wins over the entry.
 */
function eventsSyncSheetDrivers(PDO $pdo, int $userId, int $sheetId): void {
    $sheet = db_get_tech_sheet($pdo, $sheetId);
    if ($sheet === null || (int)$sheet['user_id'] !== $userId || empty($sheet['car_id'])) return;
    $carId = (int)$sheet['car_id'];
    $ids = [(int)($sheet['driver_id'] ?? 0)];
    foreach (db_get_tech_sheet_drivers($pdo, $sheetId) as $row) $ids[] = (int)($row['driver_id'] ?? 0);
    $entry = db_get_entry($pdo, $userId, (int)$sheet['event_id'], $carId);
    foreach (array_unique(array_filter($ids)) as $did) {
        db_add_car_driver($pdo, $userId, $carId, $did);   // false (and skipped) for the owner
        if ($entry !== null) db_add_entry_driver($pdo, (int)$entry['id'], $did);
    }
}
