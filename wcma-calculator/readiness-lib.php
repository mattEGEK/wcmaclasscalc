<?php
// wcma-calculator/readiness-lib.php
//
// What a competitor still has to do for the events they tagged (spec §3). buildReadiness() is
// pure: no DB, no HTML. loadReadinessInputs() (Task 5) gathers its input from the database.
// Callers must have loaded tech-status.php and gear-lib.php.

function readinessItem(string $kind, string $subjectType, int $subjectId, string $state, string $label,
                       string $detail = '', ?array $action = null, ?array $atTrack = null): array {
    return ['kind' => $kind, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'state' => $state,
            'label' => $label, 'detail' => $detail, 'action' => $action, 'at_track' => $atTrack];
}

function readinessDeclaration(array $car, ?array $decl, int $season): array {
    $id = (int)$car['id'];
    $n = '#' . $car['car_number'];
    $redeclare = ['label' => 'Re-declare class', 'url' => 'calculator.php?car=' . $id];
    $inSeason = $decl !== null && (int)substr((string)$decl['submitted_at'], 0, 4) === $season;
    if ($decl !== null && $decl['review_status'] === 'needs_changes') {
        return readinessItem('declaration', 'car', $id, 'todo', "Your class declaration for $n needs changes", 'An inspector asked for changes.', $redeclare);
    }
    if (!$inSeason) {
        return readinessItem('declaration', 'car', $id, 'todo', "Declare class for $n", 'Declare once a season, or whenever the car changes.', ['label' => 'Declare class', 'url' => 'calculator.php?car=' . $id]);
    }
    if ($decl['review_status'] === 'accepted') {
        return readinessItem('declaration', 'car', $id, 'done', "$n class declared: " . $decl['calculated_class']);
    }
    return readinessItem('declaration', 'car', $id, 'info', "Class declaration for $n is with an inspector");
}

/**
 * Car tech or gear, which share one status model. $status is techCarStatus()/gearStatus() output.
 * $names: label (todo), doneLabel (sprintf with via word + season), atTrackLabel, retakeLabel.
 */
function readinessTech(string $kind, string $subjectType, int $subjectId, array $status, int $season,
                       bool $atTrack, array $names, ?string $photosUrl, ?string $retakeUrl): array {
    switch ($status['state']) {
        case 'accepted':
            $via = ($status['via'] ?? 'in_person') === 'photos' ? 'pre-teched' : 'teched';
            return readinessItem($kind, $subjectType, $subjectId, 'done', sprintf($names['doneLabel'], $via, $season));
        case 'pending_review':
            return readinessItem($kind, $subjectType, $subjectId, 'info', $names['pendingLabel']);
        case 'needs_changes':
            return readinessItem($kind, $subjectType, $subjectId, 'todo', $names['retakeLabel'], 'An inspector asked for some photos to be retaken.',
                $retakeUrl !== null ? ['label' => 'Retake photos', 'url' => $retakeUrl] : null);
    }
    if ($atTrack) {
        return readinessItem($kind, $subjectType, $subjectId, 'done', $names['atTrackLabel']);
    }
    $detail = $photosUrl !== null
        ? 'Pre-tech with photos, or bring it to tech at the track.'
        : 'Pre-tech with photos after you submit the tech sheet, or bring it to tech at the track.';
    return readinessItem($kind, $subjectType, $subjectId, 'todo', $names['label'], $detail,
        $photosUrl !== null ? ['label' => 'Add photos', 'url' => $photosUrl] : null,
        ['subject_type' => $subjectType, 'subject_id' => $subjectId, 'season' => $season]);
}

