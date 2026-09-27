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
        $iceSuffix = ($e['discipline'] ?? 'summer') === 'ice' ? ' · Ice ' . (string)($e['host_club'] ?? '') : '';
        $out .= '<option value="' . (int)$e['id'] . '"' . ((int)$e['id'] === $vm['eventId'] ? ' selected' : '') . '>'
            . h((string)$e['name']) . ' (' . h(date('M j, Y', strtotime((string)$e['event_date']))) . ')' . h($iceSuffix) . '</option>';
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
    // Insert the link before garageClassHtml()'s closing </p> so it stays on the same line as the class,
    // instead of appending after the </p> where it wraps onto its own line.
    $classCell = $classLink === '' ? garageClassHtml($row['class'])
        : preg_replace('/<\/p>$/', $classLink . '</p>', garageClassHtml($row['class']));
    if (($row['ice_class'] ?? '') !== '') $classCell = '<p>' . h($row['ice_class']) . '</p>';
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
        . '<div><dt>Class</dt><dd>' . $classCell . '</dd></div>'
        . '<div><dt>Tech sheet</dt><dd>' . $sheetCell . '</dd></div>'
        . '<div><dt>Car tech</dt><dd><span class="hub-status ' . h(homeStatusClass($row['status']['state'])) . '">'
        . h(techCarStatusLabel($row['status'], $vm['season'], (string)($vm['discipline'] ?? 'summer'))) . '</span></dd></div>'
        . '<div><dt>Gear</dt><dd>' . ($chips !== '' ? $chips : 'No drivers') . '</dd></div>'
        . '</dl></article>';
}

/**
 * The Classing tab: every declaration, searchable and filterable (spec §5). Admins also get bulk delete.
 *
 * @param array{filters: array, rows: array, total: int, pages: int, isAdmin: bool, csrf: string} $vm
 */
