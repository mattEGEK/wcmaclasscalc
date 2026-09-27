<?php
// wcma-calculator/ice-sheet-lib.php
//
// Ice tech sheets: which checklist and equipment a sheet is checked against, parsing and validating
// the ice form, and who gets the sheet's emails. Pure except techSheetRecipientEmail(), which needs
// db.php loaded by the caller.
require_once __DIR__ . '/ice-rules.php';
require_once __DIR__ . '/tech-sheet-data.php';

function techSheetIsIce(array $sheet): bool {
    return ($sheet['discipline'] ?? 'summer') === 'ice';
}

/** The checklist sections a sheet is filled in and shown against. */
function techSheetChecklistSections(array $sheet): array {
    if (!techSheetIsIce($sheet)) return TECH_CHECKLIST_SECTIONS;
    $club = (string)($sheet['club'] ?? '');
    $class = iceClass($club, (string)($sheet['class'] ?? ''));
    return $class === null ? [] : iceChecklistSections($club, $class['group']);
}

/** The driver equipment items a sheet is filled in and shown against. */
function techSheetEquipmentItems(array $sheet): array {
    if (!techSheetIsIce($sheet)) return TECH_DRIVER_EQUIPMENT_ITEMS;
    return iceEquipmentItems(iceClass((string)($sheet['club'] ?? ''), (string)($sheet['class'] ?? '')));
}

/** "LS — Limited Stud (NASCC)" for an ice sheet; the stored class for summer. */
function techSheetClassLine(array $sheet): string {
    $code = (string)($sheet['class'] ?? '');
    if (!techSheetIsIce($sheet)) return $code;
    $club = (string)($sheet['club'] ?? '');
    $class = iceClass($club, $code);
    return ($class === null ? $code : $code . ' — ' . $class['label']) . ' (' . $club . ')';
}

/** Decodes and trims the ice form's POST. No validation here (see iceSheetValidate()). */
function iceSheetParsePost(array $post): array {
    $str = fn(string $k): string => is_string($post[$k] ?? null) ? trim($post[$k]) : '';
    $json = fn(string $k): array => is_string($post[$k] ?? null) ? (json_decode($post[$k], true) ?: []) : [];
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

/** Where a sheet's emails go: its declaration's email when it has one, otherwise the account holder's. */
function techSheetRecipientEmail(PDO $pdo, array $sheet): ?string {
    if (!empty($sheet['submission_id'])) {
        $email = db_get_submission($pdo, (int)$sheet['submission_id'])['email'] ?? null;
        if ($email) return (string)$email;
    }
    $user = db_find_user_by_id($pdo, (int)($sheet['user_id'] ?? 0));
    return $user['email'] ?? null;
}
