<?php
// wcma-calculator/gear-lib.php
//
// Driver gear records: status, create/renew, and the photo pre-tech workflow. Session-free
// (callers inject the reviewer id) so it is unit-testable. Callers must have loaded db.php.
require_once __DIR__ . '/photo-requirements.php';
require_once __DIR__ . '/inspection-lib.php';
require_once __DIR__ . '/pretech-lib.php';   // pretechPlural(), pretechCapText()
require_once __DIR__ . '/tech-status.php';   // techCarStatusBadgeClass()
require_once __DIR__ . '/ice-rules.php';
require_once __DIR__ . '/ta-drift-lib.php';

function gearNameNorm(string $name): string {
    return db_driver_name_norm($name);
}

function gearSeasonNow(string $discipline = DISCIPLINE_SUMMER): int {
    return $discipline === DISCIPLINE_ICE ? iceSeasonFromDate(date('Y-m-d')) : (int)date('Y');
}

/**
 * Derived status of one gear record.
 * accepted > needs_changes > pending_review > photos_draft > none.
 *
 * @return array{state: string, via: ?string}
 */
function gearStatus(array $gear): array {
    if (($gear['status'] ?? '') === 'accepted') {
        return ['state' => 'accepted', 'via' => $gear['accepted_via'] ?? 'in_person'];
    }
    switch ($gear['photo_status'] ?? null) {
        case 'needs_changes': return ['state' => 'needs_changes', 'via' => null];
        case 'submitted':
        case 'accepted':      return ['state' => 'pending_review', 'via' => null];
        case 'draft':         return ['state' => 'photos_draft', 'via' => null];
    }
    return ['state' => 'none', 'via' => null];
}

function gearStatusLabel(array $status, int $season, string $discipline = DISCIPLINE_SUMMER): string {
    switch ($status['state']) {
        case 'accepted':       return ($status['via'] === 'photos' ? 'Gear pre-teched ' : 'Gear teched ') . ($discipline === DISCIPLINE_ICE ? iceSeasonLabel((int)$season) : (string)$season);
        case 'needs_changes':  return 'Photos need changes';
        case 'pending_review': return 'Photos pending review';
        case 'photos_draft':   return 'Photos in progress';
        default:               return 'Needs gear check at the track';
    }
}

function gearStatusBadgeClass(string $state): string {
    return techCarStatusBadgeClass($state);
}

/**
 * The record mapped to the shape inspectionCanAccess() expects of a tech sheet: owner in user_id,
 * 'teched' once accepted, and the photo_status that drives the owner write lock.
 *
 * @return array{user_id: int, status: string, photo_status: ?string}
 */
function gearAccessShape(array $gear): array {
    return [
        'user_id' => (int)$gear['owner_user_id'],
        'status' => ($gear['status'] ?? 'open') === 'accepted' ? 'teched' : 'submitted',
        'photo_status' => $gear['photo_status'] ?? null,
    ];
}

/** Number of photos needed for a complete set: required + applicable conditional (recommended never counts). */
function gearRequiredTotal(array $requirements, array $applicable): int {
    $total = 0;
    foreach ($requirements as $key => $req) {
        if ($req['tier'] === 'required' || ($req['tier'] === 'conditional' && in_array($key, $applicable, true))) $total++;
    }
    return $total;
}

/** $filter: 'all' | 'needs_gear' (not accepted) | 'pending_review' | 'accepted'. Unknown values mean 'all'. */
function gearRosterFilter(array $records, string $filter): array {
    if (!in_array($filter, ['needs_gear', 'pending_review', 'accepted'], true)) return $records;
    return array_values(array_filter($records, function (array $g) use ($filter): bool {
        $state = gearStatus($g)['state'];
        if ($filter === 'pending_review') return $state === 'pending_review';
        return $filter === 'accepted' ? $state === 'accepted' : $state !== 'accepted';
    }));
}

