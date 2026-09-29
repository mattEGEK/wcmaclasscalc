<?php
// wcma-calculator/readiness-lib.php
//
// What a competitor still has to do for the events they tagged (spec §3), for both summer and ice
// events. buildReadiness() is pure: no DB, no HTML. loadReadinessInputs() gathers its input from
// the database. Callers must have loaded tech-status.php and gear-lib.php.
require_once __DIR__ . '/ice-sheet-lib.php';   // techSheetIsIce()
require_once __DIR__ . '/ice-rules.php';
require_once __DIR__ . '/ta-drift-lib.php';

function readinessItem(string $kind, string $subjectType, int $subjectId, string $state, string $label,
                       string $detail = '', ?array $action = null, ?array $atTrack = null): array {
    return ['kind' => $kind, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'state' => $state,
            'label' => $label, 'detail' => $detail, 'action' => $action, 'at_track' => $atTrack];
}

function readinessDeclaration(array $car, ?array $decl, int $season): array {
    $id = (int)$car['id'];
    $n = '#' . $car['car_number'];
    $redeclare = ['label' => 'Re-declare class', 'url' => 'calculator.php?car=' . $id];
    $inSeason = $decl !== null && (int)substr((string)$decl['submitted_at'], 0, 4) === $season;
    if ($decl !== null && $decl['review_status'] === 'needs_changes') {
        return readinessItem('declaration', 'car', $id, 'todo', "Your class declaration for $n needs changes", 'An inspector asked for changes.', $redeclare);
    }
    if (!$inSeason) {
        return readinessItem('declaration', 'car', $id, 'todo', "Declare class for $n", 'Declare once a season, or whenever the car changes.', ['label' => 'Declare class', 'url' => 'calculator.php?car=' . $id]);
    }
    if ($decl['review_status'] === 'accepted') {
        return readinessItem('declaration', 'car', $id, 'done', "$n class declared: " . $decl['calculated_class']);
    }
    return readinessItem('declaration', 'car', $id, 'info', "Class declaration for $n is with an inspector");
}

/**
 * Car tech or gear, which share one status model. $status is techCarStatus()/gearStatus() output.
 * $names: label (todo), doneLabel (sprintf with via word + season), atTrackLabel, retakeLabel.
 */
function readinessTech(string $kind, string $subjectType, int $subjectId, array $status, int $season,
                       bool $atTrack, array $names, ?string $photosUrl, ?string $retakeUrl, array $atTrackExtra = [],
                       ?array $detailOverride = null): array {
    switch ($status['state']) {
        case 'accepted':
            $via = ($status['via'] ?? 'in_person') === 'photos' ? 'pre-teched' : 'teched';
            return readinessItem($kind, $subjectType, $subjectId, 'done', sprintf($names['doneLabel'], $via, $season));
        case 'pending_review':
            return readinessItem($kind, $subjectType, $subjectId, 'info', $names['pendingLabel']);
        case 'needs_changes':
            return readinessItem($kind, $subjectType, $subjectId, 'todo', $names['retakeLabel'], 'An inspector asked for some photos to be retaken.',
                $retakeUrl !== null ? ['label' => 'Retake photos', 'url' => $retakeUrl] : null);
    }
    if ($atTrack) {
        return readinessItem($kind, $subjectType, $subjectId, 'done', $names['atTrackLabel']);
    }
    $detail = $photosUrl !== null
        ? ($detailOverride['with'] ?? 'Pre-tech with photos, or bring it to tech at the track.')
        : ($detailOverride['without'] ?? 'Pre-tech with photos after you submit the tech sheet, or bring it to tech at the track.');
    return readinessItem($kind, $subjectType, $subjectId, 'todo', $names['label'], $detail,
        $photosUrl !== null ? ['label' => 'Add photos', 'url' => $photosUrl] : null,
        ['subject_type' => $subjectType, 'subject_id' => $subjectId, 'season' => $season] + $atTrackExtra);
}

