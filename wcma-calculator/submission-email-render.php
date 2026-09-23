<?php
// wcma-calculator/submission-email-render.php
require_once __DIR__ . '/view_helpers.php';

/**
 * Brake/suspension option id => description. Mirrors brakeModifierTable in
 * js/modifiers.js (tests/SubmissionEmailRenderTest.php fails if they drift).
 */
const SUBMISSION_BRAKE_LABELS = [
    'brake1' => 'Non-OEM, modified or relocated brake calipers/brackets or rotor diameter',
    'brake2' => 'Suspension design utilizing upper "A-arm" or "wishbone" type control arms (front or rear)',
    'brake3' => 'Replace, modify, or remove control arms, camber arms/links, toe arms/links',
    'brake4' => 'Add, replace, or modify a Watts link, Panhard Rod, or Torque Arm',
    'brake5' => 'Non-OEM metallic and/or spherical design replacement suspension bushing modifications on control/camber/toe arms/links, panhard rods, watts links, and torque arms',
    'brake6' => 'Non-OEM shocks/struts with an external reservoir (or piggyback) OR with shaft diameter 40mm or greater',
    'brake7' => 'Increase in track width greater than four (4) inches',
];

/**
 * Brake/suspension selections as readable descriptions. Accepts the DB's JSON
 * string or an array of ids; unknown ids pass through unchanged.
 * @return string[]
 */
function submissionBrakeDescriptions($brakeSuspension): array {
    if (is_string($brakeSuspension)) {
        $decoded = json_decode($brakeSuspension, true);
        $brakeSuspension = is_array($decoded) ? $decoded : ($brakeSuspension !== '' ? [$brakeSuspension] : []);
    }
    $out = [];
    foreach ((array)$brakeSuspension as $id) {
        $id = (string)$id;
        if ($id === '') continue;
        $out[] = SUBMISSION_BRAKE_LABELS[$id] ?? $id;
    }
    return $out;
}

/**
 * Ordered [label, value] rows shared by the HTML and plain-text renderers.
 * Values are raw (unescaped); empty optional rows are omitted.
 * @return array{contact: array, factors: array, results: array}
 */
function submissionEmailSections(array $s): array {
    $num = static fn($v): string => number_format((float)$v, 2);
    $has = static fn($k): bool => isset($s[$k]) && $s[$k] !== '' && $s[$k] !== null;

    $contact = [
        ['Name', (string)($s['name'] ?? '')],
        ['Email', (string)($s['email'] ?? '')],
        ['Vehicle', trim(($s['year'] ?? '') . ' ' . ($s['make'] ?? '') . ' ' . ($s['model'] ?? ''))],
    ];
    if ($has('comments')) $contact[] = ['Comments', (string)$s['comments']];

    $factors = [
        ['Competition Weight', ($s['competition_weight'] ?? '') . ' lbs'],
        ['Declared HP', (string)($s['declared_hp'] ?? '')],
    ];
    foreach ([
        'dyno_hp' => 'Dyno HP', 'chassis_display' => 'Chassis', 'body_mods_display' => 'Body Mods',
        'transmission_display' => 'Transmission', 'drivetrain_display' => 'Drivetrain', 'tires_display' => 'Tires',
    ] as $key => $label) {
        if ($has($key)) $factors[] = [$label, (string)$s[$key]];
    }

    $results = [];
    foreach ([
        'weight_factor' => 'Weight Factor', 'base_ratio' => 'Base Ratio',
        'modification_factor' => 'Additional Mod Factors', 'modified_ratio' => 'Modified Ratio',
    ] as $key => $label) {
        if ($has($key)) $results[] = [$label, $num($s[$key])];
    }

    return ['contact' => $contact, 'factors' => $factors, 'results' => $results];
}

function submissionEmailSubmittedLine(array $s, bool $isResend): string {
    $ts = isset($s['submitted_at']) ? strtotime((string)$s['submitted_at']) : false;
    $when = $ts !== false ? date('F j, Y \a\t g:i A', $ts) : (string)($s['submitted_at'] ?? '');
    return ($isResend ? 'Originally submitted: ' : 'Submitted: ') . $when;
}