/** Creates a gear record for the owner. @return array{ok: bool, error: ?string, id: ?int} */
function gearCreate(PDO $pdo, int $ownerId, string $name, string $licence, int $season, string $discipline = DISCIPLINE_SUMMER): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'id' => null];

    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    $licence = trim($licence);
    if ($name === '') return $fail('Enter the driver\'s name.');
    if (mb_strlen($name, 'UTF-8') > 100) return $fail('That name is too long (100 characters at most).');
    if (mb_strlen($licence, 'UTF-8') > 40) return $fail('That licence number is too long (40 characters at most).');

    $norm = gearNameNorm($name);
    if (db_find_gear_record($pdo, $ownerId, $norm, $season, $discipline) !== null) {
        return $fail('You already have a gear record for ' . $name . ' this season.');
    }
    try {
        $driverId = db_find_or_create_driver($pdo, $ownerId, $name);
        if ($licence !== '') db_update_driver_licence($pdo, (int)$driverId, $licence);
        $id = db_insert_gear_record($pdo, (int)$driverId, $season, $discipline);
    } catch (PDOException $e) {
        return $fail('You already have a gear record for ' . $name . ' this season.');   // lost a race with a duplicate request
    }
    return ['ok' => true, 'error' => null, 'id' => $id];
}

/** Photos of a gear record, which are present, which conditional ones apply, and what is still missing. */
function gearSnapshot(PDO $pdo, int $id): array {
    $photos = db_get_inspection_photos($pdo, 'gear_record', $id);
    $requirements = photoRequirementsFor(db_get_gear_record($pdo, $id) ?? [], 'gear');
    $present = [];
    $applicable = [];
    foreach ($photos as $key => $row) {
        if ($row['file_path'] !== '') $present[] = $key;
        $req = $requirements[$key] ?? null;
        if ($req !== null && $req['tier'] === 'conditional' && (int)$row['applies'] === 1) $applicable[] = $key;
    }
    return [
        'photos' => $photos,
        'present' => $present,
        'applicable' => $applicable,
        'missing' => photoSetMissingFrom($requirements, $present, $applicable),
    ];
}

/** Owner submits a complete photo set for review. @return array{ok: bool, error: ?string} */
function gearSubmit(PDO $pdo, int $id): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg];

    $gear = db_get_gear_record($pdo, $id);
    if ($gear === null) return $fail('Gear record not found.');
    if ($gear['status'] === 'accepted') return $fail('This driver\'s gear has already been teched for the season.');

    $photoStatus = $gear['photo_status'] ?? null;
    if ($photoStatus === 'submitted') return $fail('These photos have already been submitted for review.');
    if (!in_array($photoStatus, ['draft', 'needs_changes'], true)) return $fail('Add your photos before submitting.');

    $snapshot = gearSnapshot($pdo, $id);
    $missing = count($snapshot['missing']);
    if ($missing > 0) {
        return $fail($missing . ' required ' . pretechPlural($missing, 'photo is', 'photos are') . ' still missing.');
    }
    foreach ($snapshot['photos'] as $row) {
        if ($row['file_path'] !== '' && $row['review_status'] === 'retake') {
            return $fail('Please retake the photos the inspector flagged before submitting again.');
        }
    }

    if (!db_transition_gear_photo_status($pdo, $id, ['draft', 'needs_changes'], 'submitted')) {
        return $fail('These photos could not be submitted. Please reload and try again.');
    }
    return ['ok' => true, 'error' => null];
}

