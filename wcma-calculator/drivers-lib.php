<?php
// wcma-calculator/drivers-lib.php
//
// Driver profiles for the Drivers page (spec §4): co-drivers, licence numbers, each driver's
// gear status for the season, and each driver's media profile status (spec 2026-09-27 §3). The
// profile carries over year to year; the gear check does not.
// Callers must have loaded db.php, gear-lib.php, ice-sheet-lib.php and media-lib.php.
require_once __DIR__ . '/ice-sheet-lib.php';   // techSheetIsIce()

/** From January 1 every driver shows "Needs gear tech {season}" until there is gear activity. */
function driversGearLabel(array $status, int $season): string {
    return $status['state'] === 'none' ? 'Needs gear tech ' . $season : gearStatusLabel($status, $season);
}

/** Every state opens this season's gear photos; gear.php?action=start creates the record on first use. */
function driversGearAction(int $driverId, array $status): array {
    $labels = ['accepted' => 'View gear', 'pending_review' => 'View gear photos', 'needs_changes' => 'Retake gear photos', 'photos_draft' => 'Continue gear photos'];
    return ['label' => $labels[$status['state']] ?? 'Add gear photos', 'url' => 'gear.php?action=start&driver_id=' . $driverId];
}

/**
 * @param array $gear driver id => that driver's gear_records row for $season
 * @param array $media driver id => db_get_media_bundle() entry
 * @param array $ice driver id => array{state, label, gearId, sheetId, driverNumber?} (driverNumber
 *                   defaults to 1, the sheet's primary driver)
 */
function driversRows(array $drivers, array $gear, int $selfId, int $season, array $media = [], array $ice = [], bool $userUsesSummer = true): array {
    $rows = [];
    foreach ($drivers as $d) {
        $id = (int)$d['id'];
        $status = isset($gear[$id]) ? gearStatus($gear[$id]) : ['state' => 'none', 'via' => null];
        $note = trim((string)($gear[$id]['revoke_note'] ?? ''));
        $row = ['driver' => $d, 'isSelf' => $id === $selfId, 'state' => $status['state'],
                'label' => driversGearLabel($status, $season)
                    . ($status['state'] === 'accepted' && ($gear[$id]['level'] ?? null) === GEAR_LEVEL_TA_DRIFT ? ' · ' . gearSummerLevelLabel(GEAR_LEVEL_TA_DRIFT) : ''),
                'action' => driversGearAction($id, $status),
                'revokeNote' => $status['state'] !== 'accepted' && $note !== '' ? $note : null,
                'media' => mediaProfileStatus($media[$id]['profile'] ?? null, $media[$id]['consent'] ?? null),
                'summer' => driverShowsSummerGear(isset($ice[$id]), $userUsesSummer, isset($gear[$id]))];
        if (isset($ice[$id])) {
            $i = $ice[$id];
            $action = null;
            if ($i['gearId'] !== null) $action = ['label' => 'View ice gear', 'url' => 'gear.php?action=pretech&id=' . (int)$i['gearId']];
            elseif ($i['sheetId'] !== null && $i['state'] !== 'accepted') {
                $num = (int)($i['driverNumber'] ?? 1);
                $action = ['label' => 'Add ice gear photos',
                           'url' => 'gear.php?action=start-ice&sheet_id=' . (int)$i['sheetId'] . ($num >= 2 ? '&driver=' . $num : '')];
            }
            $row['ice'] = ['state' => $i['state'], 'label' => $i['label'], 'action' => $action, 'revokeNote' => $i['revokeNote'] ?? null];
        }
        $rows[] = $row;
    }
    return $rows;
}

/**
 * The newest current-ice-season tech sheet id naming each driver — as the sheet's primary driver
 * (driver_id) or as an added driver (a tech_sheet_drivers row, per readiness-lib.php's
 * $sheetDrivers pattern) — for the "Add ice gear photos" action on the Drivers page.
 *
 * @param array $sheets tech_sheets rows; non-ice rows and rows for another season are ignored
 * @param array $sheetDrivers sheet id => tech_sheet_drivers rows (driver_id, driver_number), as
 *                            db_get_drivers_for_sheets() returns them
 * @return array<int, array{sheetId: int, driverNumber: int}> driver id => newest sheet id and the
 *         driver's number on it (1 = the sheet's primary driver)
 */
function driversIceSheetIdsByDriver(array $sheets, array $sheetDrivers, int $iceSeason): array {
    $byDriver = [];
    foreach ($sheets as $s) {
        if (!techSheetIsIce($s) || (int)($s['season'] ?? 0) !== $iceSeason) continue;
        $sid = (int)$s['id'];
        $onSheet = [];   // driver id => driver number; the primary driver wins over a duplicate added row
        foreach ($sheetDrivers[$sid] ?? [] as $r) $onSheet[(int)($r['driver_id'] ?? 0)] = (int)$r['driver_number'];
        $onSheet[(int)($s['driver_id'] ?? 0)] = 1;
        foreach ($onSheet as $did => $num) {
            if ($did <= 0) continue;
            if (!isset($byDriver[$did]) || $sid > $byDriver[$did]['sheetId']) $byDriver[$did] = ['sheetId' => $sid, 'driverNumber' => $num];
        }
    }
    return $byDriver;
}

/** Adds a co-driver the owner manages. @return array{ok: bool, error: ?string, id: ?int} */
function driversAdd(PDO $pdo, int $ownerId, string $name, string $licence): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'id' => null];
    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    $licence = trim($licence);
    if ($name === '') return $fail("Enter the driver's name.");
    if (mb_strlen($name, 'UTF-8') > 100) return $fail('That name is too long (100 characters at most).');
    if (mb_strlen($licence, 'UTF-8') > 40) return $fail('That licence number is too long (40 characters at most).');
    $existing = db_find_driver($pdo, $ownerId, $name);
    if ($existing !== null) return $fail($existing['name'] . ' is already on your Drivers page.');
    try {
        $id = db_create_driver($pdo, $ownerId, $name, $licence === '' ? null : $licence);
    } catch (PDOException $e) {
        return $fail($name . ' is already on your Drivers page.');   // lost a race with a duplicate request
    }
    return ['ok' => true, 'error' => null, 'id' => $id];
}

/** Sets or clears the licence number on one of the owner's drivers. @return array{ok: bool, error: ?string} */
function driversSetLicence(PDO $pdo, int $ownerId, int $driverId, string $licence): array {
    $driver = db_get_driver($pdo, $driverId);
    if ($driver === null || (int)$driver['owner_user_id'] !== $ownerId) return ['ok' => false, 'error' => 'Driver not found.'];
    $licence = trim($licence);
    if (mb_strlen($licence, 'UTF-8') > 40) return ['ok' => false, 'error' => 'That licence number is too long (40 characters at most).'];
    db_update_driver_licence($pdo, $driverId, $licence === '' ? null : $licence);
    return ['ok' => true, 'error' => null];
}
