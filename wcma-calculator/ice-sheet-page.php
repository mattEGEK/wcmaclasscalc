<?php
// wcma-calculator/ice-sheet-page.php
//
// The ice tech sheet form: its view model and HTML. It keeps the summer form's element ids so
// js/tech-sheet-form.js drives both; js/ice-class-picker.js swaps the checklist when the class
// changes. Callers must have loaded view_helpers.php and garage-page.php (garageCarTitle/Sub).
require_once __DIR__ . '/ice-sheet-lib.php';

/** Everything the ice form needs. $sheet is null for a new sheet, the tech_sheets row for an edit. */
function iceSheetFormVm(array $car, array $event, array $iceEvents, array $ownerDrivers, ?array $sheet, string $csrf): array {
    $club = (string)$event['host_club'];
    $sectionsByClass = $fhrByClass = $classNotes = $helmetNotes = [];
    foreach (ICE_CLUBS[$club]['classes'] ?? [] as $code => $def) {
        $class = iceClass($club, (string)$code);
        $sectionsByClass[$code] = iceChecklistSections($club, $class['group']);
        $fhrByClass[$code] = $class['fhr'];
        $classNotes[$code] = $class['note'];
        $helmetNotes[$code] = iceHelmetNote($club, $class);
    }
    $selected = $sheet !== null ? (string)$sheet['class'] : '';
    return [
        'car' => $car, 'event' => $event, 'club' => $club, 'clubLabel' => (string)iceClubLabel($club),
        'otherEvents' => $sheet !== null ? [] : array_values(array_filter($iceEvents, fn(array $e): bool => (int)$e['id'] !== (int)$event['id'])),
        'classOptions' => iceClassOptions($club), 'selectedClass' => $selected,
        'sectionsByClass' => $sectionsByClass, 'fhrByClass' => $fhrByClass,
        'classNotes' => $classNotes, 'helmetNotes' => $helmetNotes,
        'equipmentItems' => iceEquipmentItems(iceClass($club, $selected)),
        'driver1' => techSheetDriver1FormState($ownerDrivers, $sheet), 'ownerDrivers' => $ownerDrivers,
        'sheet' => $sheet, 'csrf' => $csrf,
        'action' => $sheet !== null ? 'tech-sheets.php?action=update' : 'tech-sheets.php?action=submit-ice',
    ];
}

