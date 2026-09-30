<?php
// wcma-calculator/ta-drift-sheet-page.php
//
// The TA/Drift tech sheet form (spec 2026-09-29-ta-drift-tech-design.md §3): its view model and HTML.
// It keeps the summer form's element ids so js/tech-sheet-form.js drives it. The "roll bar or cage"
// box swaps in the cage checks, and a hidden #sheet_type of "endurance" turns on the added-drivers
// card. Callers must have loaded view_helpers.php and garage-page.php (garageCarTitle/Sub).
require_once __DIR__ . '/ice-sheet-lib.php';   // techSheetDraftKey(); loads ta-drift-sheet-lib.php

const TA_DRIFT_SHEET_INTRO = "Check each item on the car itself before you tick it. You're confirming your car is safe to go on track.";

/**
 * Everything the TA/Drift form needs. $sheet is null for a new sheet, the tech_sheets row for an edit;
 * $sheetDrivers are that sheet's tech_sheet_drivers rows. $taEvents are taDriftOpenEvents().
 */
function taDriftSheetFormVm(array $car, array $event, array $taEvents, array $ownerDrivers, ?array $sheet, array $sheetDrivers, string $csrf, ?int $preferDriver1 = null): array {
    $club = (string)$event['host_club'];
    $caged = $sheet !== null && !empty($sheet['caged']);
    $d1 = techSheetDriver1FormState($ownerDrivers, $sheet, $preferDriver1);
    $existingDrivers = array_map(function (array $d) use ($d1): array {
        $choice = techSheetDriverChoiceFor($d1['ownedById'], (string)$d['driver_name']);
        return [
            'driver_number' => (int)$d['driver_number'],
            'driver_choice' => $choice,
            'new_name' => $choice === 'new' ? (string)$d['driver_name'] : '',
            'equipment' => json_decode((string)($d['equipment_json'] ?? '{}'), true) ?: [],
        ];
    }, $sheetDrivers);
    return [
        'car' => $car, 'event' => $event, 'club' => $club, 'caged' => $caged,
        'otherEvents' => $sheet !== null ? [] : array_values(array_filter($taEvents, fn(array $e): bool => (int)$e['id'] !== (int)$event['id'])),
        'sections' => ['off' => taDriftChecklistSections(false, $club), 'on' => taDriftChecklistSections(true, $club)],
        'helmetNotes' => ['off' => taDriftHelmetNote(false), 'on' => taDriftHelmetNote(true)],
        'equipmentItems' => taDriftEquipmentItems($caged),
        'driver1' => $d1, 'ownerDrivers' => $ownerDrivers, 'existingDrivers' => $existingDrivers,
        'sheet' => $sheet, 'csrf' => $csrf,
        'draftKey' => $sheet === null ? techSheetDraftKey((int)($car['owner_user_id'] ?? 0), (int)$car['id'], (int)$event['id']) : null,
        'action' => $sheet !== null ? 'tech-sheets.php?action=update' : 'tech-sheets.php?action=submit-ta-drift',
    ];
}

