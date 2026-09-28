<?php
// wcma-calculator/tech-sheet-next.php
//
// The submitted tech sheet page's title and "What's next" list (mobile UX spec 2026-09-28 §A5).
// Pure: no DB, no session, no echo. Callers must have loaded view_helpers.php (h()) and
// ice-rules.php (iceClubLabel()).

function techSheetViewTitle(array $sheet, ?array $event): string {
    $ice = ($sheet['discipline'] ?? 'summer') === 'ice';
    $parts = [$ice ? 'Ice tech sheet' : 'Tech sheet',
              '#' . $sheet['car_number'] . ' ' . trim($sheet['car_make'] . ' ' . $sheet['car_model'])];
    if ($event !== null && trim((string)($event['name'] ?? '')) !== '') $parts[] = (string)$event['name'];
    return implode(' — ', $parts);
}

/**
 * @param array  $carStatus     techCarStatusForSheet() output
 * @param string $gearChipsHtml renderGearChips() for the sheet's drivers, '' when there are none
 */
function renderTechSheetNextStepsHtml(array $sheet, ?array $event, array $carStatus, string $gearChipsHtml): string {
    $id = (int)$sheet['id'];
    $steps = [];
    $state = (string)($carStatus['state'] ?? 'none');
    if ($state === 'pending_review') {
        $steps[] = '<strong>Car pre-tech</strong><p>Your photos are with an inspector.</p>';
    } elseif ($state === 'needs_changes') {
        $steps[] = '<strong>Car pre-tech</strong><p>An inspector asked for some photos to be retaken.</p>'
            . '<a class="hub-btn" href="tech-sheets.php?action=pretech&amp;id=' . $id . '">Retake photos</a>';
    } elseif ($state !== 'accepted' && ($sheet['status'] ?? '') === 'submitted') {
        $steps[] = '<strong>Pre-tech with photos (optional)</strong><p>Send photos of the car so an inspector can check it before the event, and skip inspection at the track.</p>'
            . '<a class="hub-btn" href="tech-sheets.php?action=pretech&amp;id=' . $id . '">Pre-tech with photos</a>';
    }
    if ($gearChipsHtml !== '') {
        $steps[] = '<strong>Driver gear</strong><p>Add gear photos, or have it checked at the track.</p>' . $gearChipsHtml;
    }
    $club = ($sheet['discipline'] ?? 'summer') === 'ice' ? iceClubLabel((string)($sheet['club'] ?? '')) : null;
    $eventName = $event !== null ? (string)$event['name'] : 'the event';
    $steps[] = '<strong>Register with the club</strong><p>The hub doesn\'t register you. Register for ' . h($eventName)
        . ' with ' . ($club !== null ? 'the ' . h($club) : 'the host club') . '.</p>';

    $out = '<section class="hub-card sheet-next no-print"><h2>What\'s next</h2><ol class="hub-todo">';
    foreach ($steps as $n => $step) {
        $out .= '<li class="hub-todo-item"><span class="hub-todo-n">' . ($n + 1) . '</span><div class="hub-todo-txt">' . $step . '</div></li>';
    }
    return $out . '</ol></section>';
}
