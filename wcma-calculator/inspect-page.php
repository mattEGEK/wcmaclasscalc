<?php
// wcma-calculator/inspect-page.php
//
// Markup for the Inspector section (spec §5): the event roster, the review queue, the Classing list
// and the declaration page. Pure view functions: no DB, no session, no echo. Callers must have
// loaded view_helpers.php (h()), cars-lib.php (declarationReviewLabel()), home-page.php
// (homeStatusClass()), garage-page.php (garageClassHtml(), garageCarTitle()), gear-chips.php
// (renderGearChips()), tech-status.php, declaration-review-lib.php and inspect-lib.php.

function inspectCsrfField(string $csrf): string {
    return '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">';
}

/**
 * The Event roster tab: an event picker and filter, then one card per car.
 *
 * @param array{events: array, eventId: int, filter: string, rows: array, counts: array<string,int>, season: int, csrf: string} $vm
 */
function renderInspectRosterHtml(array $vm): string {
    $out = '<h1 class="hub-page-title">Event roster</h1>';
    if (!$vm['events']) {
        return $out . '<p class="hub-card">No events yet. An admin adds events under Admin, Events.</p>';
    }
    $out .= '<form method="get" action="inspect.php" class="hub-card inspect-filters">'
        . '<label for="roster-event">Event</label><select id="roster-event" name="event">';
    foreach ($vm['events'] as $e) {
        $out .= '<option value="' . (int)$e['id'] . '"' . ((int)$e['id'] === $vm['eventId'] ? ' selected' : '') . '>'
            . h((string)$e['name']) . ' (' . h(date('M j, Y', strtotime((string)$e['event_date']))) . ')</option>';
    }
    $out .= '</select><label for="roster-filter">Show</label><select id="roster-filter" name="filter">';
    foreach (INSPECT_ROSTER_FILTERS as $key => $label) {
        $out .= '<option value="' . h($key) . '"' . ($key === $vm['filter'] ? ' selected' : '') . '>'
            . h($label) . ' (' . (int)($vm['counts'][$key] ?? 0) . ')</option>';
    }
    $out .= '</select><button type="submit" class="hub-btn">Show</button></form>';

    if (!$vm['rows']) {
        $empty = (int)($vm['counts']['all'] ?? 0) === 0
            ? 'No cars are tagged for this event and no tech sheets are in yet.'
            : 'No cars match this filter.';
        return $out . '<p class="hub-card">' . $empty . '</p>';
    }
    foreach ($vm['rows'] as $row) {
        $out .= inspectRosterRowHtml($row, $vm);
    }
    return $out;
}

/** One car on the roster: plate, car and owner, then class, tech sheet, car tech and gear. */
function inspectRosterRowHtml(array $row, array $vm): string {
    $car = $row['car'];
    $sheet = $row['sheet'];
    $current = $row['class']['current'];
    $classLink = $current === null ? '' : ' <a href="inspect.php?action=declaration&amp;id=' . (int)$current['id'] . '">'
        . ($current['review_status'] === 'submitted' ? 'Review' : 'View') . '</a>';
    $sheetCell = $sheet === null
        ? '<span class="hub-status hub-status--todo">No sheet yet</span>'
        : '<span class="hub-status ' . ($sheet['status'] === 'teched' ? 'hub-status--ok">Accepted' : 'hub-status--info">Submitted') . '</span>'
            . ' <a href="inspect.php?action=tech-sheet&amp;id=' . (int)$sheet['id'] . '">' . ($sheet['status'] === 'teched' ? 'View' : 'Review') . '</a>';
    $chips = renderGearChips($row['gear_links'], 'admin', [
        'sheet_season' => $vm['season'], 'csrf' => $vm['csrf'], 'sheet_id' => $sheet !== null ? (int)$sheet['id'] : 0,
        'hidden' => ['back' => 'roster', 'filter' => $vm['filter']],
    ]);

    return '<article class="hub-card inspect-row">'
        . '<div class="inspect-row-head"><span class="hub-plate">' . h((string)$car['car_number']) . '</span>'
        . '<div><h2>' . h(garageCarTitle($car)) . '</h2><p class="inspect-row-sub">' . h((string)$car['owner_name'])
        . (empty($car['tagged']) ? ' · has a sheet, not tagged' : '') . '</p></div></div>'
        . '<dl class="inspect-facts">'
        . '<div><dt>Class</dt><dd>' . garageClassHtml($row['class']) . $classLink . '</dd></div>'
        . '<div><dt>Tech sheet</dt><dd>' . $sheetCell . '</dd></div>'
        . '<div><dt>Car tech</dt><dd><span class="hub-status ' . h(homeStatusClass($row['status']['state'])) . '">'
        . h(techCarStatusLabel($row['status'], $vm['season'])) . '</span></dd></div>'
        . '<div><dt>Gear</dt><dd>' . ($chips !== '' ? $chips : 'No drivers') . '</dd></div>'
        . '</dl></article>';
}
