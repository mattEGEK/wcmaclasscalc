<?php
// wcma-calculator/media-service.php
//
// DB work for driver media profiles: save, withdraw, delete (this task), and the review actions and
// roster/kit loaders (added in later tasks). No session or echo; controllers pass the user id.
// Callers must have loaded db.php, roles.php, photo-requirements.php, inspection-lib.php and media-lib.php.
require_once __DIR__ . '/ice-sheet-lib.php';

const MEDIA_PHOTO_DIR = 'uploads/media';

function mediaOwnedDriver(PDO $pdo, int $userId, int $driverId): ?array {
    $driver = db_get_driver($pdo, $driverId);
    return $driver !== null && (int)$driver['owner_user_id'] === $userId ? $driver : null;
}

function mediaIsSelf(array $driver, int $userId): bool {
    return (int)($driver['user_id'] ?? 0) === $userId;
}

/** @return array{ok: bool, errors: string[], status: ?string} */
function mediaSaveProfile(PDO $pdo, int $userId, int $driverId, array $post, ?string $uploadTmp, string $baseDir, ?callable $mover = null): array {
    $fail = fn(array $errors): array => ['ok' => false, 'errors' => $errors, 'status' => null];
    $driver = mediaOwnedDriver($pdo, $userId, $driverId);
    if ($driver === null) return $fail(['Choose one of your drivers.']);

    $v = mediaValidateFields($post, (int)date('Y'));
    $latestConsent = db_get_latest_media_consent($pdo, $driverId);
    $isSelf = mediaIsSelf($driver, $userId);
    // Only make the on-behalf confirmation box mandatory when the consent itself is actually
    // changing; re-saving unrelated fields (a blurb tweak) with consent unchanged should not force
    // the co-driver's manager to tick it again every time.
    $probe = mediaConsentInput($post, $isSelf, false);
    $c = mediaConsentChanged($latestConsent, $probe['consent']) ? mediaConsentInput($post, $isSelf, true) : $probe;
    $errors = $v['errors'];
    if (!$c['ok']) $errors[] = $c['error'];
    $image = null;
    if ($uploadTmp !== null) {
        $image = inspectionValidateImage($uploadTmp);
        if (!$image['ok']) $errors[] = $image['error'];
    }
    if ($errors) return $fail($errors);

    $profile = db_get_media_profile($pdo, $driverId);
    $sponsorsBefore = db_get_sponsors($pdo, $driverId);
    $oldPhoto = $profile['photo_path'] ?? null;
    $photoPath = $oldPhoto;
    $newAbs = null;
    if ($image !== null) {
        $photoPath = MEDIA_PHOTO_DIR . '/driver-' . $driverId . '-' . bin2hex(random_bytes(6)) . '.' . $image['ext'];
        $newAbs = $baseDir . '/' . $photoPath;
        $dir = dirname($newAbs);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return $fail(['Could not store the photo.']);
        if (!($mover ?? 'move_uploaded_file')($uploadTmp, $newAbs)) return $fail(['Could not store the photo.']);
    }

    $status = mediaNextPublicStatus((string)($profile['public_status'] ?? 'none'), $c['consent']['consent_public'] === 1,
        mediaContentChanged($profile, $sponsorsBefore, $v['fields'], $v['sponsors'], $image !== null));

    $pdo->beginTransaction();
    try {
        db_save_media_profile($pdo, $driverId, $v['fields'] + ['photo_path' => $photoPath, 'public_status' => $status]);
        db_replace_sponsors($pdo, $driverId, $v['sponsors']);
        if (mediaConsentChanged($latestConsent, $c['consent'])) {
            db_insert_media_consent($pdo, $c['consent'] + ['driver_id' => $driverId, 'given_by_user_id' => $userId,
                'wording_version' => MEDIA_CONSENT_WORDING_VERSION]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($newAbs !== null && is_file($newAbs)) unlink($newAbs);
        throw $e;
    }
    if ($image !== null && $oldPhoto && is_file($baseDir . '/' . $oldPhoto)) unlink($baseDir . '/' . $oldPhoto);
    return ['ok' => true, 'errors' => [], 'status' => $status];
}

/** Adds an all-off consent row (keeping the minor details) and takes the public page down. */
function mediaWithdraw(PDO $pdo, int $userId, int $driverId): bool {
    $driver = mediaOwnedDriver($pdo, $userId, $driverId);
    if ($driver === null) return false;
    $latest = db_get_latest_media_consent($pdo, $driverId);
    if (mediaCurrentConsent($latest)['media']) {
        db_insert_media_consent($pdo, [
            'driver_id' => $driverId, 'consent_media' => 0, 'consent_public' => 0,
            'is_minor' => (int)($latest['is_minor'] ?? 0), 'guardian_name' => $latest['guardian_name'] ?? null,
            'given_by_user_id' => $userId, 'on_behalf' => mediaIsSelf($driver, $userId) ? 0 : 1,
            'wording_version' => MEDIA_CONSENT_WORDING_VERSION,
        ]);
    }
    if (db_get_media_profile($pdo, $driverId) !== null) {
        $pdo->prepare("UPDATE driver_media_profiles SET public_status = 'none' WHERE driver_id = :d")->execute([':d' => $driverId]);
    }
    return true;
}

/** Hidden profiles delete as a tombstone (content and sponsors cleared, hidden_* kept) so the
 *  driver cannot re-create the profile and put it straight back onto the announcer/kit. Unhidden
 *  profiles delete outright, as before. */
function mediaDeleteProfile(PDO $pdo, int $userId, int $driverId, string $baseDir): bool {
    if (!mediaWithdraw($pdo, $userId, $driverId)) return false;
    $profile = db_get_media_profile($pdo, $driverId);
    $photo = $profile['photo_path'] ?? null;
    if (!empty($profile['hidden_at'])) {
        db_tombstone_media_profile($pdo, $driverId);
    } else {
        db_delete_media_profile($pdo, $driverId);
    }
    if ($photo && is_file($baseDir . '/' . $photo)) unlink($baseDir . '/' . $photo);
    return true;
}

/** @return array<int, array{number: string, car: string, class: string, drivers: array}> */
function mediaAnnouncerRoster(PDO $pdo, int $eventId): array {
    $event = db_get_event($pdo, $eventId);
    $isIce = ($event['discipline'] ?? 'summer') === 'ice';
    $cars = db_get_event_roster_cars($pdo, $eventId);
    $byCar = [];
    foreach (db_get_event_tech_sheets($pdo, $eventId) as $s) $byCar[(int)$s['car_id']][] = $s;
    $latest = [];
    foreach ($cars as $car) {
        $cid = (int)$car['id'];
        if (isset($byCar[$cid])) continue;
        $lid = db_get_car_latest_tech_sheet_id($pdo, $cid);
        if ($lid === null) continue;
        $sheet = db_get_tech_sheet($pdo, $lid);
        // Only a sheet of the event's own discipline says who drives the car there.
        if ($sheet !== null && techSheetIsIce($sheet) === $isIce) $latest[$cid] = $sheet;
    }
    $sheetIds = array_merge(array_column(array_merge(...array_values($byCar ?: [[]])), 'id'), array_column(array_values($latest), 'id'));
    $sheetDrivers = db_get_drivers_for_sheets($pdo, $sheetIds);
    $selfs = db_get_self_drivers_for_users($pdo, array_column($cars, 'owner_user_id'));
    $decls = db_get_declarations_for_cars($pdo, array_column($cars, 'id'));

    $perCar = [];
    $allIds = [];
    foreach ($cars as $car) {
        $cid = (int)$car['id'];
        $owner = (int)$car['owner_user_id'];
        $ids = mediaRosterDriverIds($byCar[$cid] ?? [], $latest[$cid] ?? null, $sheetDrivers, isset($selfs[$owner]) ? (int)$selfs[$owner]['id'] : null);
        $perCar[$cid] = $ids;
        array_push($allIds, ...$ids);
    }
    $bundles = db_get_media_bundle($pdo, $allIds);

    $out = [];
    foreach ($cars as $car) {
        $cid = (int)$car['id'];
        $eventSheets = $byCar[$cid] ?? [];
        $class = $eventSheets ? techSheetClassLine(end($eventSheets)) : ($isIce ? '' : mediaAcceptedClass($decls[$cid] ?? []));
        $carLabel = mediaCarLabel($car);
        $drivers = [];
        foreach ($perCar[$cid] as $did) {
            $driver = db_get_driver($pdo, $did);
            if ($driver === null) continue;
            $b = $bundles[$did];
            $entry = mediaUsable($b['profile'], $b['consent'], 'club')
                ? mediaEntry($driver, $b['profile'], $b['sponsors'], (string)$car['car_number'], $carLabel, $class, mediaUsable($b['profile'], $b['consent'], 'public'))
                : null;
            $drivers[] = ['name' => (string)$driver['name'], 'entry' => $entry];
        }
        $out[] = ['number' => (string)$car['car_number'], 'car' => $carLabel, 'class' => $class, 'drivers' => $drivers];
    }
    return $out;
}

/** The sheet media shows for a driver: from $season when given, else the newest from the current
 *  summer or current ice season (a summer driver with no sheet yet this year shows no car, not last year's). */
function mediaDriverSheet(PDO $pdo, int $driverId, ?int $season): ?array {
    if ($season !== null) return db_get_driver_latest_sheet($pdo, $driverId, $season);
    return db_get_driver_current_sheet($pdo, $driverId, (int)date('Y'), iceSeasonFromDate(date('Y-m-d')));
}

/** mediaEntry() arrays for the drivers usable for clubs, car details from their latest sheet of either
 *  discipline; limited to $season when given (null means the newest sheet of either discipline). */
function mediaEntriesForDrivers(PDO $pdo, array $driverIds, ?int $season): array {
    $out = [];
    foreach (db_get_media_bundle($pdo, $driverIds) as $did => $b) {
        if (!mediaUsable($b['profile'], $b['consent'], 'club')) continue;
        $driver = db_get_driver($pdo, $did);
        if ($driver === null) continue;
        $sheet = mediaDriverSheet($pdo, $did, $season);
        $out[] = mediaEntry($driver, $b['profile'], $b['sponsors'], (string)($sheet['car_number'] ?? ''),
            $sheet !== null ? mediaCarLabel($sheet) : '', $sheet !== null ? techSheetClassLine($sheet) : '', mediaUsable($b['profile'], $b['consent'], 'public'));
    }
    return $out;
}

/** $eventId 0 = every consented driver; otherwise the entries on that event's announcer roster. */
function mediaKitEntries(PDO $pdo, int $eventId, ?int $season): array {
    if ($eventId === 0) return mediaEntriesForDrivers($pdo, db_get_consented_driver_ids($pdo), $season);
    $out = [];
    foreach (mediaAnnouncerRoster($pdo, $eventId) as $car) {
        foreach ($car['drivers'] as $d) {
            if ($d['entry'] !== null) $out[] = $d['entry'];
        }
    }
    return $out;
}

/**
 * The public driver list: live public profiles only (public consent, accepted, not hidden). $eventId 0
 * lists everyone by name; otherwise the drivers on that event's roster (same rules as the Announcer),
 * in car-number order, each driver once.
 */
function mediaPublicDirectory(PDO $pdo, int $eventId, ?int $season): array {
    $out = [];
    foreach (mediaKitEntries($pdo, $eventId, $season) as $e) {
        if ($e['public_live'] && !isset($out[$e['driver_id']])) $out[$e['driver_id']] = $e;
    }
    $out = array_values($out);
    if ($eventId === 0) usort($out, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    return $out;
}

/** The public-review queue, skipping profiles with no photo and no blurb (nothing for a reviewer
 *  to look at, and nothing that should have reached pending_review in the first place). Each row
 *  carries the profile's updated_at so the review form can pin the version the reviewer saw. */
function mediaReviewQueue(PDO $pdo, ?int $season): array {
    $queue = [];
    foreach (db_get_media_review_queue($pdo) as $row) {
        if (!mediaHasContent($row)) continue;
        $did = (int)$row['driver_id'];
        $consent = db_get_latest_media_consent($pdo, $did);
        if (!mediaCurrentConsent($consent)['public']) continue;
        $driver = db_get_driver($pdo, $did);
        $sheet = mediaDriverSheet($pdo, $did, $season);
        $queue[] = ['driver_id' => $did, 'driver_name' => $row['driver_name'], 'updated_at' => (string)$row['updated_at'],
            'entry' => mediaEntry($driver, $row, db_get_sponsors($pdo, $did), (string)($sheet['car_number'] ?? ''),
                $sheet !== null ? mediaCarLabel($sheet) : '', $sheet !== null ? techSheetClassLine($sheet) : '', false)];
    }
    return $queue;
}

const MEDIA_POST_ACTIONS = ['media-accept', 'media-send-back', 'media-hide', 'media-unhide'];

/** @return array{ok: bool, error: ?string, notify: ?string, note: string} note is the normalised note on
 *  success when the action carries one (send-back, hide); '' on failure or when not applicable.
 *  $seenUpdatedAt is the profile's updated_at as the reviewer last saw it (the review form's hidden
 *  "seen" input); if it no longer matches, the driver changed the profile after the reviewer opened
 *  it, so accept/send-back are refused rather than acting on a version the reviewer never looked at. */
function mediaReviewAction(PDO $pdo, string $action, int $driverId, int $reviewerId, string $note, string $seenUpdatedAt = ''): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'notify' => null, 'note' => ''];
    $ok = fn(?string $notify = null, string $n = ''): array => ['ok' => true, 'error' => null, 'notify' => $notify, 'note' => $n];
    if (!in_array($action, MEDIA_POST_ACTIONS, true)) return $fail('Unknown action.');
    $profile = db_get_media_profile($pdo, $driverId);
    if ($profile === null) return $fail('That profile no longer exists.');
    if (in_array($action, ['media-accept', 'media-send-back'], true) && $seenUpdatedAt !== ''
        && $seenUpdatedAt !== (string)$profile['updated_at']) {
        return $fail('The driver changed this profile after you opened it. Review the new version.');
    }
    $note = trim((string)preg_replace('/\s+/u', ' ', $note));
    if (mb_strlen($note, 'UTF-8') > 500) return $fail('Keep the note to 500 characters or fewer.');
    switch ($action) {
        case 'media-accept':
            if ($profile['public_status'] !== 'pending_review') return $fail('That profile is not waiting for review.');
            db_set_media_public_status($pdo, $driverId, 'accepted', $reviewerId, null);
            return $ok();
        case 'media-send-back':
            if ($note === '') return $fail('Add a note so the driver knows what to change.');
            if ($profile['public_status'] !== 'pending_review') return $fail('That profile is not waiting for review.');
            db_set_media_public_status($pdo, $driverId, 'sent_back', $reviewerId, $note);
            return $ok('sent_back', $note);
        case 'media-hide':
            if ($note === '') return $fail('Add a reason for hiding it.');
            db_set_media_hidden($pdo, $driverId, $reviewerId, $note);
            return $ok('hidden', $note);
        default:   // media-unhide
            db_set_media_hidden($pdo, $driverId, null, null);
            return $ok();
    }
}
