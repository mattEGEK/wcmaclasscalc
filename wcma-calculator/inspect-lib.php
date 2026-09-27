<?php
// wcma-calculator/inspect-lib.php
//
// Pure view models for the Inspector section (spec §5): the event roster, the review queue and the
// Classing filters. No DB, no HTML. Callers must have loaded tech-status.php, gear-lib.php
// (gearLinksForSheet()) and garage-lib.php (garageClassLine()).
require_once __DIR__ . '/ice-sheet-lib.php';

const INSPECT_ROSTER_FILTERS = [
    'all' => 'All cars',
    'needs_decals' => 'Needs decals (car tech accepted)',
    'needs_tech' => 'Needs tech at the track (car or gear)',
    'no_sheet' => 'No tech sheet yet',
    'class_not_accepted' => 'Class not accepted',
];

const INSPECT_CLASSES = ['GTU', 'GT1', 'GT2', 'GT3', 'GT4', 'IT1', 'IT2'];
const INSPECT_DECLARATION_STATUSES = ['submitted', 'needs_changes', 'accepted', 'superseded'];

/**
 * One roster row per car on an event: tagged for it, or with a sheet for it (spec §5, "Event roster").
 * Drivers come from the car's sheet for the event, or from the owner's self profile when there is
 * no sheet yet (the same rule as Home, spec §3).
 *
 * @param array $cars          db_get_event_roster_cars() rows
 * @param array $eventSheets   the event's sheets (db_get_event_tech_sheets())
 * @param array $seasonSheets  every sheet in the event's season: a car's other sheets decide its car tech
 * @param array $declarations  car id => that car's declarations, newest first (db_get_declarations_for_cars())
 * @param array $sheetDrivers  sheet id => additional driver rows (db_get_drivers_for_sheets())
 * @param array $selfDrivers   owner user id => self driver row (db_get_self_drivers_for_users())
 * @param array $seasonGear    every gear record in the season (db_get_gear_records_for_season())
 * @param array $key           discipline/club identity for this roster (seasonForEvent()'s shape)
 * @return array<int, array{car: array, sheet: ?array, class: array, status: array, gear_links: array, ice_class: string}>
 */
function inspectRosterRows(array $cars, array $eventSheets, array $seasonSheets, array $declarations,
                           array $sheetDrivers, array $selfDrivers, array $seasonGear, int $season,
                           array $key = ['discipline' => 'summer', 'club' => null]): array {
    $isIce = ($key['discipline'] ?? 'summer') === 'ice';
    $sheetByCar = [];
    foreach ($eventSheets as $s) {
        $cid = (int)$s['car_id'];
        if (!isset($sheetByCar[$cid]) || (int)$s['id'] > (int)$sheetByCar[$cid]['id']) $sheetByCar[$cid] = $s;
    }
    $groups = techGroupSheetsByCar($isIce
        ? array_values(array_filter($seasonSheets, fn(array $s): bool => ($s['club'] ?? null) === $key['club']))
        : $seasonSheets);
    $gearByOwner = [];
    foreach ($seasonGear as $g) {
        $gearByOwner[(int)$g['owner_user_id']][] = $g;
    }

    $rows = [];
    foreach ($cars as $car) {
        $cid = (int)$car['id'];
        $owner = (int)$car['owner_user_id'];
        $sheet = $sheetByCar[$cid] ?? null;
        $ownerGear = $gearByOwner[$owner] ?? [];
        if ($sheet !== null) {
            $links = gearLinksForSheet($sheet, $sheetDrivers[(int)$sheet['id']] ?? [], $ownerGear);
        } elseif (isset($selfDrivers[$owner])) {
            $links = gearLinksForSheet(['user_id' => $owner, 'season' => $season, 'driver_name' => (string)$selfDrivers[$owner]['name'],
                'discipline' => $key['discipline'], 'club' => $key['club']], [], $ownerGear);
        } else {
            $links = [];
        }
        $rows[] = [
            'car' => $car,
            'sheet' => $sheet,
            'class' => garageClassLine($declarations[$cid] ?? []),
            'status' => techCarStatus($groups[techCarKey(['car_id' => $cid, 'season' => $season, 'discipline' => $key['discipline'], 'club' => $key['club']])] ?? []),
            'gear_links' => $links,
            'ice_class' => ($isIce && $sheet !== null) ? techSheetClassLine($sheet) : '',
        ];
    }
    return $rows;
}