/** Inspector accepts a submitted photo set remotely. Ice records also record the confirmed gear level. @return array{ok: bool, error: ?string} */
function gearAcceptByPhotos(PDO $pdo, int $id, int $reviewerUserId, ?string $level = null): array {
    $gear = db_get_gear_record($pdo, $id);
    $isIce = $gear !== null && ($gear['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE;
    if ($isIce && !isset(ICE_GEAR_LEVEL_LABELS[(string)$level])) {
        return ['ok' => false, 'error' => 'Choose the gear level: street-safe or caged.'];
    }
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if (!db_accept_gear_by_photos($pdo, $id, $reviewerUserId)) {
            if ($own) $pdo->rollBack();
            return ['ok' => false, 'error' => 'These photos are not awaiting review.'];
        }
        db_set_all_photos_review_status($pdo, 'gear_record', $id, 'accepted');
        if ($isIce) db_set_gear_level($pdo, $id, $level);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'error' => null];
}

/** The level the helmet photo's standard suggests (ice gear), or null. */
function gearSuggestedLevel(array $snapshot): ?string {
    $row = $snapshot['photos']['ice_helmet_label'] ?? null;
    if ($row === null || ($row['file_path'] ?? '') === '') return null;
    $typed = json_decode((string)($row['typed_value'] ?? ''), true);
    return is_array($typed) && is_string($typed['standard'] ?? null) ? iceGearLevelForHelmet($typed['standard']) : null;
}

/**
 * Inspector sends individual photos back for a retake. Every flagged photo needs a note.
 *
 * @param array<string,string> $notes requirement key => note
 * @return array{ok: bool, error: ?string, retakes: array<string,string>}
 */
function gearSendBack(PDO $pdo, int $id, array $notes): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'retakes' => []];

    $gear = db_get_gear_record($pdo, $id);
    if ($gear === null) return $fail('Gear record not found.');
    if ($gear['status'] === 'accepted' || ($gear['photo_status'] ?? null) !== 'submitted') {
        return $fail('These photos are not awaiting review.');
    }

    $photos = db_get_inspection_photos($pdo, 'gear_record', $id);
    $retakes = [];
    foreach ($notes as $key => $note) {
        if (!is_string($note) || !isset($photos[$key]) || $photos[$key]['file_path'] === '') continue;
        $note = pretechCapText(trim($note), 500);
        if ($note === '') return $fail('Add a note for every photo you send back.');
        $retakes[$key] = $note;
    }
    if (!$retakes) return $fail('Choose at least one photo to retake and say what is wrong.');

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if (!db_transition_gear_photo_status($pdo, $id, ['submitted'], 'needs_changes')) {
            if ($own) $pdo->rollBack();
            return $fail('These photos are not awaiting review.');
        }
        foreach ($retakes as $key => $note) {
            db_set_inspection_photo_review($pdo, (int)$photos[$key]['id'], 'retake', $note);
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'error' => null, 'retakes' => $retakes];
}