/**
 * The strictest ice class among $did's ice sheets in $season, across every car, club and event
 * (spec §4a: a shortfall against ANY of the driver's sheets that season is still a shortfall).
 * A caged-group class beats street_safe/drift; among caged classes, one that needs an FHR wins,
 * so the FHR rule is checked whenever any of the driver's sheets that season needs it.
 *
 * Only sheets for a live event ($liveEventIds, from buildReadiness(): active, dated today or
 * later, and tagged) and a car still in $cars (i.e. not archived) count. A stale sheet — a past,
 * deactivated, or untagged event, or an archived car — must not leave a to-do the competitor has
 * no way to clear.
 */
function readinessStrictestIceClass(array $sheets, int $did, int $season, array $liveEventIds, array $cars): ?array {
    $rank = fn(array $c): int => ($c['group'] === 'caged' ? 20 : 10) + ($c['fhr'] ? 1 : 0);
    $best = null;
    foreach ($sheets as $s) {
        if (!techSheetIsIce($s) || (int)($s['season'] ?? 0) !== $season || (int)($s['driver_id'] ?? 0) !== $did) continue;
        if (!isset($liveEventIds[(int)($s['event_id'] ?? 0)])) continue;
        if (!isset($cars[(int)($s['car_id'] ?? 0)])) continue;
        $class = iceClass((string)($s['club'] ?? ''), (string)($s['class'] ?? ''));
        if ($class === null) continue;
        if ($best === null || $rank($class) > $rank($best)) $best = $class;
    }
    return $best;
}

/**
 * The newest (by id) live ice sheet this season naming $did as its driver, restricted to the same
 * live events and cars as readinessStrictestIceClass() (spec §4a item 3). Used for the ice gear
 * "Add photos" link, so it isn't limited to the current event's own sheet.
 */
function readinessIceGearPhotosUrl(array $sheets, int $did, int $season, array $liveEventIds, array $cars): ?string {
    $best = null;
    foreach ($sheets as $s) {
        if (!techSheetIsIce($s) || (int)($s['season'] ?? 0) !== $season || (int)($s['driver_id'] ?? 0) !== $did) continue;
        if (!isset($liveEventIds[(int)($s['event_id'] ?? 0)])) continue;
        if (!isset($cars[(int)($s['car_id'] ?? 0)])) continue;
        $id = (int)$s['id'];
        if ($best === null || $id > $best) $best = $id;
    }
    return $best !== null ? 'gear.php?action=start-ice&sheet_id=' . $best : null;
}

/**
 * One ice gear item (spec §4, §4a). Accepted race-level summer gear from the season before
 * satisfies ice as caged gear (FHR included) and wins over any shortfall in an ice-season gear
 * record. Otherwise, accepted ice gear must cover the strictest class among the driver's ice
 * sheets this season (level, and a frontal head restraint when that class needs one).
 */