/** $filter is an INSPECT_ROSTER_FILTERS key. Unknown values mean 'all'. */
function inspectRosterFilter(array $rows, string $filter): array {
    if ($filter === 'all' || !isset(INSPECT_ROSTER_FILTERS[$filter])) return $rows;
    return array_values(array_filter($rows, function (array $r) use ($filter): bool {
        $carAccepted = $r['status']['state'] === 'accepted';
        switch ($filter) {
            case 'needs_decals': return $carAccepted;
            case 'needs_tech':   return !$carAccepted || techGearLinksNeedGear($r['gear_links']);
            case 'no_sheet':     return $r['sheet'] === null;
        }
        // class_not_accepted: an ice row's class comes from its sheet, not the summer declaration.
        if (($r['ice_class'] ?? '') !== '') return false;
        $current = $r['class']['current'];
        return $current === null || $current['review_status'] !== 'accepted';
    }));
}

/** @return array<string,int> INSPECT_ROSTER_FILTERS key => how many rows it keeps */
function inspectRosterCounts(array $rows): array {
    $counts = [];
    foreach (array_keys(INSPECT_ROSTER_FILTERS) as $key) {
        $counts[$key] = count(inspectRosterFilter($rows, $key));
    }
    return $counts;
}

/**
 * The review queue (spec §5): declarations with an inspector, then car and gear photo sets awaiting
 * review, merged oldest first. Each item links to the page that holds its review card.
 *
 * @param array $declarations db_get_declarations_awaiting_review()
 * @param array $sheets       db_get_sheets_awaiting_photo_review()
 * @param array $gear         db_get_gear_awaiting_photo_review()
 * @return array<int, array{kind: string, id: int, title: string, detail: string, since: string, url: string}>
 */
function inspectReviewQueue(array $declarations, array $sheets, array $gear): array {
    $items = [];
    foreach ($declarations as $d) {
        $items[] = [
            'kind' => 'declaration', 'id' => (int)$d['id'],
            'title' => 'Class declaration: #' . $d['car_number'] . ' ' . trim($d['year'] . ' ' . $d['make'] . ' ' . $d['model']),
            'detail' => $d['name'] . ' · ' . ($d['calculated_class'] ?? '—'),
            'since' => (string)$d['submitted_at'],
            'url' => 'inspect.php?action=declaration&id=' . (int)$d['id'],
        ];
    }
    foreach ($sheets as $s) {
        $items[] = [
            'kind' => 'car_photos', 'id' => (int)$s['id'],
            'title' => 'Car pre-tech photos: #' . $s['car_number'] . ' ' . trim($s['car_make'] . ' ' . $s['car_model']),
            'detail' => $s['entrant_name'] . ' · ' . ($s['event_name'] ?? ''),
            'since' => (string)$s['updated_at'],
            'url' => 'inspect.php?action=tech-sheet&id=' . (int)$s['id'] . '#pretech-review',
        ];
    }
    foreach ($gear as $g) {
        $items[] = [
            'kind' => 'gear_photos', 'id' => (int)$g['id'],
            'title' => 'Gear pre-tech photos: ' . $g['driver_name'],
            'detail' => 'Entered by ' . ($g['owner_name'] ?? '') . ' · ' . (int)$g['season'],
            'since' => (string)$g['updated_at'],
            'url' => 'inspect.php?action=gear-record&id=' . (int)$g['id'] . '#gear-review',
        ];
    }
    usort($items, fn(array $a, array $b): int => strcmp($a['since'], $b['since']) ?: strcmp($a['kind'], $b['kind']) ?: ($a['id'] <=> $b['id']));
    return $items;
}

/**
 * The Classing tab's filters from the query string, validated. Anything unknown or malformed means "any".
 *
 * @return array{q: string, class: string, season: int, status: string, car: int, page: int}
 */
function inspectClassingFilters(array $get): array {
    $str = fn(string $k): string => is_string($get[$k] ?? null) ? trim($get[$k]) : '';
    $int = fn(string $k): int => is_scalar($get[$k] ?? null) && ctype_digit((string)$get[$k]) ? (int)$get[$k] : 0;
    $class = strtoupper($str('class'));
    $status = $str('status');
    $season = $int('season');
    return [
        'q' => mb_substr($str('q'), 0, 100, 'UTF-8'),
        'class' => in_array($class, INSPECT_CLASSES, true) ? $class : '',
        'season' => $season >= 2000 && $season <= 2100 ? $season : 0,
        'status' => in_array($status, INSPECT_DECLARATION_STATUSES, true) ? $status : '',
        'car' => $int('car'),
        'page' => max(1, $int('page')),
    ];
}

/** A Classing URL for filters $f with $over applied (e.g. ['page' => 2]). Unset filters and page 1 are left out. */
function inspectClassingQuery(array $f, array $over = []): string {
    $params = ['action' => 'classing'];
    // 0 and '' mean "not set" for every Classing filter (season, car, class, status, q); page 1 is the default.
    foreach (array_merge($f, $over) as $key => $value) {
        if ($value === '' || $value === 0 || ($key === 'page' && (int)$value <= 1)) continue;
        $params[$key] = $value;
    }
    return 'inspect.php?' . http_build_query($params);
}
