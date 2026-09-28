<?php
// wcma-calculator/garage-lib.php
//
// Pure view-model builders for the Garage (spec §4): no DB, no HTML. Callers must have loaded
// tech-status.php (techCarStatus(), techCarStatusLabel()).
require_once __DIR__ . '/ice-sheet-lib.php';   // techSheetIsIce()
require_once __DIR__ . '/tech-status.php';     // techCarStatus(), techCarStatusLabel()

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

/** One Garage list card: the car, its summer class and tech (if it races summer), its ice chip (if it races ice), and its nearest tagged event. */
function garageCard(array $car, array $declarations, array $carSheets, array $taggedEventIds, array $activeEvents, int $season, string $today, int $iceSeason = 0): array {
    $summerSheets = garageSummerSheets($carSheets);
    $seasonSheets = array_values(array_filter($summerSheets, fn(array $s): bool => (int)$s['season'] === $season));
    $tech = techCarStatus($seasonSheets);
    $tagged = array_flip(array_map('intval', $taggedEventIds));
    $taggedIce = $taggedSummer = false;
    foreach ($activeEvents as $e) {
        if (!isset($tagged[(int)$e['id']]) || (string)$e['event_date'] < $today) continue;
        if (($e['discipline'] ?? 'summer') === 'ice') $taggedIce = true; else $taggedSummer = true;
    }
    $events = garageCarEvents($carSheets, $taggedEventIds, $activeEvents, [], $today);
    return [
        'car' => $car,
        'class' => garageClassLine($declarations),
        'usesSummer' => garageCarUsesSummer($declarations, $carSheets, $taggedSummer),
        'ice' => garageIceSummary($carSheets, $taggedIce, $iceSeason ?: $season),
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
 * to an ice event). The label carries the newest sheet's club and class.
 * @return ?array{state: string, label: string}
 */
function garageIceSummary(array $carSheets, bool $taggedToIce, int $iceSeason): ?array {
    $ice = array_values(array_filter($carSheets, fn(array $s): bool => techSheetIsIce($s)));
    if (!$ice && !$taggedToIce) return null;
    $season = array_values(array_filter($ice, fn(array $s): bool => (int)$s['season'] === $iceSeason));
    if (!$season) return ['state' => 'none', 'label' => 'Needs ice tech'];
    usort($season, fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
    $newest = end($season);
    $status = techCarStatus($season);
    return ['state' => $status['state'],
            'label' => techCarStatusLabel($status, $iceSeason, DISCIPLINE_ICE) . ' · ' . $newest['club'] . ' · ' . $newest['class']];
}

/** Whether the car takes part in summer: declared, summer sheets, tagged to summer, or no ice sheets at all. */
function garageCarUsesSummer(array $declarations, array $carSheets, bool $taggedToSummer): bool {
    if ($declarations || $taggedToSummer) return true;
    $hasIce = false;
    foreach ($carSheets as $s) {
        if (!techSheetIsIce($s)) return true;
        $hasIce = true;
    }
    return !$hasIce;
}
