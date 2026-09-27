<?php
// wcma-calculator/media-service.php
//
// DB work for driver media profiles: save, withdraw, delete (this task), and the review actions and
// roster/kit loaders (added in later tasks). No session or echo; controllers pass the user id.
// Callers must have loaded db.php, roles.php, photo-requirements.php, inspection-lib.php and media-lib.php.

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
    $c = mediaConsentInput($post, mediaIsSelf($driver, $userId));
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
        if (mediaConsentChanged(db_get_latest_media_consent($pdo, $driverId), $c['consent'])) {
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

function mediaDeleteProfile(PDO $pdo, int $userId, int $driverId, string $baseDir): bool {
    if (!mediaWithdraw($pdo, $userId, $driverId)) return false;
    $photo = db_get_media_profile($pdo, $driverId)['photo_path'] ?? null;
    db_delete_media_profile($pdo, $driverId);
    if ($photo && is_file($baseDir . '/' . $photo)) unlink($baseDir . '/' . $photo);
    return true;
}

/** @return array<int, array{number: string, car: string, class: string, drivers: array}> */
function mediaAnnouncerRoster(PDO $pdo, int $eventId): array {
    $cars = db_get_event_roster_cars($pdo, $eventId);
    $byCar = [];
    foreach (db_get_event_tech_sheets($pdo, $eventId) as $s) $byCar[(int)$s['car_id']][] = $s;
    $latest = [];
    foreach ($cars as $car) {
        $cid = (int)$car['id'];
        if (isset($byCar[$cid])) continue;
        $lid = db_get_car_latest_tech_sheet_id($pdo, $cid);
        if ($lid !== null) $latest[$cid] = db_get_tech_sheet($pdo, $lid);
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
        $class = $eventSheets ? (string)end($eventSheets)['class'] : mediaAcceptedClass($decls[$cid] ?? []);
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

/** mediaEntry() arrays for the drivers usable for clubs, car details from their latest sheet in $season. */
function mediaEntriesForDrivers(PDO $pdo, array $driverIds, int $season): array {
    $out = [];
    foreach (db_get_media_bundle($pdo, $driverIds) as $did => $b) {
        if (!mediaUsable($b['profile'], $b['consent'], 'club')) continue;
        $driver = db_get_driver($pdo, $did);
        if ($driver === null) continue;
        $sheet = db_get_driver_latest_sheet($pdo, $did, $season);
        $out[] = mediaEntry($driver, $b['profile'], $b['sponsors'], (string)($sheet['car_number'] ?? ''),
            $sheet !== null ? mediaCarLabel($sheet) : '', (string)($sheet['class'] ?? ''), mediaUsable($b['profile'], $b['consent'], 'public'));
    }
    return $out;
}

/** $eventId 0 = every consented driver; otherwise the entries on that event's announcer roster. */
function mediaKitEntries(PDO $pdo, int $eventId, int $season): array {
    if ($eventId === 0) return mediaEntriesForDrivers($pdo, db_get_consented_driver_ids($pdo), $season);
    $out = [];
    foreach (mediaAnnouncerRoster($pdo, $eventId) as $car) {
        foreach ($car['drivers'] as $d) {
            if ($d['entry'] !== null) $out[] = $d['entry'];
        }
    }
    return $out;
}

const MEDIA_POST_ACTIONS = ['media-accept', 'media-send-back', 'media-hide', 'media-unhide'];

/** @return array{ok: bool, error: ?string, notify: ?string} */
function mediaReviewAction(PDO $pdo, string $action, int $driverId, int $reviewerId, string $note): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'notify' => null];
    $ok = fn(?string $notify = null): array => ['ok' => true, 'error' => null, 'notify' => $notify];
    if (!in_array($action, MEDIA_POST_ACTIONS, true)) return $fail('Unknown action.');
    $profile = db_get_media_profile($pdo, $driverId);
    if ($profile === null) return $fail('That profile no longer exists.');
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
            return $ok('sent_back');
        case 'media-hide':
            if ($note === '') return $fail('Add a reason for hiding it.');
            db_set_media_hidden($pdo, $driverId, $reviewerId, $note);
            return $ok('hidden');
        default:   // media-unhide
            db_set_media_hidden($pdo, $driverId, null, null);
            return $ok();
    }
}