function submissionEmailSectionTable(array $rows): string {
    $out = '<table cellpadding="6" style="border-collapse:collapse;width:100%;font-size:0.95rem;margin-bottom:1rem">';
    foreach ($rows as [$label, $value]) {
        $out .= '<tr><td style="width:220px;background:#f0f1f2;border:1px solid #ccc;padding:6px"><strong>' . h($label) . '</strong></td>'
            . '<td style="border:1px solid #ccc;padding:6px">' . nl2br(h($value)) . '</td></tr>';
    }
    return $out . '</table>';
}

/**
 * Branded HTML body for the class-calculator submission email, matching the
 * tech sheet email (see tech-sheet-render.php). Used for both the on-submit
 * email and the admin resend so they can't drift.
 *
 * @param array       $s        Submission row (DB column names)
 * @param string|null $logoSrc  <img src> for the WCMA logo (see emailLogoSrc())
 */
function renderSubmissionEmailHtml(array $s, ?string $logoSrc = null, bool $isResend = false): string {
    $sections = submissionEmailSections($s);
    $brake = submissionBrakeDescriptions($s['brake_suspension'] ?? []);
    $factors = $sections['factors'];
    if ($brake) $factors[] = ['Brake & Suspension', implode("\n", $brake)];

    $out = '<div style="font-family:Arial,sans-serif;color:#222;max-width:800px">';
    if ($logoSrc) {
        $out .= '<div style="text-align:center;margin-bottom:0.5rem"><img src="' . h($logoSrc) . '" alt="WCMA Logo" style="max-height:70px"></div>';
    }
    $out .= '<h1 style="text-align:center;margin-bottom:0.2rem">CLASS DECLARATION</h1>';
    $out .= '<p style="text-align:center;color:#555;font-size:0.85rem">' . h(submissionEmailSubmittedLine($s, $isResend)) . '</p>';

    if (!empty($s['calculated_class'])) {
        $out .= '<div style="text-align:center;margin:1rem 0;padding:0.8rem;background:#f0f1f2;border:1px solid #ccc">'
            . '<div style="font-size:0.85rem;color:#555">Calculated Class</div>'
            . '<div style="font-size:2rem;font-weight:bold;color:#2c3e50">' . h((string)$s['calculated_class']) . '</div></div>';
    }

    $heading = '<h2 style="border-bottom:2px solid #2c3e50;padding-bottom:4px">';
    $out .= $heading . 'Contact Information</h2>' . submissionEmailSectionTable($sections['contact']);
    $out .= $heading . 'Vehicle Factors</h2>' . submissionEmailSectionTable($factors);
    if ($sections['results']) {
        $out .= $heading . 'Calculation Results</h2>' . submissionEmailSectionTable($sections['results']);
    }
    $out .= '</div>';

    return '<html><body>' . $out . '</body></html>';
}

/** Plain-text alternative to renderSubmissionEmailHtml(). */
function renderSubmissionEmailText(array $s, bool $isResend = false): string {
    $sections = submissionEmailSections($s);
    $brake = submissionBrakeDescriptions($s['brake_suspension'] ?? []);

    $t = "WCMA CLASS DECLARATION\n" . submissionEmailSubmittedLine($s, $isResend) . "\n";
    if (!empty($s['calculated_class'])) $t .= "Calculated Class: {$s['calculated_class']}\n";

    $block = static function (string $title, array $rows): string {
        $out = "\n" . $title . "\n";
        foreach ($rows as [$label, $value]) $out .= "$label: $value\n";
        return $out;
    };
    $t .= $block('CONTACT INFORMATION', $sections['contact']);
    $t .= $block('VEHICLE FACTORS', $sections['factors']);
    if ($brake) $t .= "Brake & Suspension:\n  - " . implode("\n  - ", $brake) . "\n";
    if ($sections['results']) $t .= $block('CALCULATION RESULTS', $sections['results']);

    return $t;
}
