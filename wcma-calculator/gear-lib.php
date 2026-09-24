<?php
// wcma-calculator/gear-lib.php
//
// Driver gear records: status, create/renew, and the photo pre-tech workflow. Session-free
// (callers inject the reviewer id) so it is unit-testable. Callers must have loaded db.php.
require_once __DIR__ . '/photo-requirements.php';
require_once __DIR__ . '/inspection-lib.php';
require_once __DIR__ . '/pretech-lib.php';   // pretechPlural(), pretechCapText()
require_once __DIR__ . '/tech-status.php';   // techCarStatusBadgeClass()

/** Collapse whitespace, trim, lowercase: the identity of a driver name within an owner and season. */
function gearNameNorm(string $name): string {
    return strtolower(trim((string)preg_replace('/\s+/', ' ', $name)));
}

function gearSeasonNow(): int {
    return (int)date('Y');
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

function gearStatusLabel(array $status, int $season): string {
    switch ($status['state']) {
        case 'accepted':       return ($status['via'] === 'photos' ? 'Gear pre-teched ' : 'Gear teched ') . $season;
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
function gearCreate(PDO $pdo, int $ownerId, string $name, string $licence, int $season): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'id' => null];

    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    $licence = trim($licence);
    if ($name === '') return $fail('Enter the driver\'s name.');
    if (mb_strlen($name, 'UTF-8') > 100) return $fail('That name is too long (100 characters at most).');
    if (mb_strlen($licence, 'UTF-8') > 40) return $fail('That licence number is too long (40 characters at most).');

    $norm = gearNameNorm($name);
    if (db_find_gear_record($pdo, $ownerId, $norm, $season) !== null) {
        return $fail('You already have a gear record for ' . $name . ' this season.');
    }
    try {
        $id = db_insert_gear_record($pdo, $ownerId, $name, $norm, $licence !== '' ? $licence : null, $season);
    } catch (PDOException $e) {
        return $fail('You already have a gear record for ' . $name . ' this season.');   // lost a race with a duplicate request
    }
    return ['ok' => true, 'error' => null, 'id' => $id];
}

/** New-season copy of an earlier record (same name and licence, no photos). @return array{ok: bool, error: ?string, id: ?int} */
function gearRenew(PDO $pdo, int $ownerId, int $fromId, int $season): array {
    $from = db_get_gear_record($pdo, $fromId);
    if ($from === null || (int)$from['owner_user_id'] !== $ownerId) {
        return ['ok' => false, 'error' => 'Gear record not found.', 'id' => null];
    }
    if ($season <= (int)$from['season']) {
        return ['ok' => false, 'error' => 'Choose a later season to renew for.', 'id' => null];
    }
    return gearCreate($pdo, $ownerId, $from['driver_name'], (string)($from['licence_no'] ?? ''), $season);
}

/** Photos of a gear record, which are present, which conditional ones apply, and what is still missing. */
function gearSnapshot(PDO $pdo, int $id): array {
    $photos = db_get_inspection_photos($pdo, 'gear_record', $id);
    $present = [];
    $applicable = [];
    foreach ($photos as $key => $row) {
        if ($row['file_path'] !== '') $present[] = $key;
        $req = photoRequirementByKey($key);
        if ($req !== null && $req['tier'] === 'conditional' && (int)$row['applies'] === 1) $applicable[] = $key;
    }
    return [
        'photos' => $photos,
        'present' => $present,
        'applicable' => $applicable,
        'missing' => photoSetMissingRequired('gear', $present, $applicable),
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

/** Inspector accepts a submitted photo set remotely. @return array{ok: bool, error: ?string} */
function gearAcceptByPhotos(PDO $pdo, int $id, int $reviewerUserId): array {
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if (!db_accept_gear_by_photos($pdo, $id, $reviewerUserId)) {
            if ($own) $pdo->rollBack();
            return ['ok' => false, 'error' => 'These photos are not awaiting review.'];
        }
        db_set_all_photos_review_status($pdo, 'gear_record', $id, 'accepted');
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'error' => null];
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

/** Inspector accepts the driver's gear in person: no photos needed. @return array{ok: bool, error: ?string} */
function gearAcceptInPerson(PDO $pdo, int $id, int $reviewerUserId): array {
    $gear = db_get_gear_record($pdo, $id);
    if ($gear === null) return ['ok' => false, 'error' => 'Gear record not found.'];
    if (!db_accept_gear_in_person($pdo, $id, $reviewerUserId)) {
        return ['ok' => false, 'error' => 'This driver\'s gear has already been teched.'];
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
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Each driver on a sheet (driver 1, then the additional drivers) matched to the sheet owner's
 * gear record for the sheet's season, by normalised name. $ownerGear may hold any owner's and any
 * season's records: only the owner's, same-season records are considered.
 *
 * @return array<int, array{driver_number: int, name: string, name_norm: string, gear: ?array, status: array{state: string, via: ?string}}>
 */
function gearLinksForSheet(array $sheet, array $drivers, array $ownerGear): array {
    $season = (int)($sheet['season'] ?? 0) ?: gearSeasonNow();
    $ownerId = (int)($sheet['user_id'] ?? 0);

    $byName = [];
    foreach ($ownerGear as $g) {
        if ((int)$g['owner_user_id'] === $ownerId && (int)$g['season'] === $season) {
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
            'gear' => $gear,
            'status' => $gear !== null ? gearStatus($gear) : ['state' => 'none', 'via' => null],
        ];
    }
    return $links;
}

/** Distinct gear-record driver names for a season, sorted case-insensitively: suggestions for the sheet form. */
function gearNameSuggestions(array $ownerGear, int $season): array {
    $names = [];
    foreach ($ownerGear as $g) {
        if ((int)$g['season'] === $season) $names[$g['driver_name']] = true;
    }
    $names = array_keys($names);
    usort($names, fn(string $a, string $b): int => strcasecmp($a, $b) ?: strcmp($a, $b));
    return $names;
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