function renderInspectClassingHtml(array $vm): string {
    $f = $vm['filters'];
    $admin = $vm['isAdmin'];
    $out = '<h1 class="hub-page-title">Classing</h1>'
        . '<form method="get" action="inspect.php" class="hub-card inspect-filters">'
        . '<input type="hidden" name="action" value="classing">'
        . ($f['car'] > 0 ? '<input type="hidden" name="car" value="' . (int)$f['car'] . '">' : '')
        . '<label for="classing-q">Search</label>'
        . '<input type="search" id="classing-q" name="q" value="' . h($f['q']) . '" placeholder="Name, email, car number, make or model">'
        . '<label for="classing-class">Class</label><select id="classing-class" name="class"><option value="">All classes</option>';
    foreach (INSPECT_CLASSES as $c) {
        $out .= '<option value="' . h($c) . '"' . ($c === $f['class'] ? ' selected' : '') . '>' . h($c) . '</option>';
    }
    $out .= '</select><label for="classing-season">Season</label>'
        . '<input type="number" id="classing-season" name="season" min="2000" max="2100" value="' . ($f['season'] > 0 ? (int)$f['season'] : '') . '">'
        . '<label for="classing-status">Review</label><select id="classing-status" name="status"><option value="">Any status</option>';
    foreach (INSPECT_DECLARATION_STATUSES as $st) {
        $out .= '<option value="' . h($st) . '"' . ($st === $f['status'] ? ' selected' : '') . '>' . h(declarationReviewLabel($st)) . '</option>';
    }
    $out .= '</select><button type="submit" class="hub-btn">Search</button> <a href="inspect.php?action=classing">Clear</a></form>';
    if ($f['car'] > 0) {
        $out .= '<p>Showing one car\'s declarations. <a href="inspect.php?action=classing">Show every car</a></p>';
    }

    $total = (int)$vm['total'];
    $out .= '<p class="list-summary">' . $total . ' ' . ($total === 1 ? 'declaration' : 'declarations')
        . ($vm['pages'] > 1 ? ' · page ' . (int)$f['page'] . ' of ' . (int)$vm['pages'] : '')
        . ' · <a href="inspect.php?action=declarations-export">Export all as CSV</a></p>';
    if ($admin && $vm['rows']) {
        $out .= '<form method="post" action="inspect.php?action=declarations-bulk-delete" id="bulk-delete-form">' . inspectCsrfField($vm['csrf'])
            . '<button type="submit" id="bulk-delete-btn" class="hub-btn hub-btn--secondary" disabled data-confirm-template="Permanently delete {n} selected declaration(s) and their files?">Delete selected</button></form>';
    }

    $out .= '<table class="data-table inspect-stack" id="classing-table"><thead><tr>'
        . ($admin ? '<th><input type="checkbox" id="classing-select-all" aria-label="Select all declarations"></th>' : '')
        . '<th>Submitted</th><th>Car</th><th>Entrant</th><th>Class</th><th>Review</th><th>Actions</th></tr></thead><tbody>';
    if (!$vm['rows']) {
        $out .= '<tr><td colspan="' . ($admin ? 7 : 6) . '" class="empty-row">No declarations match.</td></tr>';
    }
    foreach ($vm['rows'] as $s) {
        $id = (int)$s['id'];
        $status = (string)$s['review_status'];
        $out .= '<tr>'
            . ($admin ? '<td data-label="Select"><input type="checkbox" class="submission-select" form="bulk-delete-form" name="ids[]" value="' . $id . '" aria-label="Select declaration ' . $id . '"></td>' : '')
            . '<td data-label="Submitted">' . h(date('M j, Y', strtotime((string)$s['submitted_at']))) . '</td>'
            . '<td data-label="Car">#' . h((string)($s['car_number'] ?? '?')) . ' ' . h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) . '</td>'
            . '<td data-label="Entrant">' . h((string)$s['name']) . '</td>'
            . '<td data-label="Class"><strong>' . h((string)($s['calculated_class'] ?? '—')) . '</strong></td>'
            . '<td data-label="Review"><span class="hub-status ' . h(homeStatusClass($status)) . '">' . h(declarationReviewLabel($status)) . '</span></td>'
            . '<td><a href="inspect.php?action=declaration&amp;id=' . $id . '">' . ($status === 'submitted' ? 'Review' : 'View') . '</a></td></tr>';
    }
    $out .= '</tbody></table>';

    if ($vm['pages'] > 1) {
        $out .= '<nav class="pagination" aria-label="Declaration pages">';
        if ($f['page'] > 1) $out .= '<a href="' . h(inspectClassingQuery($f, ['page' => $f['page'] - 1])) . '">&larr; Previous</a> ';
        $out .= '<span>Page ' . (int)$f['page'] . ' of ' . (int)$vm['pages'] . '</span>';
        if ($f['page'] < $vm['pages']) $out .= ' <a href="' . h(inspectClassingQuery($f, ['page' => $f['page'] + 1])) . '">Next &rarr;</a>';
        $out .= '</nav>';
    }
    return $out;
}

/**
 * One class declaration for an inspector: the review card, the calculation, the car and entrant, files
 * and this car's declaration history. Admins also get Edit contact details and Delete.
 *
 * @param array{sub: array, car: ?array, owner: ?array, reviewer: ?array, history: array, isAdmin: bool, csrf: string} $vm
 */
function renderInspectDeclarationHtml(array $vm): string {
    $s = $vm['sub'];
    $id = (int)$s['id'];
    $car = $vm['car'];
    $vehicle = trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model']);
    $out = '<p><a href="inspect.php?action=classing">&larr; Back to Classing</a></p>'
        . '<h1 class="hub-page-title">Class declaration: ' . h(($car !== null ? '#' . $car['car_number'] . ' ' : '') . $vehicle) . '</h1>'
        . inspectDeclarationReviewHtml($s, $vm['reviewer'], $vm['csrf'])
        . '<div class="hub-grid-2">'
        . '<section class="hub-card"><h2>Calculation</h2>' . inspectBreakdownHtml($s) . '</section>'
        . '<section class="hub-card"><h2>Car and entrant</h2>' . inspectDeclarationFactsHtml($s, $car, $vm['owner']) . inspectResendFormHtml($s, $vm['csrf']) . '</section>'
        . '</div>'
        . '<section class="hub-card"><h2>Uploaded files</h2>' . inspectDeclarationFilesHtml($s) . '</section>'
        . inspectDeclarationHistoryHtml($id, $car, $vm['history']);
    return $vm['isAdmin'] ? $out . inspectDeclarationAdminHtml($s, $vm['csrf']) : $out;
}

