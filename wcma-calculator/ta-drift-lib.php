<?php
// wcma-calculator/ta-drift-lib.php
//
// TA/Drift (spec 2026-09-29-ta-drift-tech-design.md): the formats an entry runs, the tech tier they
// need, and the approval ladder (race tech and race gear also cover TA/Drift). Pure: no DB, no HTML.
require_once __DIR__ . '/tech-status.php';

const ENTRY_FORMATS = ['race' => 'Race', 'ta' => 'Time Attack', 'drift' => 'Drift'];
const ENTRY_FORMAT_ERROR = 'Choose at least one: Race, Time Attack or Drift.';
const ENTRY_NO_HOST_CLUB = 'This event has no host club yet. Ask an admin.';

/** Stored formats ('race,ta') as known formats in ENTRY_FORMATS order. Blank or unknown-only reads as race. */
function entryFormatsParse(?string $stored): array {
    $picked = array_map('trim', explode(',', (string)$stored));
    $out = array_values(array_filter(array_keys(ENTRY_FORMATS), fn(string $f): bool => in_array($f, $picked, true)));
    return $out ?: ['race'];
}

/** Formats as stored in event_plans.formats: known ones, comma-separated, in ENTRY_FORMATS order. */
function entryFormatsStore(array $formats): string {
    return implode(',', array_values(array_filter(array_keys(ENTRY_FORMATS), fn(string $f): bool => in_array($f, $formats, true))));
}

/** How an entry's formats read to people: "Time Attack · Drift". */
function entryFormatsLabel(array $formats): string {
    return implode(' · ', array_map(fn(string $f): string => ENTRY_FORMATS[$f], entryFormatsParse(implode(',', $formats))));
}

/** The tech an entry needs: race tech if it races, otherwise TA/Drift. The only home of this rule. */
function entryTechTier(array $formats): string {
    return in_array('race', $formats, true) ? TECH_TIER_RACE : TECH_TIER_TA_DRIFT;
}

/**
 * The formats a driver picked for one event. Ice events are always race (ice keeps its class-based
 * flow). Time Attack and Drift need the event's host club, because TA/Drift tech is per club.
 *
 * @param mixed $picked the posted list, e.g. $_POST['formats']
 * @return array{ok: bool, formats: string[], error: ?string}
 */
function entryFormatsValidate(array $event, $picked): array {
    if (($event['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE) {
        return ['ok' => true, 'formats' => ['race'], 'error' => null];
    }
    $fail = fn(string $msg): array => ['ok' => false, 'formats' => [], 'error' => $msg];
    if (!is_array($picked) || $picked === []) return $fail(ENTRY_FORMAT_ERROR);
    foreach ($picked as $f) {
        if (!is_string($f) || !isset(ENTRY_FORMATS[$f])) return $fail(ENTRY_FORMAT_ERROR);
    }
    $formats = entryFormatsParse(implode(',', $picked));
    if (array_diff($formats, ['race']) !== [] && trim((string)($event['host_club'] ?? '')) === '') {
        return $fail(ENTRY_NO_HOST_CLUB);
    }
    return ['ok' => true, 'formats' => $formats, 'error' => null];
}

function techSheetIsTaDrift(array $sheet): bool {
    return ($sheet['sheet_type'] ?? '') === SHEET_TYPE_TA_DRIFT;
}

/**
 * A car's TA/Drift standing at one club for one year (spec §2 approval ladder): accepted race tech
 * (any club) wins; otherwise the state of its TA/Drift sheets for that club and year.
 *
 * @param array{state: string, via: ?string, sheet_id: ?int} $race    techCarStatus() of the car's summer race sheets
 * @param array{state: string, via: ?string, sheet_id: ?int} $taDrift techCarStatus() of its TA/Drift sheets (club, year)
 * @return array{state: string, via: ?string, sheet_id: ?int, tier: string}
 */
function taDriftCarTechStatus(array $race, array $taDrift): array {
    if ($race['state'] === 'accepted') return $race + ['tier' => TECH_TIER_RACE];
    return $taDrift + ['tier' => TECH_TIER_TA_DRIFT];
}

/** True if a summer gear record is accepted at a level that covers $tier. Race needs race level (NULL). */
function gearCoversTier(?array $gear, string $tier): bool {
    if ($gear === null || ($gear['status'] ?? '') !== 'accepted') return false;
    return $tier === TECH_TIER_TA_DRIFT || ($gear['level'] ?? null) === null;
}
