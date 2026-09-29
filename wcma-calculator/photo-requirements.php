<?php
// wcma-calculator/photo-requirements.php
//
// Versioned list of the photos a competitor can submit to be "pre-teched".
// Edit tiers or add photos here when the regulations change; bump the version
// when the list changes. Sources are the WCMA 2026 Technical Regulations
// (Appendix references in each 'reg' field).

require_once __DIR__ . '/tech-status.php';   // DISCIPLINE_ICE
require_once __DIR__ . '/ice-rules.php';     // iceClass(), ICE_CLASS_GROUPS, iceClubCodes()

const PHOTO_REQUIREMENTS_VERSION = 1;

const PHOTO_HELMET_STANDARDS = ['SA2020', 'SA2025', 'FIA 8860-2010', 'FIA 8859-2015', 'FIA 8860-2018'];
const PHOTO_FHR_STANDARDS    = ['FIA 8858-2002', 'FIA 8858-2010', 'SFI 38.1'];

const PHOTO_REQUIREMENTS = [
    // ── Car: required ────────────────────────────────────────────────────────
    'front_34' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 3.D, 2.U', 'typed' => [],
        'label' => 'Front three-quarter view',
        'guidance' => 'Whole front of the car with the car number and the front tow point visible.',
    ],
    'rear_34' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.S, 2.U', 'typed' => [],
        'label' => 'Rear three-quarter view',
        'guidance' => 'Whole rear of the car with the exhaust exit and the rear tow point visible.',
    ],
    'side_driver' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 3', 'typed' => [],
        'label' => "Driver's side",
        'guidance' => 'Full side view showing the number, class designation, minimum weight on the door, and WCMA decals.',
    ],
    'side_passenger' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 3', 'typed' => [],
        'label' => "Passenger's side",
        'guidance' => 'Full side view showing the number, class designation and decals.',
    ],
    'cage_overall' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 1', 'typed' => [],
        'label' => 'Roll cage, overall',
        'guidance' => 'The whole cage as seen from inside the car.',
    ],
    'cage_mounts' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 1', 'typed' => [],
        'label' => 'Main hoop and mounting points',
        'guidance' => 'Close view of the main hoop and where the cage attaches to the chassis.',
    ],
    'cage_padding' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.H.3', 'typed' => [],
        'label' => 'Cage padding',
        'guidance' => 'Padding on every cage member the driver or helmet could contact.',
    ],
    'seat_harness' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.A', 'typed' => [],
        'label' => 'Seat and harness, installed',
        'guidance' => 'Driver seat with the harness installed and its mounting points visible.',
    ],
    'harness_date' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.A.1.c',
        'typed' => [['name' => 'date', 'label' => 'Date stamp (MM/YYYY)', 'type' => 'month_year']],
        'label' => 'Harness date stamp',
        'guidance' => 'Close-up of the harness label so the date stamp and the standard (SFI 16.1 or FIA 8853) are readable.',
    ],
    'window_net' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.E', 'typed' => [],
        'label' => 'Window net',
        'guidance' => 'The driver-side window net installed, showing how it attaches to the car.',
    ],
    'fire_system' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.B',
        'typed' => [['name' => 'date', 'label' => 'Service or expiry date (MM/YYYY)', 'type' => 'month_year']],
        'label' => 'Fire suppression system',
        'guidance' => 'The bottle with its gauge and date label readable.',
    ],
    'kill_switch' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.C', 'typed' => [],
        'label' => 'Kill switch and its marking',
        'guidance' => 'The switch and the red-spark-on-blue-triangle marking that identifies it.',
    ],
    'battery' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.T', 'typed' => [],
        'label' => 'Battery',
        'guidance' => 'The battery hold-down and the insulated terminals.',
    ],
    'engine_bay' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.F, 2.K, 2.M', 'typed' => [],
        'label' => 'Engine bay',
        'guidance' => 'The engine bay showing the oil and coolant catch tanks and the firewall.',
    ],
    'interior' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.H', 'typed' => [],
        'label' => 'Interior, overall',
        'guidance' => 'The whole cockpit with no loose objects and no flammable trim.',
    ],

    // ── Car: conditional (only if it applies to the car) ─────────────────────
    'seat_label' => [
        'scope' => 'car', 'tier' => 'conditional', 'reg' => 'App. 2.A.2', 'typed' => [],
        'label' => 'Seat label',
        'guidance' => 'For plastic or composite seats: the SFI or FIA certification label.',
    ],
    'fuel_cell' => [
        'scope' => 'car', 'tier' => 'conditional', 'reg' => 'App. 2.J.8', 'typed' => [],
        'label' => 'Fuel cell installation',
        'guidance' => 'The fuel cell in its container with the FIA FT3 or SFI 28.3 label readable.',
    ],
    'windshield_clips' => [
        'scope' => 'car', 'tier' => 'conditional', 'reg' => 'App. 2.D.1', 'typed' => [],
        'label' => 'Polycarbonate windshield clips',
        'guidance' => 'The retaining clips on a polycarbonate windshield.',
    ],
    'scattershield' => [
        'scope' => 'car', 'tier' => 'conditional', 'reg' => 'App. 2.O', 'typed' => [],
        'label' => 'Scattershield and driveshaft hoops',
        'guidance' => 'Scattershield and driveshaft safety hoops (tube-frame cars).',
    ],
    'ballast' => [
        'scope' => 'car', 'tier' => 'conditional', 'reg' => 'App. 2.P', 'typed' => [],
        'label' => 'Ballast mounting',
        'guidance' => 'How any ballast is bolted in.',
    ],
    'aero' => [
        'scope' => 'car', 'tier' => 'conditional', 'reg' => 'Sections 3.2.D, 3.3.D', 'typed' => [],
        'label' => 'Splitter or wing',
        'guidance' => 'Any front splitter or rear wing fitted to the car.',
    ],

    // ── Gear: per driver ─────────────────────────────────────────────────────
    'helmet_label' => [
        'scope' => 'gear', 'tier' => 'required', 'reg' => 'App. 4.B',
        'typed' => [
            ['name' => 'standard', 'label' => 'Standard', 'type' => 'select', 'options' => PHOTO_HELMET_STANDARDS],
            ['name' => 'date', 'label' => 'Date (MM/YYYY)', 'type' => 'month_year'],
        ],
        'label' => 'Helmet certification label',
        'guidance' => 'The inside label showing the certification standard and date.',
    ],
    'suit_label' => [
        'scope' => 'gear', 'tier' => 'required', 'reg' => 'App. 4.D',
        'typed' => [['name' => 'rating', 'label' => 'Rating (e.g. SFI 3.2A/5)', 'type' => 'text']],
        'label' => 'Race suit label',
        'guidance' => 'The suit label showing the rating.',
    ],
    'fhr_label' => [
        'scope' => 'gear', 'tier' => 'required', 'reg' => 'App. 4.C',
        'typed' => [
            ['name' => 'standard', 'label' => 'Standard', 'type' => 'select', 'options' => PHOTO_FHR_STANDARDS],
            ['name' => 'date', 'label' => 'Date (MM/YYYY)', 'type' => 'month_year'],
        ],
        'label' => 'Frontal head restraint label',
        'guidance' => 'The label on the frontal head restraint showing the standard and date.',
    ],
    'gear_flatlay' => [
        'scope' => 'gear', 'tier' => 'required', 'reg' => 'App. 4.E', 'typed' => [],
        'label' => 'Gloves, shoes, socks and balaclava',
        'guidance' => 'All four items laid out together in one photo.',
    ],
    // Required by App. 4.B.5 but not currently enforced, so recommended only.
    // Flip 'tier' to 'required' (and bump the version) if enforcement starts.
    'helmet_back' => [
        'scope' => 'gear', 'tier' => 'recommended', 'reg' => 'App. 4.B.5', 'typed' => [],
        'label' => 'Helmet back label',
        'guidance' => 'The back of the helmet showing name, date of birth and allergies.',
    ],
    'underwear_label' => [
        'scope' => 'gear', 'tier' => 'conditional', 'reg' => 'App. 4.D', 'typed' => [],
        'label' => 'Fire-resistant underwear label',
        'guidance' => 'For suits that require it: the underwear label.',
    ],
];

