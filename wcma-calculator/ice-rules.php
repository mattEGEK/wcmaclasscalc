<?php
// wcma-calculator/ice-rules.php
//
// Ice racing rules as data: the host clubs, their classes, and the tech checklist for each class
// group. Sources: NASCC 2026 Ice Race Supp Regs + Street Safe Addendum; WSCC 2026 Ice Race
// Supplementary Regulations. Update here when the regs change and bump ICE_RULES_VERSION.
// Pure: no DB, no HTML.

const ICE_RULES_VERSION = 1;

const ICE_CLASS_GROUPS = ['drift', 'street_safe', 'caged'];

const ICE_CLUBS = [
    'NASCC' => ['label' => 'Northern Alberta Sports Car Club', 'classes' => [
        'SS'    => ['label' => 'Street Safe (FWD/RWD)', 'group' => 'street_safe', 'fhr' => false,
                    'note' => 'Uncaged, FWD/RWD, studless DOT winter tire ≤ $160, ≤ 3,150 lb, ≤ 110" wheelbase, built after 1972'],
        'SSAWD' => ['label' => 'Street Safe (AWD)', 'group' => 'street_safe', 'fhr' => false,
                    'note' => 'As SS; AWD cars are classed separately'],
        'NS'    => ['label' => 'No-Stud', 'group' => 'caged', 'fhr' => false,
                    'note' => '2WD, caged, studless DOT winter tire ≤ $160, min 165 mm'],
        'LS'    => ['label' => 'Limited Stud', 'group' => 'caged', 'fhr' => true,
                    'note' => '2WD, caged, spec bolted tires: 9 bolts/ft, 12 mm max protrusion'],
        'CH'    => ['label' => 'Chevette', 'group' => 'caged', 'fhr' => false,
                    'note' => 'Chevette/Acadian/Scooter/T1000 1976–87, stock 1.4/1.6, 155/80R13 bolted tires'],
        'CHSS'  => ['label' => 'Chevette Street Stud', 'group' => 'caged', 'fhr' => false,
                    'note' => 'Same as CH: Chevette/Acadian/Scooter/T1000 1976–87, stock 1.4/1.6, same bolted tire'],
        'AWD'   => ['label' => 'AWD', 'group' => 'caged', 'fhr' => true,
                    'note' => 'AWD, caged, ≤ 3,150 lb; tire type set with the organizers in advance'],
    ]],
    'WSCC' => ['label' => 'Winnipeg Sports Car Club', 'classes' => [
        'DRIFT'   => ['label' => 'Ice Drift', 'group' => 'drift', 'fhr' => false,
                      'note' => 'Non-competitive lapping, any drivetrain, DOT winter or street-studded tires'],
        'FOI-SS'  => ['label' => 'Fire on Ice – Street Safe', 'group' => 'street_safe', 'fhr' => false,
                      'note' => 'Uncaged, FWD/RWD only, studless DOT winter tire, ≤ 3,150 lb published curb weight, ≤ 110" wheelbase, built after 1972'],
        'FOI-STD' => ['label' => 'Fire on Ice – Studded', 'group' => 'caged', 'fhr' => false,
                      'note' => 'Caged, 4-cyl ≤ 140 hp, ≥ 1,700 lb, spec bolted tires: 9 bolts/ft, 12 mm max protrusion'],
    ]],
];