/** Status, who reviewed it, the note, and the Accept / Send back forms the status allows. */
function inspectDeclarationReviewHtml(array $s, ?array $reviewer, string $csrf): string {
    $id = (int)$s['id'];
    $status = (string)$s['review_status'];
    $out = '<section class="hub-card" id="declaration-review"><h2>Review</h2>'
        . '<p><span class="hub-status ' . h(homeStatusClass($status)) . '">' . h(declarationReviewLabel($status)) . '</span></p>';
    if (!empty($s['reviewed_at'])) {
        $out .= '<p>Reviewed' . ($reviewer !== null ? ' by ' . h((string)$reviewer['name']) : '')
            . ' on ' . h(date('M j, Y g:i A', strtotime((string)$s['reviewed_at']))) . '.</p>';
    }
    if (trim((string)($s['reviewer_note'] ?? '')) !== '') {
        $out .= '<p class="garage-note"><strong>Note sent to the competitor:</strong> ' . nl2br(h((string)$s['reviewer_note'])) . '</p>';
    }
    if ($status === 'superseded') {
        return $out . '<p>The competitor has re-declared this car, so this declaration is no longer the current one. Review the newest one in the history below.</p></section>';
    }
    if (declarationReviewAllowed($status, 'accept')) {
        $out .= '<p class="form-hint">Accept when the declared weight, power and modifications match what you know of the car.</p>'
            . '<form method="post" action="inspect.php?action=declaration-accept">' . inspectCsrfField($csrf)
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<button type="submit" class="hub-btn" id="declaration-accept-btn">Accept declaration</button></form>';
    }
    if (declarationReviewAllowed($status, 'send_back')) {
        $out .= '<form method="post" action="inspect.php?action=declaration-send-back" id="declaration-sendback-form">' . inspectCsrfField($csrf)
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<label for="declaration-note">What needs to change?</label>'
            . '<textarea id="declaration-note" name="note" rows="4" maxlength="' . DECLARATION_NOTE_MAX . '" required></textarea>'
            . '<p class="form-hint">The competitor gets this note by email and sees it in their Garage. They respond by re-declaring the car.</p>'
            . '<button type="submit" class="hub-btn hub-btn--secondary" id="declaration-sendback-btn">Send back</button></form>';
    }
    return $out . '</section>';
}

/** Class ranges mirrored from js/calculator.js determineClass(), used only to annotate the breakdown. */
function inspectClassForRatio(float $ratio): ?array {
    $ranges = [['GTU', -INF, 6.00], ['GT1', 6.00, 8.00], ['GT2', 8.00, 10.00], ['GT3', 10.00, 12.00],
               ['GT4', 12.00, 14.00], ['IT1', 14.00, 18.00], ['IT2', 18.00, INF]];
    if ($ratio <= 0) return null;
    foreach ($ranges as $range) {
        if ($ratio >= $range[1] && $ratio < $range[2]) return $range;
    }
    return null;
}

function inspectFormatClassRange(array $range): string {
    [$name, $min, $max] = $range;
    if ($min === -INF) return $name . ' (< ' . number_format($max, 2) . ')';
    return $name . ' (' . number_format($min, 2) . ($max === INF ? '+' : ' – ' . number_format($max - 0.01, 2)) . ')';
}

function inspectModRow(string $label, ?string $display, float $value): string {
    if (!$display && $value == 0) return '';
    return '<tr><td>' . h($label) . '</td><td class="inspect-num">' . ($value >= 0 ? '+' : '') . number_format($value, 2) . '</td>'
        . '<td class="inspect-muted">' . ($display ? h($display) : '—') . '</td></tr>';
}