// ── Ice (2026-09-27 spec §1). Keys are prefixed ice_ so a key alone identifies its requirement. ──
const ICE_PHOTO_REQUIREMENTS_VERSION = 1;

const ICE_HELMET_STANDARDS = [
    'Snell SA2015', 'Snell SA2020', 'Snell SA2025', 'FIA 8860-2010', 'FIA 8859-2015', 'FIA 8860-2018',
    'Snell M2015', 'Snell M2020', 'ECE 22.05', 'ECE 22.06',
];

const ICE_PHOTO_REQUIREMENTS = [
    // ── Car ──
    'ice_front_34' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['drift', 'street_safe', 'caged'], 'typed' => [],
        'label' => 'Front three-quarter view',
        'guidance' => 'Whole front of the car with the car number and the front tow hook visible.',
    ],
    'ice_rear_34' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['drift', 'street_safe', 'caged'], 'typed' => [],
        'label' => 'Rear three-quarter view',
        'guidance' => 'Whole rear of the car with the rear tow hook, the exhaust exit and the rear roof light visible.',
    ],
    'ice_side_driver' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['street_safe', 'caged'], 'typed' => [],
        'label' => "Driver's side",
        'guidance' => 'Full side view showing the car number and class decal.',
    ],
    'ice_side_passenger' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['street_safe', 'caged'], 'typed' => [],
        'label' => "Passenger's side",
        'guidance' => 'Full side view showing the car number and class decal.',
    ],
    'ice_tires' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['drift', 'street_safe', 'caged'], 'typed' => [],
        'label' => 'Tire close-up',
        'guidance' => 'One tire showing tread and sidewall: the snowflake mark and size for studless tires, or the stud pattern and how far the studs stick out for studded tires.',
    ],
    'ice_windshield' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['street_safe', 'caged'], 'typed' => [],
        'label' => 'Windshield',
        'guidance' => 'The whole windshield, so any cracks are visible.',
    ],
    'ice_interior' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['street_safe', 'caged'], 'typed' => [],
        'label' => 'Interior, overall',
        'guidance' => 'Seats with headrests, the belts, and no loose items.',
    ],
    'ice_airbags' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['street_safe'], 'typed' => [],
        'label' => 'Airbags',
        'guidance' => 'The steering wheel and dash where the airbags are.',
        'club_guidance' => [
            'NASCC' => 'The steering wheel and dash showing the airbags removed, not just disabled.',
            'WSCC' => 'The steering wheel and dash showing the airbags removed or disabled.',
        ],
    ],
    'ice_battery' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['street_safe', 'caged'], 'typed' => [],
        'label' => 'Battery',
        'guidance' => 'The battery hold-down and the insulated positive terminal.',
    ],
    'ice_brake_lights' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['street_safe', 'caged'], 'typed' => [],
        'label' => 'Brake lights',
        'guidance' => 'The rear of the car with the brake lights on.',
        'club_guidance' => [
            'NASCC' => 'The rear of the car with all 4 brake lights on (2 on or above the trunk lid).',
            'WSCC' => 'The rear of the car with all 3 brake lights on (1 on or above the trunk lid).',
        ],
    ],
    'ice_cage' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['caged'], 'typed' => [],
        'label' => 'Roll cage, overall',
        'guidance' => 'The whole cage from inside the car, including the roof reinforcement bar.',
    ],
    'ice_seat_harness' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['caged'], 'typed' => [],
        'label' => 'Seat and harness, installed',
        'guidance' => "The driver's seat with the harness installed and its mounting points visible.",
    ],
    'ice_harness_date' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['caged'],
        'typed' => [['name' => 'date', 'label' => 'Date stamp (MM/YYYY)', 'type' => 'month_year']],
        'label' => 'Harness date stamp',
        'guidance' => 'Close-up of the harness label so the date stamp and the standard are readable.',
    ],
    'ice_window_net' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['caged'], 'typed' => [],
        'label' => 'Window net',
        'guidance' => "The driver-side window net installed, showing how it attaches.",
    ],
    'ice_kill_switch' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['caged'], 'typed' => [],
        'label' => 'Kill switch and its marking',
        'guidance' => 'The switch and the marking that identifies it.',
    ],
    'ice_engine_bay' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['caged'], 'typed' => [],
        'label' => 'Engine bay',
        'guidance' => 'The engine bay showing the firewall.',
        'club_guidance' => [
            'NASCC' => 'The engine bay showing the engine and radiator catch tanks and the firewall.',
        ],
    ],
    'ice_mud_flaps' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['caged'], 'typed' => [],
        'label' => 'Mud flaps',
        'guidance' => 'The mud flaps behind the driven wheels.',
    ],

    // ── Gear ──
    'ice_helmet_label' => [
        'scope' => 'gear', 'tier' => 'required',
        'typed' => [
            ['name' => 'standard', 'label' => 'Standard', 'type' => 'select', 'options' => ICE_HELMET_STANDARDS],
            ['name' => 'date', 'label' => 'Date (MM/YYYY)', 'type' => 'month_year'],
        ],
        'label' => 'Helmet certification label',
        'guidance' => 'The inside label showing the certification standard and date.',
    ],
    'ice_suit_label' => [
        'scope' => 'gear', 'tier' => 'required',
        'typed' => [['name' => 'rating', 'label' => 'Rating (e.g. SFI 3.2A/1, or FR coveralls)', 'type' => 'text']],
        'label' => 'Suit or coverall label',
        'guidance' => 'The label showing the suit rating, or that the coveralls are fire resistant.',
    ],
    'ice_gloves_shoes' => [
        'scope' => 'gear', 'tier' => 'required', 'typed' => [],
        'label' => 'Gloves and shoes',
        'guidance' => 'The gloves and shoes laid out together in one photo.',
    ],
    'ice_fhr_label' => [
        'scope' => 'gear', 'tier' => 'conditional',
        'typed' => [
            ['name' => 'standard', 'label' => 'Standard', 'type' => 'select', 'options' => PHOTO_FHR_STANDARDS],
            ['name' => 'date', 'label' => 'Date (MM/YYYY)', 'type' => 'month_year'],
        ],
        'label' => 'Frontal head restraint label',
        'guidance' => 'The label on the frontal head restraint showing the standard and date.',
        'applies_label' => 'This driver races a class that needs one (NASCC LS or AWD)',
    ],
];

