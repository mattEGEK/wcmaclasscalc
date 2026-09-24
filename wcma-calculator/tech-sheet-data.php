<?php
// wcma-calculator/tech-sheet-data.php

const TECH_CHECKLIST_SECTIONS = [
    'under_vehicle' => [
        'label' => 'Under Vehicle',
        'items' => [
            'steering_linkage'  => 'Steering linkage',
            'suspension_shocks' => 'Suspension & shocks',
            'wheel_bearing'     => 'Wheel bearing condition',
            'brakes_hoses'      => 'Brakes & hoses',
            'ball_joints'       => 'Ball joints, rod ends, bushings',
        ],
    ],
    'wheels_tires' => [
        'label' => 'Wheels & Tires',
        'items' => [
            'wheel_tire_condition' => 'Wheel and tire condition',
            'meets_class_criteria' => 'Meets class criteria',
        ],
    ],
    'engine_compartment' => [
        'label' => 'Engine Compartment',
        'items' => [
            'fuel_pump_lines'      => 'Fuel pump, lines & fittings — zero leaks',
            'oil_supply_lines'     => 'Oil supply tank, oil lines — security',
            'oil_catch_tank'       => 'Oil catch tank (min. 1L)',
            'coolant_hose'         => 'Coolant hose condition',
            'coolant_catch_tank'   => 'Coolant catch tank (min. 1L)',
            'battery_terminals'    => 'Battery terminal posts insulated',
            'battery_mount'        => 'Battery mount',
            'wiring_mounting'      => 'Wiring mounting and integrity',
            'carburetion_security' => 'Carburetion / fuel injection security',
        ],
    ],
    'vehicle_interior' => [
        'label' => 'Vehicle Interior',
        'items' => [
            'roll_cage_integrity'   => 'Roll bar padding / roll cage integrity',
            'accessories_mounted'   => 'Accessories properly mounted',
            'seat_mounted'          => "Driver's seat securely mounted",
            'rearview_mirror'       => 'Rearview mirror',
            'firewall_floor'        => 'Firewall and floor have no holes',
            'window_net_restraints' => 'Window net / arm restraints',
            'window_net_release'    => 'Window net release mechanism',
            'fire_extinguisher'     => 'Fire extinguisher (type & age)',
            'seat_belts'            => 'Seat belts (5 or 6 point, expiry date)',
        ],
    ],
    'vehicle_exterior' => [
        'label' => 'Vehicle Exterior',
        'items' => [
            'tow_points'          => 'Front and rear tow points',
            'appearance_markings' => 'Appearance and markings',
            'body_panels'         => 'Body panels secure',
            'windshield_windows'  => 'Windshield & windows',
            'headlights'          => 'Headlights (night and ice events)',
            'brake_tail_lights'   => 'Brake & tail lights as per class rules',
            'exhaust_system'      => 'Exhaust system meets regulations',
            'window_clips'        => 'Window clips or urethane',
            'bumper_condition'    => 'Bumper condition/attachment',
            'exterior_mirrors'    => 'Exterior mirrors (2)',
            'master_switch'       => 'Master switch — kills engine',
            'aero_mud_flaps'      => 'Aero and mud flaps secure',
            'rain_lights'         => 'Rain lights/rear facing light',
            'hood_trunk'          => 'Hood and trunk fastened properly',
        ],
    ],
    'fuel_tank_compartment' => [
        'label' => 'Fuel Tank Compartment',
        'items' => [
            'ventilation_check_valves' => 'Proper ventilation and check valves',
            'surge_tank_mounted'       => 'Surge tank safely mounted',
            'firewall_bulkhead'        => 'Firewall/bulkhead',
            'fuel_tank_mounted'        => 'Fuel tank/fuel cell securely mounted',
        ],
    ],
];

