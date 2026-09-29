<?php
// wcma-calculator/ta-drift-sheet-lib.php
//
// TA/Drift tech sheets (spec 2026-09-29-ta-drift-tech-design.md §3): which events take one, parsing
// and validating the form, the DB row, and the car's TA/Drift standing (race tech also counts).
// Pure: no DB, no HTML.
require_once __DIR__ . '/ta-drift-rules.php';
require_once __DIR__ . '/ta-drift-lib.php';
require_once __DIR__ . '/tech-sheet-data.php';

/** The events a TA/Drift sheet can be for: summer events with a host club (TA/Drift tech is per club). */
function taDriftOpenEvents(array $events): array {
    return array_values(array_filter($events, fn(array $e): bool =>
        ($e['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_SUMMER && trim((string)($e['host_club'] ?? '')) !== ''));
}

/** Decodes and trims the TA/Drift form's POST, after techSheetApplyDriverChoices(). No validation here. */
function taDriftSheetParsePost(array $post): array {
    $str = fn(string $k): string => is_string($post[$k] ?? null) ? trim($post[$k]) : '';
    $json = fn(string $k): array => is_string($post[$k] ?? null) ? (($decoded = json_decode($post[$k], true)) && is_array($decoded) ? $decoded : []) : [];
    return [
        'caged'         => ($post['caged'] ?? null) === '1',
        'checklist'     => $json('checklist_json'),
        'equipment'     => $json('driver1_equipment_json'),
        'drivers_input' => $json('drivers_json'),
        'entrant_name'  => $str('entrant_name'),
        'driver_name'   => $str('driver_name'),
        'car_number'    => $str('car_number'),
        'car_colour'    => $str('car_colour'),
        'engine_cc'     => $str('engine_cc') ?: null,
    ];
}

/**
 * Checks a parsed TA/Drift sheet for $club. On success, 'drivers' holds the added drivers as
 * db_replace_tech_sheet_drivers() rows.
 *
 * @return array{error: ?string, drivers: array}
 */
function taDriftSheetValidate(array $parsed, string $club): array {
    $fail = fn(string $msg): array => ['error' => $msg, 'drivers' => []];
    if ($parsed['entrant_name'] === '' || $parsed['driver_name'] === ''
        || $parsed['car_number'] === '' || $parsed['car_colour'] === '') {
        return $fail('Please complete every required field.');
    }
    $caged = (bool)$parsed['caged'];
    if (!validateChecklist($parsed['checklist'], taDriftChecklistSections($caged, $club))) {
        return $fail('Please mark every checklist item OK or N/A.');
    }
    // The regulations item is a promise, so "N/A" doesn't answer it.
    if (($parsed['checklist']['supps_read']['status'] ?? null) !== 'ok') {
        return $fail('Confirm you have read the ' . $club . ' supplementary regulations.');
    }
    $items = taDriftEquipmentItems($caged);
    if (!validateDriverEquipment($parsed['equipment'], $items)) {
        return $fail("Please confirm Driver 1's safety equipment, including the helmet rating.");
    }
    $drivers = validateAdditionalDrivers($parsed['drivers_input'], $items);
    if ($drivers === null) return $fail('Please confirm the safety equipment of every added driver.');
    return ['error' => null, 'drivers' => $drivers];
}

/**
 * The DB columns shared by submitting and updating a TA/Drift sheet. A TA/Drift sheet has no class,
 * weight, HP or log book. Callers merge in car_id/user_id/event_id (new) or event_id (edit).
 */
function taDriftSheetRow(array $p, array $car): array {
    return [
        'sheet_type' => SHEET_TYPE_TA_DRIFT, 'caged' => (bool)$p['caged'],
        'entrant_name' => $p['entrant_name'], 'driver_name' => $p['driver_name'],
        'car_make' => $car['make'], 'car_model' => $car['model'], 'car_colour' => $p['car_colour'],
        'car_number' => $p['car_number'], 'class' => '',
        'engine_cc' => $p['engine_cc'], 'engine_hp' => null, 'car_weight' => 0,
        'checklist_json' => json_encode($p['checklist']), 'driver1_equipment_json' => json_encode($p['equipment']),
        'log_book_turned_in' => null,
    ];
}

/**
 * The car's standing at this sheet's club and season (spec §2 approval ladder): its summer race tech
 * if accepted, otherwise its TA/Drift sheets for the club. $ownerSheets is every sheet of the owner's.
 *
 * @return array{state: string, via: ?string, sheet_id: ?int, tier: string}
 */
function taDriftSheetCarStatus(array $sheet, array $ownerSheets): array {
    $groups = techGroupSheetsByCar($ownerSheets);
    $raceKey = techCarKey(['car_id' => $sheet['car_id'], 'season' => $sheet['season'], 'discipline' => DISCIPLINE_SUMMER]);
    return taDriftCarTechStatus(techCarStatus($groups[$raceKey] ?? []), techCarStatus($groups[techCarKey($sheet)] ?? []));
}

/** "Teched 2026 (race)", "Pre-teched TA/Drift WSCC 2026", or the photo state for a car not yet accepted. */
function taDriftCarTechStatusLabel(array $status, int $season, string $club): string {
    if (($status['state'] ?? 'none') !== 'accepted') return techCarStatusLabel($status, $season);
    $how = ($status['via'] ?? null) === 'photos' ? 'Pre-teched ' : 'Teched ';
    return ($status['tier'] ?? TECH_TIER_TA_DRIFT) === TECH_TIER_RACE
        ? $how . $season . ' (race)'
        : $how . 'TA/Drift ' . $club . ' ' . $season;
}