function inspectBreakdownHtml(array $s): string {
    $weight = (float)$s['competition_weight'];
    $base = (float)$s['base_ratio'];
    $range = inspectClassForRatio($base);
    $brakes = json_decode((string)($s['brake_suspension'] ?? '[]'), true);
    $brakeText = is_array($brakes) ? implode(', ', array_map('strval', $brakes)) : '';
    $wf = (float)$s['weight_factor'];
    return '<table class="calc-table">'
        . '<tr><td>Base ratio</td><td class="inspect-num">' . number_format($base, 2) . '</td><td class="inspect-muted">'
        . number_format($weight, 0) . ' lbs ÷ ' . number_format((float)$s['declared_hp'], 0) . ' hp'
        . ($range !== null ? ' → ' . h(inspectFormatClassRange($range)) : '') . '</td></tr>'
        . '<tr><td>Weight factor</td><td class="inspect-num">' . ($wf >= 0 ? '+' : '') . number_format($wf, 2) . '</td><td class="inspect-muted">at ' . number_format($weight, 0) . ' lbs</td></tr>'
        . inspectModRow('Chassis', $s['chassis_display'], (float)$s['chassis_value'])
        . inspectModRow('Body mods', $s['body_mods_display'], (float)$s['body_mods_value'])
        . inspectModRow('Transmission', $s['transmission_display'], (float)$s['transmission_value'])
        . inspectModRow('Drivetrain', $s['drivetrain_display'], (float)$s['drivetrain_value'])
        . inspectModRow('Tires', $s['tires_display'], (float)$s['tires_value'])
        . ((float)$s['brake_suspension_value'] != 0 ? inspectModRow('Brake & susp.', $brakeText, (float)$s['brake_suspension_value']) : '')
        . '<tr class="total"><td>Modified ratio</td><td class="inspect-num">' . number_format((float)$s['modified_ratio'], 2) . '</td>'
        . '<td class="class-badge">' . h((string)($s['calculated_class'] ?? '—')) . '</td></tr></table>';
}

function inspectDeclarationFactsHtml(array $s, ?array $car, ?array $owner): string {
    $rows = [];
    if ($car !== null) {
        $colour = trim((string)($car['colour'] ?? ''));
        $rows[] = ['Car', '#' . $car['car_number'] . ' ' . garageCarTitle($car) . ($colour !== '' ? ' · ' . $colour : '')];
    }
    $rows[] = ['Entrant', $s['name'] . ' (' . $s['email'] . ')'];
    if ($owner !== null) $rows[] = ['Account', $owner['name'] . ' (' . $owner['email'] . ')'];
    $rows[] = ['Declared vehicle', trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])];
    $rows[] = ['Weight', $s['competition_weight'] . ' lbs'];
    $rows[] = ['Declared HP', (string)$s['declared_hp']];
    if (!empty($s['dyno_hp'])) $rows[] = ['Dyno HP', (string)$s['dyno_hp']];
    $rows[] = ['Submitted', date('F j, Y \a\t g:i A', strtotime((string)$s['submitted_at']))];
    $out = '<table class="detail-table">';
    foreach ($rows as [$label, $value]) {
        $out .= '<tr><td>' . h($label) . '</td><td>' . h((string)$value) . '</td></tr>';
    }
    if (trim((string)($s['comments'] ?? '')) !== '') {
        $out .= '<tr><td>Comments</td><td>' . nl2br(h((string)$s['comments'])) . '</td></tr>';
    }
    return $out . '</table>';
}

function inspectResendFormHtml(array $s, string $csrf): string {
    $count = (int)$s['email_send_count'];
    $history = $count > 0 && !empty($s['last_emailed_at'])
        ? 'Last emailed ' . date('M j, Y \a\t g:i A', strtotime((string)$s['last_emailed_at'])) . ' · sent ' . $count . ' ' . ($count === 1 ? 'time' : 'times')
        : 'Never emailed.';
    return '<p class="form-hint">' . h($history) . '</p>'
        . '<form method="post" action="inspect.php?action=declaration-resend" data-confirm="'
        . h('Re-send the declaration email to ' . $s['name'] . ' (' . $s['email'] . ') and the classing address?') . '">'
        . inspectCsrfField($csrf) . '<input type="hidden" name="id" value="' . (int)$s['id'] . '">'
        . '<button type="submit" class="hub-btn hub-btn--secondary">Re-send declaration email</button></form>';
}