// ── TA/Drift (2026-09-29 spec §1). Keys are prefixed tad_; 'caged_only' shots need a roll bar or cage. ──
const TA_DRIFT_PHOTO_REQUIREMENTS_VERSION = 1;

const TA_DRIFT_PHOTO_REQUIREMENTS = [
    // ── Car ──
    'tad_front_34' => [
        'scope' => 'car', 'tier' => 'required', 'typed' => [],
        'label' => 'Front three-quarter view',
        'guidance' => 'Whole front of the car with the car number visible.',
    ],
    'tad_rear_34' => [
        'scope' => 'car', 'tier' => 'required', 'typed' => [],
        'label' => 'Rear three-quarter view',
        'guidance' => 'Whole rear of the car with the car number visible.',
    ],
    'tad_interior' => [
        'scope' => 'car', 'tier' => 'required', 'typed' => [],
        'label' => "Interior from the driver's door",
        'guidance' => 'The seats, belts and floor, showing nothing is loose in the cabin.',
    ],
    'tad_battery' => [
        'scope' => 'car', 'tier' => 'required', 'typed' => [],
        'label' => 'Battery hold-down',
        'guidance' => 'The battery tie-down and the covered positive terminal.',
    ],
    'tad_tow_front' => [
        'scope' => 'car', 'tier' => 'required', 'typed' => [],
        'label' => 'Front tow point',
        'guidance' => 'The front tow hook, eye or strap. Factory ones are fine.',
    ],
    'tad_tow_rear' => [
        'scope' => 'car', 'tier' => 'required', 'typed' => [],
        'label' => 'Rear tow point',
        'guidance' => 'The rear tow hook, eye or strap. Factory ones are fine.',
    ],
    'tad_cage' => [
        'scope' => 'car', 'tier' => 'required', 'typed' => [], 'caged_only' => true,
        'label' => 'Roll bar or cage and harness',
        'guidance' => 'The roll bar or cage and the harness, from the open driver door.',
    ],
    // ── Gear ──
    'tad_helmet_label' => [
        'scope' => 'gear', 'tier' => 'required',
        'typed' => [
            ['name' => 'standard', 'label' => 'Standard', 'type' => 'select', 'options' => ICE_HELMET_STANDARDS],
            ['name' => 'date', 'label' => 'Date (MM/YYYY)', 'type' => 'month_year'],
        ],
        'label' => 'Helmet certification label',
        'guidance' => 'The inside label showing the certification standard and date.',
    ],
    'tad_fhr_label' => [
        'scope' => 'gear', 'tier' => 'required', 'caged_only' => true,
        'typed' => [
            ['name' => 'standard', 'label' => 'Standard', 'type' => 'select', 'options' => PHOTO_FHR_STANDARDS],
            ['name' => 'date', 'label' => 'Date (MM/YYYY)', 'type' => 'month_year'],
        ],
        'label' => 'Head and neck restraint label',
        'guidance' => 'The label on the head and neck restraint showing the standard and date.',
    ],
];

