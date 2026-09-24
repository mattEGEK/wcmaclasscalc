<?php
// wcma-calculator/tech-status.php
//
// Pure functions (no DB, no HTML) for a car's annual tech status. A car is
// identified by owner + normalised car number + season (calendar year); its
// status is derived from all of that car's tech sheets in the season. Any
// accepted sheet accepts the car for the year.

/** Trim, uppercase, strip leading zeros. A number that is all zeros (e.g. "00") is kept as-is. */
function techCarNumberNorm(string $carNumber): string {
    $n = strtoupper(trim($carNumber));
    $stripped = ltrim($n, '0');
    return $stripped === '' ? $n : $stripped;
}

/** Calendar year of an event date ('YYYY-MM-DD...'); the current year if it cannot be read. */
function techSeasonFromDate(?string $eventDate): int {
    if ($eventDate !== null && preg_match('/^(\d{4})-\d{2}-\d{2}/', $eventDate, $m)) {
        return (int)$m[1];
    }
    return (int)date('Y');
}

/** Groups sheets that belong to the same car in the same season. */
function techCarKey(array $sheet): string {
    $norm = $sheet['car_number_norm'] ?? null;
    if ($norm === null || $norm === '') {
        $norm = techCarNumberNorm((string)($sheet['car_number'] ?? ''));
    }
    return (int)$sheet['user_id'] . '|' . $norm . '|' . (int)($sheet['season'] ?? 0);
}

/**
 * Derived status of one car from ITS tech sheets (same identity).
 * Precedence: accepted > needs_changes > pending_review > photos_draft > none.
 *
 * @param array[] $sheets tech_sheets rows for one car identity
 * @return array{state: string, via: ?string, sheet_id: ?int}
 */
function techCarStatus(array $sheets): array {
    usort($sheets, fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);

    foreach ($sheets as $s) {
        if (($s['status'] ?? '') === 'teched') {
            return ['state' => 'accepted', 'via' => $s['accepted_via'] ?? 'in_person', 'sheet_id' => (int)$s['id']];
        }
    }
    foreach (['needs_changes' => 'needs_changes', 'submitted' => 'pending_review', 'accepted' => 'pending_review', 'draft' => 'photos_draft'] as $photoStatus => $state) {
        foreach ($sheets as $s) {
            if (($s['photo_status'] ?? null) === $photoStatus) {
                return ['state' => $state, 'via' => null, 'sheet_id' => (int)$s['id']];
            }
        }
    }
    return ['state' => 'none', 'via' => null, 'sheet_id' => null];
}

function techCarStatusLabel(array $status, int $season): string {
    switch ($status['state']) {
        case 'accepted':       return ($status['via'] === 'photos' ? 'Pre-teched ' : 'Teched ') . $season;
        case 'needs_changes':  return 'Photos need changes';
        case 'pending_review': return 'Photos pending review';
        case 'photos_draft':   return 'Photos in progress';
        default:               return 'Needs tech at the track';
    }
}

function techCarStatusBadgeClass(string $state): string {
    if ($state === 'accepted') return 'badge-ok';
    if ($state === 'needs_changes') return 'badge-fail';
    return 'badge-pending';
}

/** @return array<string, array[]> sheets keyed by techCarKey() */
function techGroupSheetsByCar(array $sheets): array {
    $groups = [];
    foreach ($sheets as $s) {
        $groups[techCarKey($s)][] = $s;
    }
    return $groups;
}

/** Status of $sheet's car within $ownerSheets (which may include other cars and seasons). */
function techCarStatusForSheet(array $sheet, array $ownerSheets): array {
    $groups = techGroupSheetsByCar($ownerSheets);
    return techCarStatus($groups[techCarKey($sheet)] ?? []);
}

/**
 * One roster row per sheet in $eventSheets, with the derived status of that
 * sheet's car. $seasonSheets must include every sheet in the season(s) of
 * $eventSheets, because a car's other sheets decide its status.
 *
 * @return array<int, array{sheet: array, status: array}>
 */
function techBuildRoster(array $eventSheets, array $seasonSheets): array {
    $groups = techGroupSheetsByCar($seasonSheets);
    $rows = [];
    foreach ($eventSheets as $s) {
        $rows[] = ['sheet' => $s, 'status' => techCarStatus($groups[techCarKey($s)] ?? [$s])];
    }
    return $rows;
}

/** $filter: 'all' | 'needs_tech' (car not accepted) | 'accepted' | 'pending_review'. Unknown values mean 'all'. */
function techRosterFilter(array $rows, string $filter): array {
    if (!in_array($filter, ['needs_tech', 'accepted', 'pending_review'], true)) return $rows;
    return array_values(array_filter($rows, function (array $r) use ($filter): bool {
        $state = $r['status']['state'];
        if ($filter === 'pending_review') return $state === 'pending_review';
        $accepted = $state === 'accepted';
        return $filter === 'accepted' ? $accepted : !$accepted;
    }));
}

/** The event to show first: nearest active event on/after $today, else the most recent event, else 0 (all events). */
function techDefaultEventId(array $events, string $today): int {
    $upcoming = null;
    $latest = null;
    foreach ($events as $e) {
        if ($latest === null || $e['event_date'] > $latest['event_date']) $latest = $e;
        if ((int)$e['active'] === 1 && $e['event_date'] >= $today
            && ($upcoming === null || $e['event_date'] < $upcoming['event_date'])) {
            $upcoming = $e;
        }
    }
    return (int)(($upcoming ?? $latest)['id'] ?? 0);
}