const TECH_DRIVER_EQUIPMENT_ITEMS = [
    'helmet'               => ['label' => 'Helmet', 'has_rating' => true,  'optional' => false],
    'goggles_visor'        => ['label' => 'Goggles or visor', 'has_rating' => false, 'optional' => false],
    'suit'                 => ['label' => 'Suit', 'has_rating' => true,  'optional' => false],
    'underwear'            => ['label' => 'Underwear (if required)', 'has_rating' => false, 'optional' => true],
    'shoes'                => ['label' => 'Shoes', 'has_rating' => false, 'optional' => false],
    'socks'                => ['label' => 'Socks', 'has_rating' => false, 'optional' => false],
    'gloves'               => ['label' => 'Gloves', 'has_rating' => false, 'optional' => false],
    'balaclava'            => ['label' => 'Balaclava', 'has_rating' => false, 'optional' => false],
    'head_neck_restraints' => ['label' => 'Head & Neck Restraints', 'has_rating' => false, 'optional' => false],
];

const TECH_ACCEPTANCE_DISCLAIMER = 'Acceptance confirms that what you submitted matches what was reviewed. It is not a certification that the vehicle or equipment is safe.';

/** Every checklist item key, mapped to null (unanswered) — the shape a fresh form starts from. */
function emptyChecklist(): array {
    $out = [];
    foreach (TECH_CHECKLIST_SECTIONS as $section) {
        foreach ($section['items'] as $key => $label) {
            $out[$key] = null;
        }
    }
    return $out;
}

/** Every driver-equipment item key, mapped to its unanswered shape. */
function emptyDriverEquipment(): array {
    $out = [];
    foreach (TECH_DRIVER_EQUIPMENT_ITEMS as $key => $def) {
        $out[$key] = ['competitor_confirmed' => false, 'value' => null];
    }
    return $out;
}

/**
 * True only if every checklist item key from TECH_CHECKLIST_SECTIONS is
 * present with status 'ok' or 'na'. fire_extinguisher/seat_belts may carry
 * extra free-text fields (type/age, expiry_date) but status still governs
 * completeness.
 */
function validateChecklist(array $checklist): bool {
    foreach (TECH_CHECKLIST_SECTIONS as $section) {
        foreach ($section['items'] as $key => $label) {
            if (!isset($checklist[$key]) || !is_array($checklist[$key])) return false;
            $status = $checklist[$key]['status'] ?? null;
            if (!in_array($status, ['ok', 'na'], true)) return false;
        }
    }
    return true;
}

/**
 * True only if: every non-optional item is either competitor_confirmed, and
 * helmet/suit additionally carry a non-blank rating value. The optional
 * "underwear" item may be left unconfirmed.
 */
function validateDriverEquipment(array $equipment): bool {
    foreach (TECH_DRIVER_EQUIPMENT_ITEMS as $key => $def) {
        if (!isset($equipment[$key]) || !is_array($equipment[$key])) return false;
        $confirmed = $equipment[$key]['competitor_confirmed'] ?? false;
        if (!$def['optional'] && $confirmed !== true) return false;
        if ($def['has_rating'] && trim((string)($equipment[$key]['value'] ?? '')) === '') return false;
    }
    return true;
}

/**
 * Validates and normalizes the decoded `drivers_json` payload (additional
 * endurance drivers, numbered 2-7, max 6 of them). Returns the normalized
 * driver rows (ready for db_replace_tech_sheet_drivers()) on success, or
 * null if anything is invalid: more than 6 drivers, a blank/missing name, a
 * driver_number outside [2,7], a duplicate driver_number, or equipment that
 * fails validateDriverEquipment().
 */
function validateAdditionalDrivers(array $driversInput): ?array {
    if (count($driversInput) > 6) return null;

    $rows = [];
    $seenNumbers = [];
    foreach ($driversInput as $d) {
        if (!is_array($d)) return null;

        $name = trim((string)($d['driver_name'] ?? ''));
        if ($name === '') return null;

        $number = $d['driver_number'] ?? null;
        if (!is_int($number) && !(is_string($number) && ctype_digit($number))) return null;
        $number = (int)$number;
        if ($number < 2 || $number > 7) return null;
        if (isset($seenNumbers[$number])) return null;
        $seenNumbers[$number] = true;

        $equipment = is_array($d['equipment'] ?? null) ? $d['equipment'] : [];
        if (!validateDriverEquipment($equipment)) return null;

        $rows[] = [
            'driver_number' => $number,
            'driver_name' => $name,
            'equipment_json' => json_encode($equipment),
        ];
    }
    return $rows;
}
