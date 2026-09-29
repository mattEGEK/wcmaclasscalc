<?php
// wcma-calculator/garage-lib.php
//
// Pure view-model builders for the Garage (spec §4): no DB, no HTML. Callers must have loaded
// tech-status.php (techCarStatus(), techCarStatusLabel()).
require_once __DIR__ . '/ice-sheet-lib.php';   // techSheetIsIce()
require_once __DIR__ . '/tech-status.php';     // techCarStatus(), techCarStatusLabel()
require_once __DIR__ . '/ta-drift-lib.php';

/**
 * A car's stored season (mobile UX spec 2026-09-28 §A1). Null on cars added before it existed.
 * 'ta_drift' is a summer car that only runs Time Attack and Drift (TA/Drift spec §2): no class declaration.
 */
const CAR_DISCIPLINES = ['ice', 'summer', 'both', 'ta_drift'];

/**
 * Which seasons a car races. A stored season wins, but activity is never hidden: a declaration,
 * summer sheet or summer tag keeps summer on; an ice sheet or ice tag keeps ice on. With nothing
 * stored, today's inference: summer unless the car only has ice activity.
 * @return array{summer: bool, ice: bool}
 */
function carSeasons(?string $stored, bool $summerActivity, bool $iceActivity): array {
    $s = in_array($stored, CAR_DISCIPLINES, true) ? $stored : null;
    if ($s === null) return ['summer' => $summerActivity || !$iceActivity, 'ice' => $iceActivity];
    return ['summer' => $s !== 'ice' || $summerActivity, 'ice' => in_array($s, ['ice', 'both'], true) || $iceActivity];
}

/** carSeasons() for one car, from its declarations, sheets and upcoming event tags. @return array{summer: bool, ice: bool} */
function garageCarSeasons(array $car, array $declarations, array $carSheets, bool $taggedSummer, bool $taggedIce): array {
    $summerSheet = $iceSheet = false;
    foreach ($carSheets as $s) {
        if (techSheetIsIce($s)) $iceSheet = true; else $summerSheet = true;
    }
    return carSeasons(isset($car['disciplines']) ? (string)$car['disciplines'] : null,
        (bool)$declarations || $summerSheet || $taggedSummer, $iceSheet || $taggedIce);
}

/**
 * A car's class (spec §2): its newest non-superseded declaration. When that one isn't accepted,
 * also the newest earlier declaration that was (accepted_at survives superseding).
 *
 * @param array $declarations one car's submissions rows, newest first
 * @return array{current: ?array, earlierAccepted: ?array}
 */
function garageClassLine(array $declarations): array {
    $current = null;
    foreach ($declarations as $d) {
        if ($d['review_status'] !== 'superseded') { $current = $d; break; }
    }
    $earlier = null;
    if ($current === null || $current['review_status'] !== 'accepted') {
        foreach ($declarations as $d) {
            if ($current !== null && (int)$d['id'] === (int)$current['id']) continue;
            if (!empty($d['accepted_at'])) { $earlier = $d; break; }
        }
    }
    return ['current' => $current, 'earlierAccepted' => $earlier];
}

/** The event a car is being added for (Home's "Add a car for this event"), or null if it can't be tagged. */
function garageAddEvent(?array $event, string $today): ?array {
    if ($event === null || (int)($event['active'] ?? 0) !== 1 || (string)($event['event_date'] ?? '') < $today) return null;
    return $event;
}

/**
 * Where Add a car goes next (mobile UX spec 2026-09-28 §A2). $event is the event it was added for
 * and has already been tagged, or null.
 * @return array{url: string, flash: string}
 */