const ICE_CHECKLIST_SECTIONS = [
    'drift' => [
        'vehicle_exterior' => ['label' => 'Vehicle Exterior', 'items' => [
            'tow_hooks'              => 'Front and rear tow hooks, installed and easy to reach',
            'lights'                 => 'Headlights, tail lights and 2 red brake lights working',
            'rear_light_recommended' => 'Rear-facing roof light (recommended)',
            'body_secure'            => 'Body panels, bumpers and exhaust secure',
        ]],
        'wheels_tires' => ['label' => 'Wheels & Tires', 'items' => [
            'tires_class'     => 'Tires meet class rules',
            'wheel_condition' => 'Wheel and tire condition',
        ]],
        'mechanical' => ['label' => 'Mechanical & Interior', 'items' => [
            'brakes_steering' => 'Brakes, steering and suspension sound',
            'battery'         => 'Battery securely mounted',
            'no_leaks'        => 'No fluid leaks',
            'no_loose_items'  => 'Interior clean, no loose items or sharp edges',
        ]],
    ],
    'street_safe' => [
        'under_vehicle' => ['label' => 'Under Vehicle', 'items' => [
            'brakes_steering'  => 'Brakes at every wheel; steering and suspension stock type and sound',
            'exhaust_exit'     => 'Exhaust secure and exits behind the driver',
            'fuel_system'      => 'Fuel system stock',
            'no_leaks'         => 'No fluid leaks',
        ]],
        'wheels_tires' => ['label' => 'Wheels & Tires', 'items' => [
            'tires_class'     => 'Studless DOT winter tires (snowflake), min 165 mm, meet class rules',
            'wheel_condition' => 'Wheels and tires inside the fenders; no space-savers',
        ]],
        'engine_compartment' => ['label' => 'Engine Compartment', 'items' => [
            'battery' => 'Battery in stock location, secured at 2 points, positive terminal insulated',
        ]],
        'vehicle_interior' => ['label' => 'Vehicle Interior', 'items' => [
            'seats'          => 'Seats secure; factory seats have headrests; aftermarket seats on 4 points',
            'seat_belts'     => 'Factory 3-point belts working (5-point only in caged cars)',
            'airbags'        => 'Airbags',
            'no_loose_items' => 'No loose items or sharp edges',
            'mirrors'        => 'Both side mirrors and interior mirror',
        ]],
        'vehicle_exterior' => ['label' => 'Vehicle Exterior', 'items' => [
            'tow_hooks'       => 'Front and rear tow hooks, clearly marked',
            'crash_structure' => 'Factory crash structures and door beams intact; no structural rust',
            'windshield'      => 'Windshield: no crack through more than one layer; wiper works',
            'windows'         => 'Driver and passenger windows in place and closed; no aftermarket tint',
            'sunroof'         => 'Sunroof secured, or replaced with metal',
            'headlights'      => 'Two or more headlights (clear or blue), tail lights working',
            'brake_lights'    => 'Rear brake lights as per club rules',
            'rear_light'      => 'Rear-facing roof light at eye level',
            'appearance'      => 'Neat appearance; car number and class decals',
        ]],
    ],
    'caged' => [
        'under_vehicle' => ['label' => 'Under Vehicle', 'items' => [
            'brakes_steering' => 'Brakes at every wheel; steering and suspension sound',
            'exhaust_exit'    => 'Exhaust secure and exits behind the driver',
            'no_leaks'        => 'No fluid leaks',
        ]],
        'wheels_tires' => ['label' => 'Wheels & Tires', 'items' => [
            'tires_class'     => 'Tires meet class rules (spec studs: count and protrusion)',
            'wheel_condition' => 'Wheels and tires inside the fenders; no space-savers',
            'mud_flaps'       => 'Mud flaps behind the driven wheels',
        ]],
        'engine_compartment' => ['label' => 'Engine Compartment', 'items' => [
            'battery'     => 'Battery secured, positive terminal insulated (marine box if inside)',
            'catch_tanks' => 'Engine and radiator catch tanks (min. 1 L)',
            'fuel_system' => 'Fuel system and firewall; fuel pump outside the cockpit',
        ]],
        'vehicle_interior' => ['label' => 'Vehicle Interior', 'items' => [
            'roll_cage'      => 'Roll cage to WCMA spec, with ice roof reinforcement',
            'seat_mounted'   => "Driver's seat securely mounted",
            'harness'        => '5-point SFI/FIA harness, in date',
            'window_net'     => 'Window net and release',
            'kill_switch'    => 'Kill switch, clearly marked',
            'abs_disabled'   => 'ABS disabled',
            'no_loose_items' => 'No loose items or sharp edges',
            'mirrors'        => 'Mirrors (at least one outside and one inside)',
        ]],
        'vehicle_exterior' => ['label' => 'Vehicle Exterior', 'items' => [
            'tow_hooks'    => 'Front and rear tow hooks, clearly marked',
            'hood_pins'    => 'Hood (and trunk or hatch) pinned or double-latched',
            'windshield'   => 'Windshield glass or polycarbonate; wiper works',
            'headlights'   => 'Two or more headlights (clear or blue), tail lights working',
            'brake_lights' => 'Rear brake lights as per club rules',
            'rear_light'   => 'Rear-facing roof light at eye level',
            'bumpers'      => 'Bumpers stock or per the wood-bumper rule; no sharp ends',
            'appearance'   => 'Car numbers (10"+) and decals',
        ]],
    ],
];

