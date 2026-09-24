<?php
// wcma-calculator/photo-requirements.php
//
// Versioned list of the photos a competitor can submit to be "pre-teched".
// Edit tiers or add photos here when the regulations change; bump the version
// when the list changes. Sources are the WCMA 2026 Technical Regulations
// (Appendix references in each 'reg' field).

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

/** Requirements for one scope ('car' or 'gear'), or all of them, keyed by requirement key. */
function photoRequirements(?string $scope = null): array {
    if ($scope === null) return PHOTO_REQUIREMENTS;
    return array_filter(PHOTO_REQUIREMENTS, fn(array $r): bool => $r['scope'] === $scope);
}

/** One requirement with its 'key' added, or null if the key is unknown. */
function photoRequirementByKey(string $key): ?array {
    if (!isset(PHOTO_REQUIREMENTS[$key])) return null;
    return PHOTO_REQUIREMENTS[$key] + ['key' => $key];
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
 * Requirement keys still missing for a complete pre-tech set: every
 * 'required' photo, plus any 'conditional' photo the competitor said applies.
 * 'recommended' photos never count.
 *
 * @param string[] $presentKeys              keys that already have a photo
 * @param string[] $applicableConditionalKeys conditional keys marked "applies to my car"
 * @return string[]
 */
function photoSetMissingRequired(string $scope, array $presentKeys, array $applicableConditionalKeys): array {
    $missing = [];
    foreach (photoRequirements($scope) as $key => $def) {
        $needed = $def['tier'] === 'required'
            || ($def['tier'] === 'conditional' && in_array($key, $applicableConditionalKeys, true));
        if ($needed && !in_array($key, $presentKeys, true)) {
            $missing[] = $key;
        }
    }
    return $missing;
}