function garageAfterAdd(int $carId, string $disciplines, ?array $event): array {
    $car = 'garage.php?car=' . $carId;
    if ($disciplines === 'ta_drift') {
        // A TA/Drift-only car (TA/Drift spec §4): no class to declare. Its entry defaulted to Time
        // Attack when the event has a host club, so the TA/Drift sheet is next.
        if ($event !== null && entryTierAtEvent($event, 'ta') === TECH_TIER_TA_DRIFT) {
            return ['url' => garageTaDriftSheetUrl($carId, (int)$event['id']),
                    'flash' => 'Car added and going to ' . $event['name'] . '. Next, the TA/Drift tech sheet.'];
        }
        if ($event !== null) return ['url' => $car, 'flash' => 'Car added and going to ' . $event['name'] . '.'];
        return ['url' => $car . '#events', 'flash' => 'Car added. Which event is it going to first?'];
    }
    if ($event !== null && ($event['discipline'] ?? 'summer') === 'ice') {
        return ['url' => 'tech-sheets.php?action=new-ice&car_id=' . $carId . '&event_id=' . (int)$event['id'],
                'flash' => 'Car added and going to ' . $event['name'] . '. Next, the ice tech sheet.'];
    }
    if ($event !== null) return ['url' => $car, 'flash' => 'Car added and going to ' . $event['name'] . '. Next, declare its class.'];
    if ($disciplines === 'ice') return ['url' => $car, 'flash' => 'Car added. Which ice event is it going to first?'];
    if ($disciplines === 'both') return ['url' => $car, 'flash' => 'Car added. Declare its class for summer, and pick an ice event below.'];
    return ['url' => $car, 'flash' => 'Car added. Next, declare its class.'];
}

/** The TA/Drift tech sheet form for a car at an event (plan 2's route). */
function garageTaDriftSheetUrl(int $carId, int $eventId): string {
    return 'tech-sheets.php?action=new-ta-drift&car_id=' . $carId . '&event_id=' . $eventId;
}

/** The race sheets among $sheets: summer sheets that are neither ice nor TA/Drift. Race car tech counts only these. */
function garageRaceSheets(array $sheets): array {
    return array_values(array_filter($sheets, fn(array $s): bool => !techSheetIsIce($s) && !techSheetIsTaDrift($s)));
}

/**
 * What a car's upcoming summer entries need (entryTierAtEvent()): whether any is race, and the host
 * clubs of those that are TA/Drift. $formatsByEvent: event id => the entry's stored formats.
 * @return array{race: bool, taDriftClubs: string[]}
 */
function garageEntryTiers(array $formatsByEvent, array $activeEvents, string $today): array {
    $race = false;
    $clubs = [];
    foreach ($activeEvents as $e) {
        $eid = (int)$e['id'];
        if (!array_key_exists($eid, $formatsByEvent) || (string)$e['event_date'] < $today || ($e['discipline'] ?? 'summer') === 'ice') continue;
        if (entryTierAtEvent($e, $formatsByEvent[$eid]) === TECH_TIER_RACE) { $race = true; continue; }
        $clubs[trim((string)$e['host_club'])] = true;
    }
    $clubs = array_keys($clubs);
    sort($clubs);
    return ['race' => $race, 'taDriftClubs' => $clubs];
}

/**
 * Whether the car needs a class and race tech: every summer car, except a TA/Drift-only car with no
 * declaration, no race sheet and no upcoming race entry (TA/Drift spec §2 cars).
 */
function garageCarRaces(array $car, array $declarations, array $carSheets, bool $taggedRace): bool {
    if (($car['disciplines'] ?? null) !== 'ta_drift' || $declarations || $taggedRace) return true;
    return garageRaceSheets($carSheets) !== [];
}

/** The events a car can be added to: only the seasons it races (garageCarSeasons()). */
function garageEventsForSeasons(array $events, array $seasons): array {
    return array_values(array_filter($events, fn(array $e): bool =>
        (($e['discipline'] ?? 'summer') === 'ice') ? $seasons['ice'] : $seasons['summer']));
}

/**
 * The events the car page offers to add the car to. Only a car with a stored season is filtered:
 * a car from before seasons were stored keeps every event, as before (mobile UX spec §A1).
 */