function inspectDeclarationFilesHtml(array $s): string {
    $files = ['car_image' => ['Car image', $s['car_image_path'] ?? null], 'dyno_chart' => ['Dyno chart', $s['dyno_chart_path'] ?? null],
              'dyno_table' => ['Dyno table', $s['dyno_table_path'] ?? null]];
    $out = '';
    foreach ($files as $field => [$label, $path]) {
        if (!$path) continue;
        $url = 'inspect.php?action=declaration-file&id=' . (int)$s['id'] . '&field=' . $field;
        $isImage = in_array(strtolower(pathinfo((string)$path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true);
        $out .= '<p><strong>' . h($label) . '</strong></p>' . ($isImage
            ? '<img src="' . h($url) . '" class="file-thumb" data-lightbox alt="' . h($label) . '">'
            : '<a href="' . h($url) . '" target="_blank" rel="noopener">Open ' . h(basename((string)$path)) . '</a>');
    }
    return $out !== '' ? $out : '<p>No files uploaded.</p>';
}

function inspectDeclarationHistoryHtml(int $currentId, ?array $car, array $history): string {
    if ($car === null || !$history) return '';
    $out = '<section class="hub-card"><h2>This car\'s declarations</h2><table class="data-table inspect-stack">'
        . '<thead><tr><th>Submitted</th><th>Class</th><th>Review</th><th></th></tr></thead><tbody>';
    foreach ($history as $d) {
        $did = (int)$d['id'];
        $out .= '<tr><td data-label="Submitted">' . h(date('M j, Y', strtotime((string)$d['submitted_at']))) . '</td>'
            . '<td data-label="Class">' . h((string)($d['calculated_class'] ?? '—')) . '</td>'
            . '<td data-label="Review">' . h(declarationReviewLabel((string)$d['review_status'])) . '</td>'
            . '<td>' . ($did === $currentId ? 'This one' : '<a href="inspect.php?action=declaration&amp;id=' . $did . '">View</a>') . '</td></tr>';
    }
    return $out . '</tbody></table><p><a href="inspect.php?action=classing&amp;car=' . (int)$car['id'] . '">Open this car in Classing</a></p></section>';
}

/** Admin only: edit the entrant's contact details, and delete the declaration. */
function inspectDeclarationAdminHtml(array $s, string $csrf): string {
    $id = (int)$s['id'];
    $field = fn(string $name, string $label, string $type = 'text', bool $required = false): string =>
        '<label for="edit-' . $name . '">' . $label . '</label><input type="' . $type . '" id="edit-' . $name . '" name="' . $name . '" value="'
        . h((string)($s[$name] ?? '')) . '"' . ($required ? ' required' : '') . '>';
    return '<details class="hub-card"><summary>Edit contact details (admin)</summary>'
        . '<form method="post" action="inspect.php?action=declaration-update-contact" class="edit-form">' . inspectCsrfField($csrf)
        . '<input type="hidden" name="id" value="' . $id . '">'
        . $field('name', 'Name', 'text', true) . $field('email', 'Email', 'email', true)
        . $field('year', 'Year') . $field('make', 'Make') . $field('model', 'Model')
        . '<label for="edit-comments">Comments</label><textarea id="edit-comments" name="comments" rows="3">' . h((string)($s['comments'] ?? '')) . '</textarea>'
        . '<button type="submit" class="hub-btn">Save</button></form></details>'
        . '<form method="post" action="inspect.php?action=declaration-delete" data-confirm="Permanently delete this declaration and its files?">'
        . inspectCsrfField($csrf) . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="hub-btn hub-btn--link">Delete this declaration (admin)</button></form>';
}

/** The Review queue tab: everything waiting on an inspector, oldest first, each linking to its review card. */
function renderInspectQueueHtml(array $items): string {
    $out = '<h1 class="hub-page-title">Review queue</h1>';
    if (!$items) return $out . '<p class="hub-card">Nothing is waiting for review.</p>';
    $n = count($items);
    $out .= '<p>' . $n . ' ' . ($n === 1 ? 'item is' : 'items are') . ' waiting, oldest first.</p><ol class="hub-card inspect-queue">';
    foreach ($items as $item) {
        $out .= '<li class="hub-line"><span><strong>' . h($item['title']) . '</strong><br>'
            . '<span class="inspect-muted">' . h($item['detail']) . ' · waiting since ' . h(date('M j, g:i A', strtotime($item['since']))) . '</span></span>'
            . '<a class="hub-btn" href="' . h($item['url']) . '">Review</a></li>';
    }
    return $out . '</ol>';
}