function readinessIceGear(int $did, string $name, int $season, ?array $iceGear, ?array $summerGear, ?array $class,
                          bool $fhrSeen, bool $atTrack, ?string $photosUrl): array {
    if (gearCoversTier($summerGear, TECH_TIER_RACE)) {   // race-level summer gear only (TA/Drift spec §2)
        return readinessItem('gear', 'driver', $did, 'done', "Ice gear for $name: from summer " . ($season - 1));
    }
    $iceStatus = $iceGear !== null ? gearStatus($iceGear) : ['state' => 'none', 'via' => null];
    if ($iceStatus['state'] === 'accepted') {
        $level = (string)($iceGear['level'] ?? '');
        $levelLabel = ICE_GEAR_LEVEL_LABELS[$level] ?? $level;
        if ($class !== null && !iceGearSatisfies($level !== '' ? $level : null, $class['group'])) {
            return readinessItem('gear', 'driver', $did, 'todo',
                "$name's gear is checked for $levelLabel; {$class['code']} needs caged-level gear",
                'Bring caged-level gear to tech at the track.', null);
        }
        if ($class !== null && $class['fhr'] && ($iceGear['accepted_via'] ?? '') === 'photos' && !$fhrSeen) {
            return readinessItem('gear', 'driver', $did, 'todo',
                "$name's gear needs a frontal head restraint checked for {$class['code']}",
                'Bring the frontal head restraint to tech at the track.', null);
        }
        $via = ($iceStatus['via'] ?? 'in_person') === 'photos' ? 'pre-teched' : 'teched';
        return readinessItem('gear', 'driver', $did, 'done', "Ice gear for $name: $via " . iceSeasonLabel($season) . ($levelLabel !== '' ? " · $levelLabel" : ''));
    }
    return readinessTech('gear', 'driver', $did, $iceStatus, $season, $atTrack, [
        'label' => "Ice gear for $name", 'pendingLabel' => "Ice gear photos for $name are with an inspector",
        'retakeLabel' => "Retake ice gear photos for $name", 'atTrackLabel' => "Ice gear for $name: checked at the track",
    ], $photosUrl, $iceGear !== null ? 'gear.php?action=pretech&id=' . (int)$iceGear['id'] : null,
       ['discipline' => DISCIPLINE_ICE, 'club' => ''],
       ['with' => 'Pre-tech with photos from your ice tech sheet, or bring it to tech at the track.',
        'without' => 'Bring it to tech at the track, or add photos once this driver is on an ice tech sheet.']);
}

/**
 * Ice to-dos for one car at one ice event: the ice tech sheet, ice car tech for the event's club
 * and season, and ice gear for each driver. $once de-duplicates season-wide items across events.
 */
function readinessIceCarItems(array $in, array $event, array $key, int $carId, array $sheetsByCar, array $atTrack, callable $once, array $liveEventIds): array {
    $eid = (int)$event['id'];
    $season = $key['season'];
    $club = (string)$key['club'];
    $car = $in['cars'][$carId];
    $n = '#' . $car['car_number'];
    $safeN = str_replace('%', '%%', $n);
    $items = [];

    $eventSheet = null;
    foreach ($sheetsByCar[$carId] ?? [] as $s) {
        if ((int)$s['event_id'] === $eid && techSheetIsIce($s)) { $eventSheet = $s; break; }
    }
    $items[] = $eventSheet !== null
        ? readinessItem('tech_sheet', 'car', $carId, 'done', 'Ice tech sheet for ' . $event['name'] . ' submitted')
        : readinessItem('tech_sheet', 'car', $carId, 'todo', "Submit an ice tech sheet for $n", 'Pick your class on the ice tech sheet.',
            ['label' => 'Submit ice tech sheet', 'url' => "tech-sheets.php?action=new-ice&car_id=$carId&event_id=$eid"]);

    if ($once("ice_car_tech:$club:$carId")) {
        $clubSheets = array_values(array_filter($sheetsByCar[$carId] ?? [], fn(array $s): bool =>
            techSheetIsIce($s) && ($s['club'] ?? '') === $club && (int)$s['season'] === $season));
        $status = techCarStatus($clubSheets);
        $ids = array_map(fn(array $s): int => (int)$s['id'], $clubSheets);
        $latest = $ids ? max($ids) : null;
        $items[] = readinessTech('car_tech', 'car', $carId, $status, $season, isset($atTrack[atTrackKey('car', $carId, $season, DISCIPLINE_ICE, $club)]), [
            'label' => "Ice car tech for $n at $club", 'doneLabel' => 'Ice car tech ' . iceSeasonLabel($season) . " for $safeN at $club: %1\$s",
            'pendingLabel' => "Ice car tech photos for $n are with an inspector", 'retakeLabel' => "Retake ice car photos for $n",
            'atTrackLabel' => "Ice car tech for $n: you'll bring it to tech at the track",
        ], $latest !== null ? 'tech-sheets.php?action=pretech&id=' . $latest : null,
           $status['sheet_id'] !== null ? 'tech-sheets.php?action=pretech&id=' . $status['sheet_id'] : null,
           ['discipline' => DISCIPLINE_ICE, 'club' => $club]);
    }

    $driverIds = array_values(array_unique(array_filter(array_merge(
        $eventSheet !== null ? [(int)($eventSheet['driver_id'] ?? 0)] : [], [(int)$in['selfDriverId']], array_map('intval', array_keys($in['drivers']))
    ))));
    foreach ($driverIds as $did) {
        if (!isset($in['drivers'][$did]) || !$once("ice_gear:$did")) continue;
        $iceGear = $in['iceGear']["$did:$season"] ?? null;
        $class = readinessStrictestIceClass($in['sheets'], $did, $season, $liveEventIds, $in['cars']);
        $photosUrl = readinessIceGearPhotosUrl($in['sheets'], $did, $season, $liveEventIds, $in['cars']);
        $items[] = readinessIceGear($did, (string)$in['drivers'][$did]['name'], $season, $iceGear,
            $in['gear']["$did:" . ($season - 1)] ?? null, $class,
            $iceGear !== null && !empty($in['iceGearFhr'][(int)$iceGear['id']]),
            isset($atTrack[atTrackKey('driver', $did, $season, DISCIPLINE_ICE)]),
            $photosUrl);
    }
    return $items;
}