function garageAddableEvents(array $events, array $car, array $seasons): array {
    return in_array($car['disciplines'] ?? null, CAR_DISCIPLINES, true) ? garageEventsForSeasons($events, $seasons) : $events;
}

/**
 * Whether $event (an ice event) already has this car's ice tech: an ice sheet for the event's club
 * in its ice season or later. Ice tech is one sheet per car, club and ice season (ice spec §4).
 */
function garageIceEventCovered(array $event, array $carSheets): bool {
    $key = seasonForEvent($event);
    foreach ($carSheets as $s) {
        if (techSheetIsIce($s) && (string)($s['club'] ?? '') === (string)$key['club'] && (int)($s['season'] ?? 0) >= $key['season']) return true;
    }
    return false;
}

/** Where tagging a car goes: straight to the ice sheet when the ice next-step card asked for it. */
function garageAfterTagUrl(int $carId, array $event, bool $wantsSheet): string {
    if ($wantsSheet && ($event['discipline'] ?? 'summer') === 'ice') {
        return 'tech-sheets.php?action=new-ice&car_id=' . $carId . '&event_id=' . (int)$event['id'];
    }
    return 'garage.php?car=' . $carId;
}

/**
 * The car page's Events section.
 * - tagged: active events on or after $today that this car is tagged for, soonest first, each with
 *   this car's sheet for it (or null).
 * - untagged: active events on or after $today it is not tagged for ("Bring this car to another event").
 * - earlierSheets: this car's sheets for any event not in `tagged`, newest first.
 *
 * @param array $eventNames event id => name, for every event (db_get_all_events())
 */
function garageCarEvents(array $carSheets, array $taggedEventIds, array $activeEvents, array $eventNames, string $today): array {
    $isTagged = array_flip(array_map('intval', $taggedEventIds));
    $upcoming = array_values(array_filter($activeEvents, fn(array $e): bool => (string)$e['event_date'] >= $today));
    usort($upcoming, fn(array $a, array $b): int => strcmp((string)$a['event_date'], (string)$b['event_date']) ?: ((int)$a['id'] <=> (int)$b['id']));

    $sheetByEvent = [];
    foreach ($carSheets as $s) {
        $eid = (int)$s['event_id'];
        if (!isset($sheetByEvent[$eid]) || (int)$s['id'] > (int)$sheetByEvent[$eid]['id']) $sheetByEvent[$eid] = $s;
    }

    $tagged = [];
    $untagged = [];
    foreach ($upcoming as $e) {
        if (isset($isTagged[(int)$e['id']])) {
            $tagged[] = ['event' => $e, 'sheet' => $sheetByEvent[(int)$e['id']] ?? null];
        } else {
            $untagged[] = $e;
        }
    }

    $shown = [];
    foreach ($tagged as $row) {
        if ($row['sheet'] !== null) $shown[(int)$row['sheet']['id']] = true;
    }
    $earlier = [];
    foreach ($carSheets as $s) {
        if (isset($shown[(int)$s['id']])) continue;
        $earlier[] = ['sheet' => $s, 'event_name' => (string)($eventNames[(int)$s['event_id']] ?? 'Event')];
    }
    usort($earlier, fn(array $a, array $b): int => (int)$b['sheet']['id'] <=> (int)$a['sheet']['id']);

    return ['tagged' => $tagged, 'untagged' => $untagged, 'earlierSheets' => $earlier];
}

/**
 * One Garage list card: the car, its summer class and race tech (if it races), its ice chip (if it
 * races ice), and its nearest tagged event. $formatsByEvent: event id => the entry's stored formats;
 * tagged events missing from it read as race.
 */