/** In-person acceptance. Ice records also record the gear level the inspector confirmed. @return array{ok: bool, error: ?string} */
function gearAcceptInPerson(PDO $pdo, int $id, int $reviewerUserId, ?string $level = null): array {
    $gear = db_get_gear_record($pdo, $id);
    if ($gear === null) return ['ok' => false, 'error' => 'Gear record not found.'];
    $isIce = ($gear['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE;
    if ($isIce && !isset(ICE_GEAR_LEVEL_LABELS[(string)$level])) {
        return ['ok' => false, 'error' => 'Choose the gear level: street-safe or caged.'];
    }
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if (!db_accept_gear_in_person($pdo, $id, $reviewerUserId)) {
            if ($own) $pdo->rollBack();
            return ['ok' => false, 'error' => 'This driver\'s gear has already been teched.'];
        }
        if ($isIce) db_set_gear_level($pdo, $id, $level);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'error' => null];
}

/** Undo an acceptance (for example the wrong driver was accepted). @return array{ok: bool, error: ?string} */
function gearRevoke(PDO $pdo, int $id): array {
    $gear = db_get_gear_record($pdo, $id);
    if ($gear === null) return ['ok' => false, 'error' => 'Gear record not found.'];
    $viaPhotos = ($gear['accepted_via'] ?? null) === 'photos';

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if (!db_revoke_gear_acceptance($pdo, $id)) {
            if ($own) $pdo->rollBack();
            return ['ok' => false, 'error' => 'This gear record has not been accepted.'];
        }
        // The photos go back to awaiting review along with the record.
        if ($viaPhotos) db_set_all_photos_review_status($pdo, 'gear_record', $id, 'pending');
        if (($gear['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE) db_set_gear_level($pdo, $id, null);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Each driver on a sheet (driver 1, then the additional drivers) matched to the sheet owner's
 * gear record for the sheet's season and discipline, by normalised name. $ownerGear may hold any
 * owner's, any season's and any discipline's records: only the owner's, same-season, same-discipline
 * records are considered.
 *
 * @return array<int, array{driver_number: int, name: string, name_norm: string, discipline: string, default_level: ?string, gear: ?array, status: array{state: string, via: ?string}}>
 */
function gearLinksForSheet(array $sheet, array $drivers, array $ownerGear): array {
    $discipline = (string)($sheet['discipline'] ?? DISCIPLINE_SUMMER);
    $season = (int)($sheet['season'] ?? 0) ?: gearSeasonNow($discipline);
    $ownerId = (int)($sheet['user_id'] ?? 0);
    $defaultLevel = $discipline === DISCIPLINE_ICE ? iceGearLevelForClass((string)($sheet['club'] ?? ''), (string)($sheet['class'] ?? '')) : null;

    $byName = [];
    foreach ($ownerGear as $g) {
        if ((int)$g['owner_user_id'] === $ownerId && (int)$g['season'] === $season
            && ($g['discipline'] ?? DISCIPLINE_SUMMER) === $discipline) {
            $byName[$g['driver_name_norm']] = $g;
        }
    }

    $entries = [[1, (string)($sheet['driver_name'] ?? '')]];
    foreach ($drivers as $d) {
        $entries[] = [(int)$d['driver_number'], (string)$d['driver_name']];
    }

    $links = [];
    foreach ($entries as [$number, $rawName]) {
        $name = trim((string)preg_replace('/\s+/', ' ', $rawName));
        if ($name === '') continue;
        $norm = gearNameNorm($name);
        $gear = $byName[$norm] ?? null;
        $links[] = [
            'driver_number' => $number,
            'name' => $name,
            'name_norm' => $norm,
            'discipline' => $discipline,
            'default_level' => $defaultLevel,
            'gear' => $gear,
            'status' => $gear !== null ? gearStatus($gear) : ['state' => 'none', 'via' => null],
        ];
    }
    return $links;
}

/**
 * Adds `gear_links` to each roster row. $driversBySheet is db_get_drivers_for_sheets(); $seasonGear
 * is every owner's gear records for the season(s) on the roster.
 */
function gearAttachToRoster(array $rows, array $driversBySheet, array $seasonGear): array {
    $byOwner = [];
    foreach ($seasonGear as $g) {
        $byOwner[(int)$g['owner_user_id']][] = $g;
    }
    foreach ($rows as $i => $row) {
        $sheet = $row['sheet'];
        $rows[$i]['gear_links'] = gearLinksForSheet($sheet, $driversBySheet[(int)$sheet['id']] ?? [], $byOwner[(int)$sheet['user_id']] ?? []);
    }
    return $rows;
}

/**
 * Inspector shortcut for a driver on a sheet who has no gear record yet: creates the record under
 * the SHEET OWNER's account for the sheet's season and accepts it in person, in one transaction.
 * An existing record for that owner/name/season is accepted instead of duplicated. Current season
 * only. The name always comes from the sheet, so no arbitrary name can be created.
 *
 * @param array $sheet    a tech_sheets row (user_id, season, driver_name)
 * @param array $drivers  db_get_tech_sheet_drivers() rows (driver_number, driver_name)
 * @return array{ok: bool, error: ?string, id: ?int}
 */
function gearCreateAndAcceptInPerson(PDO $pdo, array $sheet, array $drivers, int $driverNumber, int $reviewerUserId, ?string $level = null): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'id' => null];

    $name = null;
    if ($driverNumber === 1) {
        $name = (string)($sheet['driver_name'] ?? '');
    } else {
        foreach ($drivers as $d) {
            if ((int)$d['driver_number'] === $driverNumber) {
                $name = (string)$d['driver_name'];
                break;
            }
        }
    }
    $name = trim((string)preg_replace('/\s+/', ' ', (string)$name));
    if ($name === '') return $fail('That driver is not on this sheet.');

    $discipline = (string)($sheet['discipline'] ?? DISCIPLINE_SUMMER);
    $season = (int)($sheet['season'] ?? 0) ?: gearSeasonNow($discipline);
    if ($season !== gearSeasonNow($discipline)) return $fail('Gear can only be added for the current season.');
    if ($discipline === DISCIPLINE_ICE && !isset(ICE_GEAR_LEVEL_LABELS[(string)$level])) {
        return $fail('Choose the gear level: street-safe or caged.');
    }

    $ownerId = (int)($sheet['user_id'] ?? 0);

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $existing = db_find_gear_record($pdo, $ownerId, gearNameNorm($name), $season, $discipline);
        if ($existing !== null) {
            $id = (int)$existing['id'];
        } else {
            $created = gearCreate($pdo, $ownerId, $name, '', $season, $discipline);
            if (!$created['ok']) {
                if ($own) $pdo->rollBack();
                return $fail((string)$created['error']);
            }
            $id = (int)$created['id'];
        }
        $accepted = gearAcceptInPerson($pdo, $id, $reviewerUserId, $level);
        if (!$accepted['ok']) {
            if ($own) $pdo->rollBack();
            return $fail((string)$accepted['error']);
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'error' => null, 'id' => $id];
}

/**
 * Opens ice gear photos from an ice tech sheet: finds or creates the ice gear record for one of the
 * sheet's drivers, in the sheet's (current) ice season, under the sheet owner. $driverNumber 1 is the
 * sheet's primary driver; 2 and up is that sheet's tech_sheet_drivers row with that driver_number
 * (looked up on this sheet only).
 *
 * @return array{ok: bool, error: ?string, id: ?int}
 */
function gearStartIceForSheet(PDO $pdo, array $sheet, int $ownerId, int $driverNumber = 1): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'id' => null];
    if ((int)($sheet['user_id'] ?? 0) !== $ownerId) return $fail('Tech sheet not found.');
    if (($sheet['discipline'] ?? DISCIPLINE_SUMMER) !== DISCIPLINE_ICE) return $fail('That is not an ice tech sheet.');
    $season = (int)($sheet['season'] ?? 0);
    if ($season !== gearSeasonNow(DISCIPLINE_ICE)) return $fail('Gear photos can only be added for the current ice season.');
    if ($driverNumber < 1) return $fail('That driver is not on this sheet.');
    $rawName = null;
    if ($driverNumber === 1) {
        $rawName = (string)($sheet['driver_name'] ?? '');
    } else {
        foreach (db_get_tech_sheet_drivers($pdo, (int)$sheet['id']) as $d) {
            if ((int)$d['driver_number'] === $driverNumber) { $rawName = (string)$d['driver_name']; break; }
        }
        if ($rawName === null) return $fail('That driver is not on this sheet.');
    }
    $name = trim((string)preg_replace('/\s+/', ' ', $rawName));
    if ($name === '') return $fail($driverNumber === 1 ? 'This tech sheet has no driver.' : 'That driver is not on this sheet.');

    $existing = db_find_gear_record($pdo, $ownerId, gearNameNorm($name), $season, DISCIPLINE_ICE);
    if ($existing !== null) return ['ok' => true, 'error' => null, 'id' => (int)$existing['id']];
    $created = gearCreate($pdo, $ownerId, $name, '', $season, DISCIPLINE_ICE);
    return $created['ok'] ? ['ok' => true, 'error' => null, 'id' => (int)$created['id']] : $fail((string)$created['error']);
}

/**
 * A driver's ice gear for $iceSeason (spec §4a): an accepted ice record, else accepted race-level
 * summer gear from the season before (it counts as caged ice gear), else the ice record's state.
 * @return array{state: string, label: string, gearId: ?int}
 */
function gearIceSummary(?array $iceGear, ?array $summerPrev, int $iceSeason): array {
    $iceStatus = $iceGear !== null ? gearStatus($iceGear) : null;
    if ($iceStatus !== null && $iceStatus['state'] === 'accepted') {
        $level = (string)($iceGear['level'] ?? '');
        return ['state' => 'accepted',
                'label' => gearStatusLabel($iceStatus, $iceSeason, DISCIPLINE_ICE) . ($level !== '' ? ' · ' . (ICE_GEAR_LEVEL_LABELS[$level] ?? $level) : ''),
                'gearId' => (int)$iceGear['id']];
    }
    // Only race-level summer gear counts on ice; TA/Drift-level gear doesn't meet caged standards.
    if (gearCoversTier($summerPrev, TECH_TIER_RACE)) {
        return ['state' => 'accepted', 'label' => iceSeasonLabel($iceSeason) . ': from summer ' . ($iceSeason - 1), 'gearId' => null];
    }
    if ($iceStatus !== null && $iceStatus['state'] !== 'none') {
        return ['state' => $iceStatus['state'], 'label' => gearStatusLabel($iceStatus, $iceSeason, DISCIPLINE_ICE), 'gearId' => (int)$iceGear['id']];
    }
    return ['state' => 'none', 'label' => "Needs ice gear check $iceSeason", 'gearId' => $iceGear !== null ? (int)$iceGear['id'] : null];
}