/** True if $subject takes the TA/Drift list for $scope: a ta_drift sheet (car), or a gear subject the caller marked photo_tier ta_drift. */
function photoSubjectIsTaDrift(array $subject, string $scope): bool {
    if (($subject['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE) return false;
    return $scope === 'car'
        ? ($subject['sheet_type'] ?? '') === SHEET_TYPE_TA_DRIFT
        : ($subject['photo_tier'] ?? '') === TECH_TIER_TA_DRIFT;
}

/** Requirements for one scope ('car' or 'gear'), or all of them, keyed by requirement key. */
function photoRequirements(?string $scope = null): array {
    if ($scope === null) return PHOTO_REQUIREMENTS;
    return array_filter(PHOTO_REQUIREMENTS, fn(array $r): bool => $r['scope'] === $scope);
}

/** One requirement (summer or ice) with its 'key' added, or null if the key is unknown. */
function photoRequirementByKey(string $key): ?array {
    if (isset(PHOTO_REQUIREMENTS[$key])) return PHOTO_REQUIREMENTS[$key] + ['key' => $key];
    if (isset(ICE_PHOTO_REQUIREMENTS[$key])) return ICE_PHOTO_REQUIREMENTS[$key] + ['key' => $key];
    if (isset(TA_DRIFT_PHOTO_REQUIREMENTS[$key])) return TA_DRIFT_PHOTO_REQUIREMENTS[$key] + ['key' => $key];
    return null;
}

/**
 * The photos that apply to one subject: a tech_sheets row (scope 'car') or a gear_records row
 * (scope 'gear'). Summer subjects (and an empty array) get the summer list. An ice tech sheet gets
 * the car shots for its class group; an ice gear record gets the ice gear shots. Each def gets its
 * club's guidance and the list 'version' it is stored under.
 * A ta_drift sheet, or a gear subject with photo_tier 'ta_drift', gets the TA/Drift list (cage shots only when 'caged').
 *
 * @return array<string, array> key => requirement
 */
function photoRequirementsFor(array $subject, string $scope): array {
    if (photoSubjectIsTaDrift($subject, $scope)) {
        $out = [];
        foreach (TA_DRIFT_PHOTO_REQUIREMENTS as $key => $def) {
            if ($def['scope'] !== $scope) continue;
            if (!empty($def['caged_only']) && empty($subject['caged'])) continue;
            $out[$key] = $def + ['version' => TA_DRIFT_PHOTO_REQUIREMENTS_VERSION];
        }
        return $out;
    }
    if (($subject['discipline'] ?? DISCIPLINE_SUMMER) !== DISCIPLINE_ICE) {
        return array_map(fn(array $r): array => $r + ['version' => PHOTO_REQUIREMENTS_VERSION], photoRequirements($scope));
    }
    $club = (string)($subject['club'] ?? '');
    $group = null;
    if ($scope === 'car') {
        $class = iceClass($club, (string)($subject['class'] ?? ''));
        if ($class === null) return [];
        $group = $class['group'];
    }
    $out = [];
    foreach (ICE_PHOTO_REQUIREMENTS as $key => $def) {
        if ($def['scope'] !== $scope) continue;
        if ($group !== null && !in_array($group, $def['groups'], true)) continue;
        $def['guidance'] = $def['club_guidance'][$club] ?? $def['guidance'];
        unset($def['club_guidance']);
        $out[$key] = $def + ['version' => ICE_PHOTO_REQUIREMENTS_VERSION];
    }
    return $out;
}

/** One requirement on $subject's list, with 'key' added, or null if the key is not on that list. */
function photoRequirementForSubject(array $subject, string $scope, string $key): ?array {
    $list = photoRequirementsFor($subject, $scope);
    return isset($list[$key]) ? $list[$key] + ['key' => $key] : null;
}

/** The ice gear level a helmet standard suggests: SA/FIA → caged, M/ECE → street-safe. Null if unknown. */
function iceGearLevelForHelmet(string $standard): ?string {
    if (preg_match('/^(Snell SA|FIA )/', $standard)) return 'caged';
    if (preg_match('/^(Snell M|ECE )/', $standard)) return 'street_safe';
    return null;
}

/**
 * Validates the typed values posted with a photo against the requirement's
 * typed field definitions. Blank values are dropped. Returns the normalised
 * [name => value] map, or null if any provided value is unknown or invalid.
 */
function photoValidateTypedValue(array $requirement, array $input): ?array {
    $defs = [];
    foreach ($requirement['typed'] as $def) {
        $defs[$def['name']] = $def;
    }

    $out = [];
    foreach ($input as $name => $value) {
        if (!isset($defs[$name]) || !is_string($value)) return null;
        $value = trim($value);
        if ($value === '') continue;

        switch ($defs[$name]['type']) {
            case 'month_year':
                if (!preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/', $value)) return null;
                break;
            case 'select':
                if (!in_array($value, $defs[$name]['options'], true)) return null;
                break;
            case 'text':
                if (strlen($value) > 100) return null;
                break;
            default:
                return null;
        }
        $out[$name] = $value;
    }
    return $out;
}

/**
 * Requirement keys still missing from $requirements for a complete pre-tech set: every 'required'
 * photo, plus any 'conditional' photo marked as applying. 'recommended' photos never count.
 *
 * @return string[]
 */
function photoSetMissingFrom(array $requirements, array $presentKeys, array $applicableConditionalKeys): array {
    $missing = [];
    foreach ($requirements as $key => $def) {
        $needed = $def['tier'] === 'required'
            || ($def['tier'] === 'conditional' && in_array($key, $applicableConditionalKeys, true));
        if ($needed && !in_array($key, $presentKeys, true)) {
            $missing[] = $key;
        }
    }
    return $missing;
}

/** The summer list's missing keys for $scope. Kept for callers that have no subject. */
function photoSetMissingRequired(string $scope, array $presentKeys, array $applicableConditionalKeys): array {
    return photoSetMissingFrom(photoRequirements($scope), $presentKeys, $applicableConditionalKeys);
}