function garageCard(array $car, array $declarations, array $carSheets, array $taggedEventIds, array $activeEvents, int $season, string $today, int $iceSeason, array $formatsByEvent = []): array {
    $raceSheets = garageRaceSheets($carSheets);
    $seasonSheets = array_values(array_filter($raceSheets, fn(array $s): bool => (int)$s['season'] === $season));
    $tech = techCarStatus($seasonSheets);
    $tagged = array_flip(array_map('intval', $taggedEventIds));
    $taggedIce = $taggedSummer = false;
    foreach ($activeEvents as $e) {
        if (!isset($tagged[(int)$e['id']]) || (string)$e['event_date'] < $today) continue;
        if (($e['discipline'] ?? 'summer') === 'ice') $taggedIce = true; else $taggedSummer = true;
    }
    $formatsByEvent += array_fill_keys(array_map('intval', $taggedEventIds), 'race');
    $tiers = garageEntryTiers($formatsByEvent, $activeEvents, $today);
    $events = garageCarEvents($carSheets, $taggedEventIds, $activeEvents, [], $today);
    $stored = isset($car['disciplines']) ? (string)$car['disciplines'] : null;
    $seasons = garageCarSeasons($car, $declarations, $carSheets, $taggedSummer, $taggedIce);
    return [
        'car' => $car,
        'class' => garageClassLine($declarations),
        'seasons' => $seasons,
        'usesSummer' => $seasons['summer'],
        'usesRace' => $seasons['summer'] && garageCarRaces($car, $declarations, $carSheets, $tiers['race']),
        'ice' => garageIceSummary($carSheets, $taggedIce, $iceSeason, $stored),
        'techState' => $tech['state'],
        'techLabel' => techCarStatusLabel($tech, $season),
        'next' => $events['tagged'][0] ?? null,
    ];
}

/**
 * The car tech photo button. Photos hang off a tech sheet (the existing pre-tech flow), so there
 * is nothing to open until the car has a sheet this season.
 *
 * @param array $seasonSheets this car's sheets for the season
 * @param array $status       techCarStatus() of those sheets
 */
function garageTechPhotosAction(array $seasonSheets, array $status): ?array {
    $url = fn(int $id): string => 'tech-sheets.php?action=pretech&id=' . $id;
    switch ($status['state']) {
        case 'accepted':       return null;
        case 'needs_changes':  return ['label' => 'Retake photos', 'url' => $url((int)$status['sheet_id'])];
        case 'pending_review': return ['label' => 'View photos', 'url' => $url((int)$status['sheet_id'])];
    }
    if (!$seasonSheets) return null;
    $latest = max(array_map(fn(array $s): int => (int)$s['id'], $seasonSheets));
    return ['label' => $status['state'] === 'photos_draft' ? 'Continue photos' : 'Add photos', 'url' => $url($latest)];
}

/** The summer sheets among $sheets: summer car tech, events and history never count ice sheets. */
function garageSummerSheets(array $sheets): array {
    return array_values(array_filter($sheets, fn(array $s): bool => !techSheetIsIce($s)));
}

/**
 * The car's ice chip for $iceSeason, or null when it isn't an ice car (no ice sheets, not tagged
 * to an ice event). The label carries the newest sheet's club and class, and the status counts only
 * that club's sheets: ice tech is keyed by car, club and season (spec §4).
 * @return ?array{state: string, label: string}
 */
