<?php
// wcma-calculator/tech-sheet-render.php
require_once __DIR__ . '/tech-sheet-data.php';

function techSheetSignatureImg(?string $path): string {
    if (!$path) return '<span style="color:#999">Not signed</span>';
    return '<img src="' . h($path) . '" alt="Signature" style="max-height:60px;border-bottom:1px solid #333">';
}

function techSheetEquipmentTable(array $equipment, bool $showTechColumn): string {
    $out = '<table cellpadding="4" style="border-collapse:collapse;width:100%;font-size:0.85rem">';
    $out .= '<tr style="background:#f0f1f2"><th style="text-align:left;border:1px solid #ccc;padding:4px">Item</th>';
    $out .= '<th style="border:1px solid #ccc;padding:4px">Confirmed</th>';
    if ($showTechColumn) $out .= '<th style="border:1px solid #ccc;padding:4px">Tech Rep Approved</th>';
    $out .= '</tr>';
    foreach (TECH_DRIVER_EQUIPMENT_ITEMS as $key => $def) {
        $item = $equipment[$key] ?? ['competitor_confirmed' => false, 'value' => null, 'tech_approved' => null];
        $label = h($def['label']);
        if ($def['has_rating'] && !empty($item['value'])) {
            $label .= ' — <strong>' . h((string)$item['value']) . '</strong>';
        }
        $confirmed = !empty($item['competitor_confirmed']) ? '✓' : '—';
        $out .= '<tr><td style="border:1px solid #ccc;padding:4px">' . $label . '</td>';
        $out .= '<td style="text-align:center;border:1px solid #ccc;padding:4px">' . $confirmed . '</td>';
        if ($showTechColumn) {
            $techVal = $item['tech_approved'] === null ? '—' : ($item['tech_approved'] ? '✓' : '✗');
            $out .= '<td style="text-align:center;border:1px solid #ccc;padding:4px">' . $techVal . '</td>';
        }
        $out .= '</tr>';
    }
    $out .= '</table>';
    return $out;
}

function renderTechSheetHtml(array $sheet, array $drivers, array $event): string {
    $checklist = json_decode($sheet['checklist_json'] ?? '{}', true) ?: [];
    $equipment = json_decode($sheet['driver1_equipment_json'] ?? '{}', true) ?: [];
    $showTechColumn = ($sheet['status'] ?? 'submitted') === 'teched';

    $out = '<div style="font-family:Arial,sans-serif;color:#222;max-width:800px">';
    $out .= '<h1 style="text-align:center;margin-bottom:0.2rem">VEHICLE INSPECTION FORM</h1>';
    $out .= '<p style="text-align:center;color:#555;font-size:0.85rem">' . h($event['name'] ?? '') . ' — ' . h(date('F j, Y', strtotime($event['event_date'] ?? 'now'))) . '</p>';

    $out .= '<table cellpadding="4" style="width:100%;border-collapse:collapse;margin:1rem 0">';
    $out .= '<tr><td style="width:50%"><strong>Entrant:</strong> ' . h($sheet['entrant_name']) . '</td><td><strong>Driver/Team:</strong> ' . h($sheet['driver_name']) . '</td></tr>';
    $out .= '<tr><td><strong>Car Make:</strong> ' . h($sheet['car_make']) . '</td><td><strong>Car Number:</strong> ' . h($sheet['car_number']) . '</td></tr>';
    $out .= '<tr><td><strong>Car Model:</strong> ' . h($sheet['car_model']) . '</td><td><strong>Class:</strong> ' . h($sheet['class']) . '</td></tr>';
    $out .= '<tr><td><strong>Car Colour:</strong> ' . h($sheet['car_colour']) . '</td><td><strong>Engine:</strong> ' . h((string)($sheet['engine_cc'] ?? '')) . ' CC / ' . h((string)($sheet['engine_hp'] ?? '')) . ' HP</td></tr>';
    $out .= '<tr><td><strong>Car Weight:</strong> ' . h((string)$sheet['car_weight']) . ' lbs</td><td></td></tr>';
    $out .= '</table>';

    $out .= '<h2 style="border-bottom:2px solid #2c3e50;padding-bottom:4px">Vehicle Checklist</h2>';
    foreach (TECH_CHECKLIST_SECTIONS as $section) {
        $out .= '<h3 style="margin-bottom:2px">' . h($section['label']) . '</h3>';
        $out .= '<table cellpadding="4" style="border-collapse:collapse;width:100%;font-size:0.85rem;margin-bottom:0.8rem">';
        foreach ($section['items'] as $key => $label) {
            $status = $checklist[$key]['status'] ?? null;
            $display = $status === 'ok' ? 'OK' : ($status === 'na' ? 'N/A' : '—');
            $out .= '<tr><td style="border:1px solid #ccc;padding:4px">' . h($label) . '</td>';
            $out .= '<td style="text-align:center;border:1px solid #ccc;padding:4px;width:60px">' . $display . '</td></tr>';
        }
        $out .= '</table>';
    }

    $out .= '<h2 style="border-bottom:2px solid #2c3e50;padding-bottom:4px">Driver Safety Equipment — ' . h($sheet['driver_name']) . '</h2>';
    $out .= techSheetEquipmentTable($equipment, $showTechColumn);

    if (($sheet['sheet_type'] ?? 'standard') === 'endurance' && !empty($drivers)) {
        foreach ($drivers as $d) {
            $driverEquipment = json_decode($d['equipment_json'] ?? '{}', true) ?: [];
            $out .= '<h2 style="border-bottom:2px solid #2c3e50;padding-bottom:4px">Driver ' . (int)$d['driver_number'] . ' — ' . h($d['driver_name']) . '</h2>';
            $out .= techSheetEquipmentTable($driverEquipment, $showTechColumn);
        }
    }

    $out .= '<h2 style="border-bottom:2px solid #2c3e50;padding-bottom:4px">Declaration</h2>';
    $out .= '<p><em>I hereby stipulate that the above vehicle meets the regulations for the event.</em></p>';
    $out .= '<table cellpadding="8" style="width:100%"><tr>';
    $out .= '<td style="width:33%"><div>' . techSheetSignatureImg($sheet['entrant_signature_path'] ?? null) . '</div><p style="font-size:0.8rem">Entrant\'s Signature</p></td>';
    $out .= '<td style="width:33%"><div>' . techSheetSignatureImg($sheet['driver_signature_path'] ?? null) . '</div><p style="font-size:0.8rem">Driver\'s Signature</p></td>';
    $out .= '<td style="width:33%"><div>' . techSheetSignatureImg($sheet['tech_signature_path'] ?? null) . '</div><p style="font-size:0.8rem">Tech Representative\'s Signature</p></td>';
    $out .= '</tr></table>';
    $out .= '<p>Vehicle Log Book Turned In: <strong>' . (($sheet['log_book_turned_in'] ?? null) === null ? '—' : ((int)$sheet['log_book_turned_in'] === 1 ? 'Yes' : 'No')) . '</strong></p>';
    $out .= '<p style="font-weight:bold;color:' . (($sheet['status'] ?? 'submitted') === 'teched' ? '#27ae60' : '#f39c12') . '">Status: ' . h(($sheet['status'] ?? 'submitted') === 'teched' ? 'Reviewed' : 'Submitted — awaiting review') . '</p>';
    $out .= '</div>';

    return $out;
}