function renderTaDriftTechSheetFormHtml(array $vm): string {
    $car = $vm['car'];
    $event = $vm['event'];
    $sheet = $vm['sheet'];
    $isEdit = $sheet !== null;
    $d1 = $vm['driver1'];
    $key = $vm['caged'] ? 'on' : 'off';
    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $json = fn($v): string => json_encode($v ?: new stdClass(), $flags);

    $out = '<div class="detail-card"><p><strong>' . h(TA_DRIFT_SHEET_INTRO) . '</strong></p></div>'
        . '<form id="tech-sheet-form" method="post" action="' . h($vm['action']) . '">'
        . '<input type="hidden" name="csrf_token" value="' . h($vm['csrf']) . '">'
        . ($isEdit ? '<input type="hidden" name="tech_sheet_id" value="' . (int)$sheet['id'] . '">'
                   : '<input type="hidden" name="car_id" value="' . (int)$car['id'] . '">')
        . '<input type="hidden" name="event_id" value="' . (int)$event['id'] . '">'
        . '<input type="hidden" id="sheet_type" value="endurance">'   // turns on the added-drivers card in tech-sheet-form.js
        . '<input type="hidden" name="checklist_json" id="checklist_json">'
        . '<input type="hidden" name="driver1_equipment_json" id="driver1_equipment_json">'
        . '<input type="hidden" name="drivers_json" id="drivers_json">'
        . '<input type="hidden" name="entrant_signature" id="entrant_signature">'
        . '<input type="hidden" name="driver_signature" id="driver_signature">';

    // Event
    $out .= '<div class="detail-card"><h2>Event</h2><p><strong>' . h((string)$event['name']) . '</strong> — '
        . h(date('M j, Y', strtotime((string)$event['event_date']))) . ' · ' . h($vm['club']) . '</p>'
        . '<p class="form-hint">This sheet is for Time Attack and Drift. It is checked against the ' . h($vm['club'])
        . ' supplementary regulations.</p>';
    if ($vm['otherEvents']) {
        $links = [];
        foreach ($vm['otherEvents'] as $e) {
            $links[] = '<a href="tech-sheets.php?action=new-ta-drift&amp;car_id=' . (int)$car['id'] . '&amp;event_id=' . (int)$e['id'] . '">'
                . h((string)$e['name']) . '</a>';
        }
        $out .= '<p class="form-hint">Other events: ' . implode(' · ', $links) . '</p>';
    }
    $out .= '</div>';

    // Car
    $out .= '<div class="detail-card"><h2>Car</h2><p class="tech-sheet-car"><span class="hub-plate">' . h((string)$car['car_number']) . '</span> '
        . h(garageCarTitle($car)) . (garageCarSub($car) !== '' ? ' · ' . h(garageCarSub($car)) : '') . '</p>'
        . '<p class="form-hint">Car details come from your Garage. <a href="garage.php?car=' . (int)$car['id'] . '">Edit car details</a></p>';
    if (trim((string)($car['colour'] ?? '')) === '') {
        $out .= '<label for="car_colour">Car colour (required)</label><input type="text" id="car_colour" name="car_colour" maxlength="30" required data-message="Enter the car\'s colour.">'
            . '<p class="form-hint">Your car has no colour on file yet. It will be saved to the car.</p>';
    }
    $out .= '<label class="checkbox-label"><input type="checkbox" id="ta_drift_caged" name="caged" value="1"' . ($vm['caged'] ? ' checked' : '') . '> This car has a roll bar or cage</label>'
        . '<p class="form-hint">A roll bar or cage adds its own checks, a 5- or 6-point harness, and a head and neck restraint for every driver.</p></div>';

    // Entrant & driver
    $out .= '<div class="detail-card"><h2>Entrant &amp; Driver</h2><div class="tech-sheet-header-grid">'
        . '<div><label for="entrant_name">Entrant (required)</label><input type="text" id="entrant_name" name="entrant_name" required data-message="Enter the entrant\'s name." value="'
        . h($isEdit ? (string)$sheet['entrant_name'] : (string)($d1['ownedById'][$d1['selfId'] ?? 0]['name'] ?? '')) . '"></div>'
        . '<div><label for="driver1_choice">Driver 1 (required)</label><select id="driver1_choice" name="driver1_choice" required>';
    foreach ($vm['ownerDrivers'] as $d) {
        $out .= '<option value="' . (int)$d['id'] . '"' . ((string)(int)$d['id'] === $d1['choice'] ? ' selected' : '') . '>'
            . h((string)$d['name']) . ((int)$d['id'] === $d1['selfId'] ? ' (you)' : '') . '</option>';
    }
    $out .= '<option value="new"' . ($d1['choice'] === 'new' ? ' selected' : '') . '>+ Add a co-driver</option></select>'
        . '<input type="text" id="driver1_new_name" name="driver1_new_name" maxlength="100" placeholder="Driver\'s name" aria-label="Driver 1 name" data-message="Enter the co-driver\'s name." value="' . h($d1['newName']) . '"></div>'
        . '</div></div>';

    // Checklist, driver 1 gear, other drivers
    $out .= '<div class="detail-card"><h2>Vehicle Checklist</h2><div id="checklist-container"></div></div>'
        . '<div class="detail-card"><h2>Driver Safety Equipment — Driver 1</h2><p class="form-hint" id="ta-drift-helmet-note">' . h($vm['helmetNotes'][$key]) . '</p>'
        . '<div id="equipment-container"></div></div>'
        . '<div class="detail-card" id="endurance-drivers-card"><h2>Other Drivers (optional)</h2>'
        . '<p class="form-hint">Add everyone else driving this car in Time Attack or Drift at this event.</p>'
        . '<div id="additional-drivers-container"></div>'
        . '<button type="button" class="btn btn-secondary" id="add-driver-btn">+ Add Driver</button></div>';

    // Signatures
    $out .= '<div class="detail-card"><h2>Declaration &amp; Signatures</h2>'
        . '<p><em>I hereby stipulate that the above vehicle meets the regulations for the event.</em></p>'
        . ($isEdit ? '<p class="form-hint">Leave the pads blank to keep the signatures already on file.</p>' : '')
        . '<p id="sig-error" class="field-message" hidden></p>'
        . '<label id="entrant-sig-label">Entrant\'s signature</label><div class="sig-pad-wrap"><canvas id="entrant-sig-canvas"></canvas></div>'
        . '<div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="entrant">Clear</button></div>'
        . '<div id="driver-sig-block"><label>Driver\'s signature</label><div class="sig-pad-wrap"><canvas id="driver-sig-canvas"></canvas></div>'
        . '<div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="driver">Clear</button></div></div></div>';

    $out .= '<div id="tech-sheet-error" class="form-messages error" role="alert" hidden></div>'
        . '<div class="form-actions"><button type="submit" class="btn btn-primary" id="tech-sheet-submit-btn">'
        . ($isEdit ? 'Save Changes' : 'Submit TA/Drift Tech Sheet') . '</button></div></form>';

    $existingChecklist = $isEdit ? (json_decode((string)($sheet['checklist_json'] ?? '{}'), true) ?: []) : [];
    $existingEquipment = $isEdit ? (json_decode((string)($sheet['driver1_equipment_json'] ?? '{}'), true) ?: []) : [];
    $out .= '<script>'
        . 'const TECH_CHECKLIST_SECTIONS = ' . $json($vm['sections'][$key]) . ';'
        . 'const TECH_DRIVER_EQUIPMENT_ITEMS = ' . $json($vm['equipmentItems']) . ';'
        . 'window.TA_DRIFT_SECTIONS = ' . $json($vm['sections']) . ';'
        . 'window.TA_DRIFT_HELMET_NOTES = ' . $json($vm['helmetNotes']) . ';'
        . 'window.TA_DRIFT_RENDERED_CAGED = ' . ($vm['caged'] ? 'true' : 'false') . ';'
        . 'window.TECH_SHEET_EXISTING_CHECKLIST = ' . $json($existingChecklist) . ';'
        . 'window.TECH_SHEET_EXISTING_EQUIPMENT = ' . $json($existingEquipment) . ';'
        . 'window.TECH_SHEET_EXISTING_DRIVERS = ' . json_encode($vm['existingDrivers'], $flags) . ';'
        . 'window.TECH_SHEET_DRIVERS = ' . json_encode($d1['driversForJs'], $flags) . ';'
        . 'window.TECH_SHEET_HAS_ENTRANT_SIGNATURE = ' . ($isEdit && !empty($sheet['entrant_signature_path']) ? 'true' : 'false') . ';'
        . 'window.TECH_SHEET_HAS_DRIVER_SIGNATURE = ' . ($isEdit && !empty($sheet['driver_signature_path']) ? 'true' : 'false') . ';'
        . (($vm['draftKey'] ?? null) !== null ? 'window.TECH_SHEET_DRAFT_KEY = ' . json_encode($vm['draftKey']) . ';' : '')
        . '</script>'
        . '<script src="js/tech-sheet-checklist.js"></script><script src="js/signature-pad.js"></script>'
        . '<script src="js/driver-choice.js"></script><script src="js/ice-class-picker.js"></script>'
        . '<script src="js/form-problems.js"></script><script src="js/tech-sheet-draft.js"></script><script src="js/tech-sheet-form.js"></script>';
    return $out;
}
