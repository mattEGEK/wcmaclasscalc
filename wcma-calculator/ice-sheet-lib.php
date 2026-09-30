<?php
// wcma-calculator/ice-sheet-lib.php
//
// Ice tech sheets: which checklist and equipment a sheet is checked against, parsing and validating
// the ice form, and who gets the sheet's emails. Pure except techSheetRecipientEmail(), which needs
// db.php loaded by the caller.
require_once __DIR__ . '/ice-rules.php';
require_once __DIR__ . '/tech-sheet-data.php';
require_once __DIR__ . '/tech-status.php';
require_once __DIR__ . '/ta-drift-sheet-lib.php';

function techSheetIsIce(array $sheet): bool {
    return ($sheet['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE;
}

/** The checklist sections a sheet is filled in and shown against. */
function techSheetChecklistSections(array $sheet): array {
    if (techSheetIsTaDrift($sheet)) return taDriftChecklistSections(!empty($sheet['caged']), (string)($sheet['club'] ?? ''));
    if (!techSheetIsIce($sheet)) return TECH_CHECKLIST_SECTIONS;
    $club = (string)($sheet['club'] ?? '');
    $class = iceClass($club, (string)($sheet['class'] ?? ''));
    return $class === null ? [] : iceChecklistSections($club, $class['group']);
}

/** The driver equipment items a sheet is filled in and shown against. */
function techSheetEquipmentItems(array $sheet): array {
    if (techSheetIsTaDrift($sheet)) return taDriftEquipmentItems(!empty($sheet['caged']));
    if (!techSheetIsIce($sheet)) return TECH_DRIVER_EQUIPMENT_ITEMS;
    return iceEquipmentItems(iceClass((string)($sheet['club'] ?? ''), (string)($sheet['class'] ?? '')));
}

/** "LS — Limited Stud (NASCC)" for an ice sheet; "TA/Drift (WSCC)" for a TA/Drift sheet; the stored class for summer. */
function techSheetClassLine(array $sheet): string {
    if (techSheetIsTaDrift($sheet)) return 'TA/Drift (' . (string)($sheet['club'] ?? '') . ')';
    $code = (string)($sheet['class'] ?? '');
    if (!techSheetIsIce($sheet)) return $code;
    $club = (string)($sheet['club'] ?? '');
    $class = iceClass($club, $code);
    return ($class === null ? $code : $code . ' — ' . $class['label']) . ' (' . $club . ')';
}

/** Decodes and trims the ice form's POST. No validation here (see iceSheetValidate()). */
function iceSheetParsePost(array $post): array {
    $str = fn(string $k): string => is_string($post[$k] ?? null) ? trim($post[$k]) : '';
    $json = fn(string $k): array => is_string($post[$k] ?? null) ? (($decoded = json_decode($post[$k], true)) && is_array($decoded) ? $decoded : []) : [];
    return [
        'class'        => $str('class'),
        'car_weight'   => $str('car_weight'),
        'checklist'    => $json('checklist_json'),
        'equipment'    => $json('driver1_equipment_json'),
        'entrant_name' => $str('entrant_name'),
        'driver_name'  => $str('driver_name'),
        'car_number'   => $str('car_number'),
        'car_colour'   => $str('car_colour'),
        'engine_cc'    => $str('engine_cc') ?: null,
        'engine_hp'    => $str('engine_hp') ?: null,
        'log_book'     => $post['log_book_turned_in'] ?? null,
    ];
}

/** Null when the parsed ice sheet is complete for $club, otherwise the first problem to show. */
function iceSheetValidate(array $parsed, string $club): ?string {
    $class = iceClass($club, (string)$parsed['class']);
    if ($class === null) return 'Choose a class from the ' . $club . ' list.';
    $w = (string)$parsed['car_weight'];
    if (!ctype_digit($w) || (int)$w < 1 || (int)$w > 9999) return "Enter the car's race weight in pounds.";
    if ($parsed['entrant_name'] === '' || $parsed['driver_name'] === ''
        || $parsed['car_number'] === '' || $parsed['car_colour'] === ''
        || !in_array($parsed['log_book'], ['0', '1'], true)) {
        return 'Please complete every required field.';
    }
    if (!validateChecklist($parsed['checklist'], iceChecklistSections($club, $class['group']))) {
        return 'Please mark every checklist item OK or N/A.';
    }
    if (!validateDriverEquipment($parsed['equipment'], iceEquipmentItems($class))) {
        return "Please confirm Driver 1's safety equipment, including the helmet and suit ratings.";
    }
    return null;
}

/**
 * The DB columns shared by handleSubmitIce() and handleUpdateIce(): everything from a validated,
 * parsed ice form (see iceSheetParsePost()/iceSheetValidate()) plus the car's make/model. Callers
 * merge in their own keys (car_id/user_id/event_id for a new sheet; event_id pinned to the sheet's
 * existing one for an edit).
 */
function iceSheetRow(array $p, array $car): array {
    return [
        'sheet_type' => 'ice',
        'entrant_name' => $p['entrant_name'], 'driver_name' => $p['driver_name'],
        'car_make' => $car['make'], 'car_model' => $car['model'], 'car_colour' => $p['car_colour'],
        'car_number' => $p['car_number'], 'class' => $p['class'],
        'engine_cc' => $p['engine_cc'], 'engine_hp' => $p['engine_hp'], 'car_weight' => (int)$p['car_weight'],
        'checklist_json' => json_encode($p['checklist']), 'driver1_equipment_json' => json_encode($p['equipment']),
        'log_book_turned_in' => (int)$p['log_book'],
    ];
}

/** Where a sheet's emails go: its declaration's email when it has one, otherwise the account holder's. */
function techSheetRecipientEmail(PDO $pdo, array $sheet): ?string {
    if (!empty($sheet['submission_id'])) {
        $email = db_get_submission($pdo, (int)$sheet['submission_id'])['email'] ?? null;
        if ($email) return (string)$email;
    }
    $user = db_find_user_by_id($pdo, (int)($sheet['user_id'] ?? 0));
    return $user['email'] ?? null;
}

/** The browser key a new tech sheet's draft is kept under (mobile UX spec 2026-09-28 §C1): one per user, car and event. */
function techSheetDraftKey(int $userId, int $carId, int $eventId): string {
    return 'wcma-tsdraft:' . $userId . ':' . $carId . ':' . $eventId;
}