function garageIceSummary(array $carSheets, bool $taggedToIce, int $iceSeason, ?string $stored = null): ?array {
    $ice = array_values(array_filter($carSheets, fn(array $s): bool => techSheetIsIce($s)));
    // A 'both' car shows ice tech once it has ice activity; an 'ice' car always (spec 2026-09-29 §2.2).
    if (!$ice && !$taggedToIce && $stored !== 'ice') return null;
    // This season or a later one: a sheet sent before the July rollover for next winter's event counts.
    $current = array_values(array_filter($ice, fn(array $s): bool => (int)$s['season'] >= $iceSeason));
    if (!$current) return ['state' => 'none', 'label' => 'Needs ice tech'];
    $showSeason = max(array_map(fn(array $s): int => (int)$s['season'], $current));
    $season = array_values(array_filter($current, fn(array $s): bool => (int)$s['season'] === $showSeason));
    usort($season, fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
    $newest = end($season);
    $status = techCarStatus(array_values(array_filter($season, fn(array $s): bool => (string)$s['club'] === (string)$newest['club'])));
    return ['state' => $status['state'],
            'label' => techCarStatusLabel($status, $showSeason, DISCIPLINE_ICE) . ' · ' . $newest['club'] . ' · ' . $newest['class']];
}

/**
 * Whether the car takes part in summer: declared, summer sheets, tagged to summer, stored as a
 * summer car, or (nothing stored) neither ice sheets nor an ice tag. See garageCarSeasons().
 */
function garageCarUsesSummer(array $declarations, array $carSheets, bool $taggedToSummer, bool $taggedToIce = false, ?string $stored = null): bool {
    return garageCarSeasons(['disciplines' => $stored], $declarations, $carSheets, $taggedToSummer, $taggedToIce)['summer'];
}

/**
 * Whether the user has any ice activity: an ice sheet, an ice gear record, a car tagged to an
 * active ice event, or a car stored as ice only (a 'both' car counts once it has ice activity). Home and the Drivers page both use this so they agree.
 *
 * @param array $plans        event_plans rows (event_id, car_id)
 * @param array $activeEvents the active events (with discipline)
 */
/**
 * Whether the user races summer at all: no cars yet (new users default to summer), or any car that
 * uses summer (garageCarUsesSummer, with its upcoming active-event tags). Home and Drivers share it.
 */
function userUsesSummer(array $cars, array $sheets, array $declarationsByCar, array $plans, array $activeEvents, string $today): bool {
    if (!$cars) return true;
    $eventsById = [];
    foreach ($activeEvents as $e) $eventsById[(int)$e['id']] = $e;
    $tags = [];
    foreach ($plans as $p) {
        $e = $eventsById[(int)$p['event_id']] ?? null;
        if ($e === null || (string)$e['event_date'] < $today) continue;
        $tags[(int)$p['car_id']][(($e['discipline'] ?? 'summer') === 'ice') ? 'ice' : 'summer'] = true;
    }
    foreach (array_keys($cars) as $carId) {
        $carId = (int)$carId;
        $carSheets = array_values(array_filter($sheets, fn(array $s): bool => (int)$s['car_id'] === $carId));
        $decl = $declarationsByCar[$carId] ?? null;
        if (garageCarUsesSummer($decl !== null ? [$decl] : [], $carSheets, isset($tags[$carId]['summer']), isset($tags[$carId]['ice']),
                isset($cars[$carId]['disciplines']) ? (string)$cars[$carId]['disciplines'] : null)) return true;
    }
    return false;
}

/**
 * Whether a driver's summer gear chip shows (spec §4a: one chip per discipline in play). Always for
 * users with no ice activity; otherwise when the user races summer or the driver already has
 * summer gear this season. Ice-only competitors don't get a summer "Needs gear tech" prompt.
 */
function driverShowsSummerGear(bool $userHasIce, bool $userUsesSummer, bool $hasSummerGear): bool {
    return !$userHasIce || $userUsesSummer || $hasSummerGear;
}

function userHasIceActivity(array $sheets, bool $hasIceGear, array $plans, array $activeEvents, array $cars = []): bool {
    if ($hasIceGear) return true;
    foreach ($sheets as $s) {
        if (techSheetIsIce($s)) return true;
    }
    $ice = [];
    foreach ($activeEvents as $e) {
        if (($e['discipline'] ?? 'summer') === 'ice') $ice[(int)$e['id']] = true;
    }
    foreach ($plans as $p) {
        if (isset($ice[(int)$p['event_id']])) return true;
    }
    foreach ($cars as $car) {
        if (($car['disciplines'] ?? null) === 'ice') return true;
    }
    return false;
}
