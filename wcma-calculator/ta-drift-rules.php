<?php
// wcma-calculator/ta-drift-rules.php
//
// Summer TA/Drift tech as data (spec 2026-09-29-ta-drift-tech-design.md §1): one shared checklist
// and gear list built from what the clubs' regulations have in common. Sources: Canadian National
// SoloSport Regulations – Time Attack (2020, adopted by WCMA); NASCC 2025 Time Attack Regulations v6;
// WSCC 2024 Time Attack Supplementary Regulations v4.0; WSCC 2026 Ice Race Supp Regs §3 (Ice Drift).
// Update here when the regs change and bump TA_DRIFT_RULES_VERSION. Pure: no DB, no HTML.

require_once __DIR__ . '/tech-sheet-data.php';

const TA_DRIFT_RULES_VERSION = 1;

/** The section shown only when the sheet says a roll bar or cage is fitted. */
const TA_DRIFT_CAGED_SECTION = 'cage';

const TA_DRIFT_CHECKLIST_SECTIONS = [
    'brakes_wheels' => ['label' => 'Brakes, Wheels & Tires', 'items' => [
        'brakes'    => 'Brakes work at all four wheels, no leaks',
        'lug_nuts'  => 'All lug nuts present and tight',
        'hubcaps'   => 'Hubcaps and trim rings removed',
        'tires'     => 'DOT tires in good condition',
    ]],
    'engine' => ['label' => 'Engine Compartment', 'items' => [
        'battery_mount'    => 'Battery securely held down (no bungee cords)',
        'battery_terminal' => 'Positive battery terminal insulated',
        'no_leaks'         => 'No fluid leaks',
        'catch_cans'       => 'Coolant overflow and crankcase breather drain to catch cans',
    ]],
    'interior' => ['label' => 'Interior', 'items' => [
        'no_loose_items'    => 'Nothing loose in the cabin or trunk; nothing hanging from the mirror',
        'seats'             => 'Seats securely mounted (an aftermarket seat at four points or more)',
        'belts'             => 'Seat belts in good condition (factory 3-point is fine; a harness only with a roll bar or cage)',
        'fire_extinguisher' => "Fire extinguisher within the driver's reach, on a quick-release mount (recommended)",
    ]],
    'exterior' => ['label' => 'Exterior', 'items' => [
        'windows'      => 'Windows up unless window nets are fitted; sunroof or convertible top closed and locked',
        'mirrors'      => 'At least one rear-view mirror',
        'brake_lights' => 'Brake lights working',
        'tow_points'   => 'Front and rear tow points (factory ones are fine)',
        'numbers'      => 'Car number on both sides',
    ]],
    TA_DRIFT_CAGED_SECTION => ['label' => 'Roll Bar or Cage', 'items' => [
        'cage_spec' => 'Roll bar or cage built to WCMA spec',
        'harness'   => '5- or 6-point harness fitted',
    ]],
    'regulations' => ['label' => 'Supplementary Regulations', 'items' => [
        'supps_read' => 'I have read the {club} supplementary regulations and my car complies',
    ]],
];

const TA_DRIFT_EQUIPMENT_ITEMS = [
    'helmet'               => ['label' => 'Helmet', 'has_rating' => true, 'optional' => false],
    'clothing'             => ['label' => 'Long pants, closed-toe shoes and a sleeved natural-fibre shirt', 'has_rating' => false, 'optional' => false],
    'head_neck_restraints' => ['label' => 'Head & Neck Restraint (SFI 38.1 or FIA 8858)', 'has_rating' => false, 'optional' => true],
];

/** The TA/Drift checklist for one sheet, shaped like TECH_CHECKLIST_SECTIONS. */
function taDriftChecklistSections(bool $caged, string $club): array {
    $out = [];
    foreach (TA_DRIFT_CHECKLIST_SECTIONS as $key => $section) {
        if ($key === TA_DRIFT_CAGED_SECTION && !$caged) continue;
        $section['items'] = array_map(fn(string $label): string => str_replace('{club}', $club, $label), $section['items']);
        $out[$key] = $section;
    }
    return $out;
}

/** Driver gear for a TA/Drift sheet. A head and neck restraint is required only in a caged car. */
function taDriftEquipmentItems(bool $caged): array {
    $items = TA_DRIFT_EQUIPMENT_ITEMS;
    $items['head_neck_restraints']['optional'] = !$caged;
    return $items;
}

/** The helmet standard, for the form's equipment card. */
function taDriftHelmetNote(bool $caged): string {
    return $caged
        ? 'Helmet: Snell SA2015 or newer. A head and neck restraint (SFI 38.1 or FIA 8858) is required in a caged car.'
        : 'Helmet: Snell SA or M 2015 or newer, or ECE 22.05 made in the last 10 years.';
}
