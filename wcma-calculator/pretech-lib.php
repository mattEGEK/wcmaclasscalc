<?php
// wcma-calculator/pretech-lib.php
//
// The photo pre-tech workflow for a tech sheet. Session-free (callers inject the reviewer id)
// so it is unit-testable. Callers must have loaded db.php, tech-status.php,
// photo-requirements.php and inspection-lib.php.

/** Photos of the sheet, which are present, which conditional ones apply, and what is still missing. */
function pretechSnapshot(PDO $pdo, int $sheetId): array {
    $photos = db_get_inspection_photos($pdo, 'tech_sheet', $sheetId);
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
        'missing' => photoSetMissingRequired('car', $present, $applicable),
    ];
}

/** Caps text at $max characters (bytes when mbstring is unavailable). */
function pretechCapText(string $s, int $max): string {
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

/** The owner may edit a sheet only while it is unreviewed and its photos are not under or past review. */
function pretechSheetEditable(array $sheet): bool {
    return ($sheet['status'] ?? '') === 'submitted'
        && !in_array($sheet['photo_status'] ?? null, ['submitted', 'accepted'], true);
}

function pretechPlural(int $n, string $singular, string $plural): string {
    return $n === 1 ? $singular : $plural;
}

/** Competitor submits a complete photo set for review. @return array{ok: bool, error: ?string} */
function pretechSubmit(PDO $pdo, int $sheetId): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg];

    $sheet = db_get_tech_sheet($pdo, $sheetId);
    if ($sheet === null) return $fail('Tech sheet not found.');
    if ($sheet['status'] === 'teched') return $fail('This car has already been teched.');

    $identity = db_get_identity_sheets($pdo, (int)$sheet['user_id'], (string)$sheet['car_number_norm'], (int)$sheet['season']);
    $mode = pretechPageMode($sheet, $identity)['mode'];
    if ($mode === 'car_accepted') return $fail('This car has already been teched for the season, so no photos are needed.');
    if ($mode === 'held_elsewhere') return $fail('Your pre-tech photos for this car are on another of your tech sheets.');

    $photoStatus = $sheet['photo_status'] ?? null;
    if ($photoStatus === 'submitted') return $fail('These photos have already been submitted for review.');
    if (!in_array($photoStatus, ['draft', 'needs_changes'], true)) return $fail('Add your photos before submitting.');

    $snapshot = pretechSnapshot($pdo, $sheetId);
    $missing = count($snapshot['missing']);
    if ($missing > 0) {
        return $fail($missing . ' required ' . pretechPlural($missing, 'photo is', 'photos are') . ' still missing.');
    }
    foreach ($snapshot['photos'] as $row) {
        if ($row['file_path'] !== '' && $row['review_status'] === 'retake') {
            return $fail('Please retake the photos the inspector flagged before submitting again.');
        }
    }

    if (!db_transition_tech_sheet_photo_status($pdo, $sheetId, ['draft', 'needs_changes'], 'submitted')) {
        return $fail('These photos could not be submitted. Please reload and try again.');
    }
    return ['ok' => true, 'error' => null];
}

/** Inspector accepts a submitted photo set remotely. @return array{ok: bool, error: ?string} */
function pretechAccept(PDO $pdo, int $sheetId, int $reviewerUserId): array {
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        if (!db_accept_tech_sheet_by_photos($pdo, $sheetId, $reviewerUserId)) {
            if ($owns) $pdo->rollBack();
            return ['ok' => false, 'error' => 'These photos are not awaiting review.'];
        }
        db_set_all_photos_review_status($pdo, 'tech_sheet', $sheetId, 'accepted');
        if ($owns) $pdo->commit();
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
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
function pretechSendBack(PDO $pdo, int $sheetId, array $notes): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'retakes' => []];

    $sheet = db_get_tech_sheet($pdo, $sheetId);
    if ($sheet === null) return $fail('Tech sheet not found.');
    if ($sheet['status'] === 'teched' || ($sheet['photo_status'] ?? null) !== 'submitted') {
        return $fail('These photos are not awaiting review.');
    }

    $photos = db_get_inspection_photos($pdo, 'tech_sheet', $sheetId);
    $retakes = [];
    foreach ($notes as $key => $note) {
        if (!isset($photos[$key]) || $photos[$key]['file_path'] === '') continue;
        $note = pretechCapText(trim((string)$note), 500);
        if ($note === '') return $fail('Add a note for every photo you send back.');
        $retakes[$key] = $note;
    }
    if (!$retakes) return $fail('Choose at least one photo to retake and say what is wrong.');

    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        if (!db_transition_tech_sheet_photo_status($pdo, $sheetId, ['submitted'], 'needs_changes')) {
            if ($owns) $pdo->rollBack();
            return $fail('These photos are not awaiting review.');
        }
        foreach ($retakes as $key => $note) {
            db_set_inspection_photo_review($pdo, (int)$photos[$key]['id'], 'retake', $note);
        }
        if ($owns) $pdo->commit();
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'error' => null, 'retakes' => $retakes];
}

/**
 * What the competitor's pre-tech page should do for $sheet, given ALL of the owner's sheets for
 * this car identity: the car is already accepted, another sheet already holds the photo set, or
 * this sheet is where the photos live.
 *
 * @return array{mode: string, sheet_id: ?int}
 */
function pretechPageMode(array $sheet, array $identitySheets): array {
    $status = techCarStatus($identitySheets);
    if ($status['state'] === 'accepted') return ['mode' => 'car_accepted', 'sheet_id' => $status['sheet_id']];

    foreach ($identitySheets as $other) {
        if ((int)$other['id'] !== (int)$sheet['id'] && ($other['photo_status'] ?? null) !== null) {
            return ['mode' => 'held_elsewhere', 'sheet_id' => (int)$other['id']];
        }
    }
    return ['mode' => 'this_sheet', 'sheet_id' => null];
}