/** Per-club wording, keyed by checklist item. A null value removes the item for that club. */
const ICE_CLUB_OVERRIDES = [
    'NASCC' => [
        'airbags'      => 'Airbags removed (disabling is not enough)',
        'brake_lights' => '4 rear brake lights, 2 on or above the trunk lid (red lenses)',
        'rear_light'   => 'Rear-facing 20 W+ roof light at eye level',
    ],
    'WSCC' => [
        'airbags'      => 'Airbags disabled or removed',
        'brake_lights' => '3 rear brake lights, 1 on or above the trunk lid (red lenses)',
        'rear_light'   => 'Rear-facing 55 W+ (1000 lm LED) amber or blue roof light at eye level',
        'catch_tanks'  => null,
        'abs_disabled' => null,
    ],
];

/** Gear levels that satisfy each group. */
const ICE_GEAR_ACCEPTS = [
    'drift'       => ['street_safe', 'caged'],
    'street_safe' => ['street_safe', 'caged'],
    'caged'       => ['caged'],
];

/** @return string[] */
function iceClubCodes(): array {
    return array_keys(ICE_CLUBS);
}

function iceClubLabel(string $club): ?string {
    return ICE_CLUBS[$club]['label'] ?? null;
}

/** @return ?array{code: string, label: string, group: string, note: string, fhr: bool} */
function iceClass(string $club, string $code): ?array {
    $class = ICE_CLUBS[$club]['classes'][$code] ?? null;
    return $class === null ? null : ['code' => $code] + $class;
}

/** @return array<string, string> code => "CODE — Label", in rule order */
function iceClassOptions(string $club): array {
    $out = [];
    foreach (ICE_CLUBS[$club]['classes'] ?? [] as $code => $class) {
        $out[$code] = $code . ' — ' . $class['label'];
    }
    return $out;
}

/** The checklist for one club and class group, shaped like TECH_CHECKLIST_SECTIONS. */
function iceChecklistSections(string $club, string $group): array {
    $overrides = ICE_CLUB_OVERRIDES[$club] ?? [];
    $out = [];
    foreach (ICE_CHECKLIST_SECTIONS[$group] ?? [] as $sectionKey => $section) {
        $items = [];
        foreach ($section['items'] as $key => $label) {
            if (array_key_exists($key, $overrides)) {
                if ($overrides[$key] === null) continue;
                $label = $overrides[$key];
            }
            $items[$key] = $label;
        }
        if ($items) $out[$sectionKey] = ['label' => $section['label'], 'items' => $items];
    }
    return $out;
}

/** True if gear checked at $level is good enough for a class in $group. */
function iceGearSatisfies(?string $level, string $group): bool {
    return $level !== null && in_array($level, ICE_GEAR_ACCEPTS[$group] ?? [], true);
}

/**
 * Discipline and host club from the admin event form. Summer events never keep a club.
 * @return array{ok: bool, discipline: string, club: ?string, error: ?string}
 */
function iceEventFields(array $post): array {
    $discipline = $post['discipline'] ?? 'summer';
    if (!is_string($discipline) || !in_array($discipline, ['summer', 'ice'], true)) {
        return ['ok' => false, 'discipline' => 'summer', 'club' => null, 'error' => 'Choose summer or ice.'];
    }
    if ($discipline === 'summer') {
        return ['ok' => true, 'discipline' => 'summer', 'club' => null, 'error' => null];
    }
    $club = $post['host_club'] ?? '';
    if (!is_string($club) || !in_array($club, iceClubCodes(), true)) {
        return ['ok' => false, 'discipline' => 'ice', 'club' => null, 'error' => 'Choose the host club for an ice event.'];
    }
    return ['ok' => true, 'discipline' => 'ice', 'club' => $club, 'error' => null];
}