function renderIceTechSheetFormHtml(array $vm): string {
    $car = $vm['car'];
    $event = $vm['event'];
    $sheet = $vm['sheet'];
    $isEdit = $sheet !== null;
    $d1 = $vm['driver1'];
    $sel = $vm['selectedClass'];
    $json = fn($v): string => json_encode($v ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $out = '<form id="tech-sheet-form" method="post" action="' . h($vm['action']) . '">'
        . '<input type="hidden" name="csrf_token" value="' . h($vm['csrf']) . '">'
        . ($isEdit ? '<input type="hidden" name="tech_sheet_id" value="' . (int)$sheet['id'] . '">'
                   : '<input type="hidden" name="car_id" value="' . (int)$car['id'] . '">')
        . '<input type="hidden" name="event_id" value="' . (int)$event['id'] . '">'
        . '<input type="hidden" name="checklist_json" id="checklist_json">'
        . '<input type="hidden" name="driver1_equipment_json" id="driver1_equipment_json">'
        . '<input type="hidden" name="drivers_json" id="drivers_json">'
        . '<input type="hidden" name="entrant_signature" id="entrant_signature">'
        . '<input type="hidden" name="driver_signature" id="driver_signature">';

    // Event
    $out .= '<div class="detail-card"><h2>Ice event</h2><p><strong>' . h((string)$event['name']) . '</strong> — '
        . h(date('M j, Y', strtotime((string)$event['event_date']))) . ' · ' . h($vm['clubLabel']) . '</p>';
    if ($vm['otherEvents']) {
        $out .= '<p class="form-hint">Other ice events: ';
        $links = [];
        foreach ($vm['otherEvents'] as $e) {
            $links[] = '<a href="tech-sheets.php?action=new-ice&amp;car_id=' . (int)$car['id'] . '&amp;event_id=' . (int)$e['id'] . '">'
                . h((string)$e['name']) . '</a>';
        }
        $out .= implode(' · ', $links) . '</p>';
    }
    $out .= '</div>';

    // Car
    $out .= '<div class="detail-card"><h2>Car</h2><p class="tech-sheet-car"><span class="hub-plate">' . h((string)$car['car_number']) . '</span> '
        . h(garageCarTitle($car)) . (garageCarSub($car) !== '' ? ' · ' . h(garageCarSub($car)) : '') . '</p>'
        . '<p class="form-hint">Car details come from your Garage. <a href="garage.php?car=' . (int)$car['id'] . '">Edit car details</a></p>';
    if (trim((string)($car['colour'] ?? '')) === '') {
        $out .= '<label for="car_colour">Car colour</label><input type="text" id="car_colour" name="car_colour" maxlength="30" required>'
            . '<p class="form-hint">Your car has no colour on file yet. It will be saved to the car.</p>';
    }
    $out .= '<label for="car_weight">Race weight (lbs, without driver)</label>'
        . '<input type="number" id="car_weight" name="car_weight" min="1" max="9999" step="1" required value="'
        . ($isEdit ? (int)$sheet['car_weight'] : '') . '"></div>';

    // Class
    $out .= '<div class="detail-card"><h2>Class</h2><label for="ice_class">' . h($vm['club']) . ' class</label>'
        . '<select id="ice_class" name="class" required><option value="">Choose a class</option>';
    foreach ($vm['classOptions'] as $code => $label) {
        $out .= '<option value="' . h($code) . '"' . ($code === $sel ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    $out .= '</select><p class="form-hint" id="ice-class-note">' . h($vm['classNotes'][$sel] ?? '') . '</p>'
        . '<p class="form-hint">Pick the class from the ' . h($vm['club']) . ' supplementary regulations.</p></div>';

    // Entrant & driver
    $out .= '<div class="detail-card"><h2>Entrant &amp; Driver</h2><div class="tech-sheet-header-grid">'
        . '<div><label for="entrant_name">Entrant</label><input type="text" id="entrant_name" name="entrant_name" required value="'
        . h($isEdit ? (string)$sheet['entrant_name'] : (string)($d1['ownedById'][$d1['selfId'] ?? 0]['name'] ?? '')) . '"></div>'
        . '<div><label for="driver1_choice">Driver</label><select id="driver1_choice" name="driver1_choice" required>';
    foreach ($vm['ownerDrivers'] as $d) {
        $out .= '<option value="' . (int)$d['id'] . '"' . ((string)(int)$d['id'] === $d1['choice'] ? ' selected' : '') . '>'
            . h((string)$d['name']) . ((int)$d['id'] === $d1['selfId'] ? ' (you)' : '') . '</option>';
    }
    $out .= '<option value="new"' . ($d1['choice'] === 'new' ? ' selected' : '') . '>+ Add a co-driver</option></select>'
        . '<input type="text" id="driver1_new_name" name="driver1_new_name" maxlength="100" placeholder="Driver\'s name" aria-label="Driver name" value="' . h($d1['newName']) . '"></div>'
        . '<div><label for="engine_hp">Engine HP</label><input type="text" id="engine_hp" name="engine_hp" value="' . h($isEdit ? (string)($sheet['engine_hp'] ?? '') : '') . '"></div>'
        . '</div></div>';

    // Checklist and equipment
    $out .= '<div class="detail-card"><h2>Vehicle Checklist</h2><div id="checklist-container"></div></div>'
        . '<div class="detail-card"><h2>Driver Safety Equipment</h2><p class="form-hint" id="ice-helmet-note">' . h($vm['helmetNotes'][$sel] ?? '') . '</p>'
        . '<div id="equipment-container"></div></div>';

    // Log book
    $log = $isEdit ? (string)$sheet['log_book_turned_in'] : null;
    $out .= '<div class="detail-card"><h2>Log Book</h2>'
        . '<label class="checkbox-label"><input type="radio" name="log_book_turned_in" value="1"' . ($log === '1' ? ' checked' : '') . ' required> Yes</label>'
        . '<label class="checkbox-label"><input type="radio" name="log_book_turned_in" value="0"' . ($log === '0' ? ' checked' : '') . '> No</label></div>';

    // Signatures
    $out .= '<div class="detail-card"><h2>Declaration &amp; Signatures</h2>'
        . '<p><em>I hereby stipulate that the above vehicle meets the regulations for the event.</em></p>'
        . ($isEdit ? '<p class="form-hint">Leave the pads blank to keep the signatures already on file.</p>' : '')
        . '<label>Entrant\'s Signature</label><div class="sig-pad-wrap"><canvas id="entrant-sig-canvas"></canvas></div>'
        . '<div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="entrant">Clear</button></div>'
        . '<label>Driver\'s Signature</label><div class="sig-pad-wrap"><canvas id="driver-sig-canvas"></canvas></div>'
        . '<div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="driver">Clear</button></div></div>';

    $out .= '<div class="form-actions"><button type="submit" class="btn btn-primary" id="tech-sheet-submit-btn">'
        . ($isEdit ? 'Save Changes' : 'Submit Ice Tech Sheet') . '</button></div>'
        . '<div id="tech-sheet-error" class="form-messages error" hidden></div></form>';

    $existingChecklist = $isEdit ? (json_decode((string)($sheet['checklist_json'] ?? '{}'), true) ?: []) : [];
    $existingEquipment = $isEdit ? (json_decode((string)($sheet['driver1_equipment_json'] ?? '{}'), true) ?: []) : [];
    $out .= '<script>'
        . 'const TECH_CHECKLIST_SECTIONS = ' . $json($vm['sectionsByClass'][$sel] ?? []) . ';'
        . 'const TECH_DRIVER_EQUIPMENT_ITEMS = ' . $json($vm['equipmentItems']) . ';'
        . 'window.ICE_SECTIONS_BY_CLASS = ' . $json($vm['sectionsByClass']) . ';'
        . 'window.ICE_FHR_BY_CLASS = ' . $json($vm['fhrByClass']) . ';'
        . 'window.ICE_CLASS_NOTES = ' . $json($vm['classNotes']) . ';'
        . 'window.ICE_HELMET_NOTES = ' . $json($vm['helmetNotes']) . ';'
        . 'window.TECH_SHEET_EXISTING_CHECKLIST = ' . $json($existingChecklist) . ';'
        . 'window.TECH_SHEET_EXISTING_EQUIPMENT = ' . $json($existingEquipment) . ';'
        . 'window.TECH_SHEET_EXISTING_DRIVERS = [];'
        . 'window.TECH_SHEET_DRIVERS = ' . json_encode($d1['driversForJs'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'
        . 'window.TECH_SHEET_HAS_ENTRANT_SIGNATURE = ' . ($isEdit && !empty($sheet['entrant_signature_path']) ? 'true' : 'false') . ';'
        . 'window.TECH_SHEET_HAS_DRIVER_SIGNATURE = ' . ($isEdit && !empty($sheet['driver_signature_path']) ? 'true' : 'false') . ';'
        . '</script>'
        . '<script src="js/tech-sheet-checklist.js"></script><script src="js/signature-pad.js"></script>'
        . '<script src="js/driver-choice.js"></script><script src="js/ice-class-picker.js"></script>'
        . '<script src="js/tech-sheet-form.js"></script>';
    return $out;
}