/**
 * One car's entry at one event: its formats, the tech tier they need there (entryTierAtEvent()),
 * and when the supplementary-regulations box was ticked. $plan is its event_plans row, or null.
 * @return array{formats: string[], tier: string, supps_ack_at: ?string}
 */
function readinessEntry(array $event, ?array $plan): array {
    $stored = isset($plan['formats']) ? (string)$plan['formats'] : null;
    return ['formats' => entryFormatsParse($stored), 'tier' => entryTierAtEvent($event, $stored),
            'supps_ack_at' => $plan['supps_ack_at'] ?? null];
}

function buildReadiness(array $in): array {
    $in += ['iceGear' => [], 'iceGearFhr' => []];
    $today = (string)$in['today'];
    $upcoming = array_values(array_filter($in['events'], fn(array $e): bool => (string)$e['event_date'] >= $today));
    usort($upcoming, fn(array $a, array $b): int => strcmp((string)$a['event_date'], (string)$b['event_date']) ?: ((int)$a['id'] <=> (int)$b['id']));

    $carsByEvent = [];
    $plansByEvent = [];
    foreach ($in['plans'] as $p) {
        if (!isset($in['cars'][(int)$p['car_id']])) continue;
        $carsByEvent[(int)$p['event_id']][(int)$p['car_id']] = true;
        $plansByEvent[(int)$p['event_id']][(int)$p['car_id']] = $p;
    }
    $atTrack = array_flip($in['atTrack']);

    $sheetsByCar = [];
    foreach ($in['sheets'] as $s) $sheetsByCar[(int)$s['car_id']][] = $s;

    // Events the strictest-ice-class and gear-photo-link scans may draw sheets from: active,
    // dated today or later, and tagged (spec §4a item 1).
    $liveEventIds = [];
    foreach ($upcoming as $event) {
        if (!empty($carsByEvent[(int)$event['id']])) $liveEventIds[(int)$event['id']] = true;
    }

    $seen = [];
    $events = [];
    $untagged = [];
    foreach ($upcoming as $event) {
        $eid = (int)$event['id'];
        if (empty($carsByEvent[$eid])) { $untagged[] = $event; continue; }
        $key = seasonForEvent($event);
        $season = $key['season'];
        $items = [];
        $entries = [];

        $once = function (string $key) use (&$seen, $season): bool {
            $k = $key . '@' . $season;
            if (isset($seen[$k])) return false;
            return $seen[$k] = true;
        };

        foreach (array_keys($in['cars']) as $carId) {
            if (!isset($carsByEvent[$eid][$carId])) continue;
            $car = $in['cars'][$carId];
            $n = '#' . $car['car_number'];
            $entries[$carId] = readinessEntry($event, $plansByEvent[$eid][$carId] ?? null);

            if ($key['discipline'] === DISCIPLINE_ICE) {
                $items = array_merge($items, readinessIceCarItems($in, $event, $key, $carId, $sheetsByCar, $atTrack, $once, $liveEventIds));
                continue;
            }
            $safeN = str_replace('%', '%%', $n);

            $seasonSheets = array_values(array_filter($sheetsByCar[$carId] ?? [], fn(array $s): bool => (int)$s['season'] === $season && !techSheetIsIce($s)));
            $eventSheet = null;
            foreach ($sheetsByCar[$carId] ?? [] as $s) {
                if ((int)$s['event_id'] === $eid && !techSheetIsIce($s)) { $eventSheet = $s; break; }
            }

            if ($once("declaration:$carId")) {
                $items[] = readinessDeclaration($car, $in['declarations'][$carId] ?? null, $season);
            }

            $items[] = $eventSheet !== null
                ? readinessItem('tech_sheet', 'car', $carId, 'done', 'Tech sheet for ' . $event['name'] . ' submitted')
                : readinessItem('tech_sheet', 'car', $carId, 'todo', "Submit a tech sheet for $n", 'Every car needs a tech sheet for every event.',
                    ['label' => 'Submit tech sheet', 'url' => "tech-sheets.php?action=new&car_id=$carId&event_id=$eid"]);

            if ($once("car_tech:$carId")) {
                $status = techCarStatus($seasonSheets);
                $byId = $seasonSheets;
                usort($byId, fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
                $latest = $byId ? (int)end($byId)['id'] : null;
                $items[] = readinessTech('car_tech', 'car', $carId, $status, $season, isset($atTrack["car:$carId@$season"]), [
                    'label' => "Car tech for $n", 'doneLabel' => "Car tech $season for $safeN: %s", 'pendingLabel' => "Car tech photos for $n are with an inspector",
                    'retakeLabel' => "Retake photos for $n", 'atTrackLabel' => "Car tech for $n: you'll bring it to tech at the track",
                ], $latest !== null ? 'tech-sheets.php?action=pretech&id=' . $latest : null,
                   $status['sheet_id'] !== null ? 'tech-sheets.php?action=pretech&id=' . $status['sheet_id'] : null);
            }

            // Every driver on the profile needs this season's gear checked (spec §3), not only
            // those named on a tech sheet. The sheet's drivers and the self driver come first.
            $sheetDriverIds = $eventSheet !== null
                ? array_merge([(int)($eventSheet['driver_id'] ?? 0)], array_map('intval', $in['sheetDrivers'][(int)$eventSheet['id']] ?? []))
                : [];
            $driverIds = array_values(array_unique(array_filter(array_merge(
                $sheetDriverIds, [(int)$in['selfDriverId']], array_map('intval', array_keys($in['drivers']))
            ))));
            foreach ($driverIds as $did) {
                if (!isset($in['drivers'][$did]) || !$once("gear:$did")) continue;
                $name = (string)$in['drivers'][$did]['name'];
                $safeName = str_replace('%', '%%', $name);
                $gear = $in['gear']["$did:$season"] ?? null;
                if ($gear !== null && gearStatus($gear)['state'] === 'accepted' && !gearCoversTier($gear, TECH_TIER_RACE)) {
                    // Accepted at TA/Drift level only (TA/Drift spec §2): racing needs race-level gear.
                    $items[] = readinessItem('gear', 'driver', $did, 'todo', "$name's gear is checked for TA/Drift; racing needs race-level gear",
                        'Bring race-level gear to tech at the track.');
                    continue;
                }
                $status = $gear !== null ? gearStatus($gear) : ['state' => 'none', 'via' => null];
                $items[] = readinessTech('gear', 'driver', $did, $status, $season, isset($atTrack["driver:$did@$season"]), [
                    'label' => "Gear for $name", 'doneLabel' => "Gear for $safeName: %s $season", 'pendingLabel' => "Gear photos for $name are with an inspector",
                    'retakeLabel' => "Retake gear photos for $name", 'atTrackLabel' => "Gear for $name: checked at the track",
                ], 'gear.php?action=start&driver_id=' . $did, $gear !== null ? 'gear.php?action=pretech&id=' . (int)$gear['id'] : null);
            }
        }
        $events[] = ['event' => $event, 'items' => $items, 'entries' => $entries];
    }
    return ['events' => $events, 'untagged' => $untagged];
}

/** Gathers buildReadiness() input for one user from the database. Callers must have loaded db.php. */
function loadReadinessInputs(PDO $pdo, int $userId, string $today): array {
    $cars = [];
    foreach (db_get_user_cars($pdo, $userId) as $c) $cars[(int)$c['id']] = $c;

    $sheets = db_get_user_tech_sheets($pdo, $userId);
    $sheetDrivers = [];
    foreach (db_get_drivers_for_sheets($pdo, array_map(fn(array $s): int => (int)$s['id'], $sheets)) as $sheetId => $rows) {
        $sheetDrivers[(int)$sheetId] = array_values(array_filter(array_map(fn(array $r): int => (int)($r['driver_id'] ?? 0), $rows)));
    }

    $drivers = [];
    foreach (db_get_user_drivers($pdo, $userId) as $d) $drivers[(int)$d['id']] = $d;
    $self = db_get_self_driver($pdo, $userId);

    $events = db_get_active_events($pdo);
    $summerSeasons = [];
    $iceSeasons = [];
    foreach ($events as $e) {
        $key = seasonForEvent($e);
        if ($key['discipline'] === DISCIPLINE_ICE) {
            $iceSeasons[$key['season']] = true;
            $summerSeasons[$key['season'] - 1] = true;   // summer gear carries over to the next ice season
        } else {
            $summerSeasons[$key['season']] = true;
        }
    }
    $gear = [];
    $iceGear = [];
    $iceGearFhr = [];
    foreach (array_keys($drivers) as $did) {
        foreach (array_keys($summerSeasons) as $season) {
            $g = db_get_gear_record_for_driver($pdo, $did, $season);
            if ($g !== null) $gear["$did:$season"] = $g;
        }
        foreach (array_keys($iceSeasons) as $season) {
            $g = db_get_gear_record_for_driver($pdo, $did, $season, DISCIPLINE_ICE);
            if ($g === null) continue;
            $iceGear["$did:$season"] = $g;
            if (($g['accepted_via'] ?? null) === 'photos') {
                $fhr = db_get_inspection_photos($pdo, 'gear_record', (int)$g['id'])['ice_fhr_label'] ?? null;
                $iceGearFhr[(int)$g['id']] = $fhr !== null && ($fhr['file_path'] ?? '') !== '';
            }
        }
    }

    $atTrack = [];
    foreach (array_keys($summerSeasons) as $season) {
        $atTrack = array_merge($atTrack, db_get_at_track_keys($pdo, array_keys($cars), array_keys($drivers), $season));
    }
    foreach (array_keys($iceSeasons) as $season) {
        $atTrack = array_merge($atTrack, db_get_at_track_keys($pdo, array_keys($cars), array_keys($drivers), $season, DISCIPLINE_ICE));
    }

    return [
        'today' => $today,
        'cars' => $cars,
        'events' => $events,
        'plans' => array_map(fn(array $p): array => ['event_id' => (int)$p['event_id'], 'car_id' => (int)$p['car_id'],
            'formats' => (string)($p['formats'] ?? 'race'), 'supps_ack_at' => $p['supps_ack_at'] ?? null], db_get_user_event_plans($pdo, $userId)),
        'declarations' => db_get_user_current_declarations($pdo, $userId),
        'sheets' => $sheets,
        'sheetDrivers' => $sheetDrivers,
        'drivers' => $drivers,
        'selfDriverId' => $self !== null ? (int)$self['id'] : 0,
        'gear' => $gear,
        'iceGear' => $iceGear,
        'iceGearFhr' => $iceGearFhr,
        'atTrack' => array_values(array_unique($atTrack)),
    ];
}