function buildReadiness(array $in): array {
    $today = (string)$in['today'];
    $upcoming = array_values(array_filter($in['events'], fn(array $e): bool => (string)$e['event_date'] >= $today));
    usort($upcoming, fn(array $a, array $b): int => strcmp((string)$a['event_date'], (string)$b['event_date']) ?: ((int)$a['id'] <=> (int)$b['id']));

    $carsByEvent = [];
    foreach ($in['plans'] as $p) {
        if (isset($in['cars'][(int)$p['car_id']])) $carsByEvent[(int)$p['event_id']][(int)$p['car_id']] = true;
    }
    $atTrack = array_flip($in['atTrack']);

    $sheetsByCar = [];
    foreach ($in['sheets'] as $s) $sheetsByCar[(int)$s['car_id']][] = $s;

    $seen = [];
    $events = [];
    $untagged = [];
    foreach ($upcoming as $event) {
        $eid = (int)$event['id'];
        if (empty($carsByEvent[$eid])) { $untagged[] = $event; continue; }
        $season = techSeasonFromDate((string)$event['event_date']);
        $items = [];
        foreach (array_keys($in['cars']) as $carId) {
            if (!isset($carsByEvent[$eid][$carId])) continue;
            $car = $in['cars'][$carId];
            $n = '#' . $car['car_number'];
            $seasonSheets = array_values(array_filter($sheetsByCar[$carId] ?? [], fn(array $s): bool => (int)$s['season'] === $season));
            $eventSheet = null;
            foreach ($sheetsByCar[$carId] ?? [] as $s) {
                if ((int)$s['event_id'] === $eid) { $eventSheet = $s; break; }
            }

            $once = function (string $key) use (&$seen, $season): bool {
                $k = $key . '@' . $season;
                if (isset($seen[$k])) return false;
                return $seen[$k] = true;
            };

            if ($once("declaration:$carId")) {
                $items[] = readinessDeclaration($car, $in['declarations'][$carId] ?? null, $season);
            }

            $items[] = $eventSheet !== null
                ? readinessItem('tech_sheet', 'car', $carId, 'done', 'Tech sheet for ' . $event['name'] . ' submitted')
                : readinessItem('tech_sheet', 'car', $carId, 'todo', "Submit a tech sheet for $n", 'Every car needs a tech sheet for every event.',
                    ['label' => 'Submit tech sheet', 'url' => "tech-sheets.php?action=new&car_id=$carId&event_id=$eid"]);

            if ($once("car_tech:$carId")) {
                $status = techCarStatus($seasonSheets);
                $byId = $seasonSheets;
                usort($byId, fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
                $latest = $byId ? (int)end($byId)['id'] : null;
                $items[] = readinessTech('car_tech', 'car', $carId, $status, $season, isset($atTrack["car:$carId"]), [
                    'label' => "Car tech for $n", 'doneLabel' => "Car tech $season for $n: %s", 'pendingLabel' => "Car tech photos for $n are with an inspector",
                    'retakeLabel' => "Retake photos for $n", 'atTrackLabel' => "Car tech for $n: you'll bring it to tech at the track",
                ], $latest !== null ? 'tech-sheets.php?action=pretech&id=' . $latest : null,
                   $status['sheet_id'] !== null ? 'tech-sheets.php?action=pretech&id=' . $status['sheet_id'] : null);
            }

            $driverIds = $eventSheet !== null
                ? array_values(array_unique(array_filter(array_merge([(int)($eventSheet['driver_id'] ?? 0)], array_map('intval', $in['sheetDrivers'][(int)$eventSheet['id']] ?? [])))))
                : [(int)$in['selfDriverId']];
            foreach ($driverIds as $did) {
                if (!isset($in['drivers'][$did]) || !$once("gear:$did")) continue;
                $name = (string)$in['drivers'][$did]['name'];
                $gear = $in['gear']["$did:$season"] ?? null;
                $status = $gear !== null ? gearStatus($gear) : ['state' => 'none', 'via' => null];
                $items[] = readinessTech('gear', 'driver', $did, $status, $season, isset($atTrack["driver:$did"]), [
                    'label' => "Gear for $name", 'doneLabel' => "Gear for $name: %s $season", 'pendingLabel' => "Gear photos for $name are with an inspector",
                    'retakeLabel' => "Retake gear photos for $name", 'atTrackLabel' => "Gear for $name: checked at the track",
                ], 'gear.php?action=start&driver_id=' . $did, $gear !== null ? 'gear.php?action=pretech&id=' . (int)$gear['id'] : null);
            }
        }
        $events[] = ['event' => $event, 'items' => $items];
    }
    return ['events' => $events, 'untagged' => $untagged];
}
