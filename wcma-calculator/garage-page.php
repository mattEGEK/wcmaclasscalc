<?php
// wcma-calculator/garage-page.php
//
// Markup for the Garage (spec §4): the car list, Add a car, the car page and a class declaration.
// Pure view functions: no DB, no session, no echo. Callers must have loaded view_helpers.php (h()),
// cars-lib.php, events-lib.php (EVENTS_NOT_REGISTERING), home-page.php (homeStatusClass()) and
// gear-chips.php (renderGearChips()).

function garageCsrfField(string $csrf): string {
    return '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">';
}

/** A one-button POST form to garage.php. $confirm adds a data-confirm prompt (js/confirm-modal.js). */
function garagePostForm(string $csrf, string $action, int $carId, string $button, string $btnClass = 'hub-btn hub-btn--link', string $confirm = '', array $extra = []): string {
    $out = '<form method="post" action="garage.php" class="garage-inline-form"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>'
        . garageCsrfField($csrf)
        . '<input type="hidden" name="action" value="' . h($action) . '">'
        . '<input type="hidden" name="car_id" value="' . $carId . '">';
    foreach ($extra as $name => $value) {
        $out .= '<input type="hidden" name="' . h((string)$name) . '" value="' . h((string)$value) . '">';
    }
    return $out . '<button type="submit" class="' . h($btnClass) . '">' . h($button) . '</button></form>';
}

function garageCarTitle(array $car): string {
    return trim(($car['year'] ?? '') . ' ' . $car['make'] . ' ' . $car['model']);
}

function garageCarSub(array $car): string {
    $parts = [];
    if (trim((string)($car['colour'] ?? '')) !== '') $parts[] = (string)$car['colour'];
    if (trim((string)($car['engine_cc'] ?? '')) !== '') $parts[] = $car['engine_cc'] . ' cc';
    return implode(' · ', $parts);
}

/** Class badge + review status word; "Accepted: X" when an earlier declaration was accepted (spec §2). */
function garageClassHtml(array $line): string {
    $cur = $line['current'];
    if ($cur === null) {
        return '<p class="garage-class"><span class="hub-status hub-status--todo">No class declared yet</span></p>';
    }
    $out = '<p class="garage-class"><span class="hub-class">' . h((string)$cur['calculated_class']) . '</span> '
        . '<span class="hub-status ' . h(homeStatusClass((string)$cur['review_status'])) . '">' . h(declarationReviewLabel((string)$cur['review_status'])) . '</span>';
    if ($line['earlierAccepted'] !== null) {
        $out .= ' <span class="garage-class-earlier">Accepted: ' . h((string)$line['earlierAccepted']['calculated_class']) . '</span>';
    }
    return $out . '</p>';
}

/** The six car inputs shared by Add a car and Edit details. */
function garageDetailsFields(array $values): string {
    $fields = [
        'car_number' => ['Car number', true],
        'year' => ['Year', false],
        'make' => ['Make', true],
        'model' => ['Model', true],
        'colour' => ['Colour', true],
        'engine_cc' => ['Engine size in cc (optional)', false],
    ];
    $out = '<div class="garage-fields">';
    foreach ($fields as $name => [$label, $required]) {
        $out .= '<div><label for="car-' . $name . '">' . h($label) . '</label>'
            . '<input type="text" id="car-' . $name . '" name="' . $name . '" maxlength="' . CARS_FIELD_MAX[$name] . '"'
            . ($required ? ' required' : '') . ($name === 'year' || $name === 'engine_cc' ? ' inputmode="numeric"' : '')
            . ' value="' . h((string)($values[$name] ?? '')) . '"></div>';
    }
    return $out . '</div>';
}

function garageRenderCard(array $card): string {
    $car = $card['car'];
    $id = (int)$car['id'];
    $out = '<article class="hub-card garage-card"><div class="garage-card-head">'
        . '<span class="hub-plate hub-plate--lg">' . h((string)$car['car_number']) . '</span><div>'
        . '<h2><a href="garage.php?car=' . $id . '">' . h(garageCarTitle($car)) . '</a></h2>';
    if (garageCarSub($car) !== '') $out .= '<p class="garage-card-sub">' . h(garageCarSub($car)) . '</p>';
    $out .= garageClassHtml($card['class']) . '</div></div>';

    $out .= '<dl class="garage-card-facts"><div><dt>Car tech</dt><dd><span class="hub-status ' . h(homeStatusClass($card['techState'])) . '">'
        . h($card['techLabel']) . '</span></dd></div>';
    $next = $card['next'];
    if ($next === null) {
        $out .= '<div><dt>Next event</dt><dd>Not going to any events yet</dd></div>';
    } else {
        $out .= '<div><dt>' . h((string)$next['event']['name']) . '</dt><dd>'
            . ($next['sheet'] !== null ? '<span class="hub-status hub-status--ok">Tech sheet in</span>' : '<span class="hub-status hub-status--todo">No tech sheet</span>')
            . '</dd></div>';
    }
    $out .= '</dl><div class="garage-card-actions">';
    if ($card['class']['current'] === null) {
        $out .= '<a class="hub-btn" href="calculator.php?car=' . $id . '">Declare class</a>';
    } elseif ($next !== null && $next['sheet'] === null) {
        $out .= '<a class="hub-btn" href="tech-sheets.php?action=new&amp;car_id=' . $id . '&amp;event_id=' . (int)$next['event']['id'] . '">Submit tech sheet</a>';
    }
    $out .= '<a class="hub-btn hub-btn--secondary" href="garage.php?car=' . $id . '">Open</a></div></article>';
    return $out;
}

function renderGarageListHtml(array $vm): string {
    $out = '<h1>Garage</h1>';
    if (!$vm['cards']) $out .= '<p class="hub-intro">Start by adding your car and declaring its class.</p>';
    $out .= '<div class="garage-grid">';
    foreach ($vm['cards'] as $card) $out .= garageRenderCard($card);
    $out .= '<a class="garage-add" href="garage.php?action=add">+ Add a car</a></div>';

    if ($vm['archived']) {
        $out .= '<details class="hub-card garage-archived"><summary>Archived cars (' . count($vm['archived']) . ')</summary>';
        foreach ($vm['archived'] as $car) {
            $out .= '<div class="hub-line"><span>' . h(carDisplayName($car)) . '</span><span>'
                . '<a href="garage.php?car=' . (int)$car['id'] . '">View</a> '
                . garagePostForm((string)$vm['csrf'], 'restore', (int)$car['id'], 'Restore') . '</span></div>';
        }
        $out .= '</details>';
    }
    return $out;
}

function renderAddCarHtml(array $vm): string {
    $out = '<h1>Add a car</h1>';
    if ($vm['error'] !== null) $out .= '<div class="form-messages show error" role="alert">' . h((string)$vm['error']) . '</div>';
    $out .= '<form method="post" action="garage.php" class="hub-card">' . garageCsrfField((string)$vm['csrf'])
        . '<input type="hidden" name="action" value="add">' . garageDetailsFields($vm['values'])
        . '<p class="form-hint">Car numbers are reserved on MotorsportReg. The hub records the number you enter.';
    if ($vm['msrLink'] !== null) {
        $out .= ' <a href="' . h((string)$vm['msrLink']['url']) . '" target="_blank" rel="noopener">' . h((string)$vm['msrLink']['label']) . ' &#8599;</a>';
    }
    $out .= '</p><button type="submit" class="hub-btn">Add car</button></form>'
        . '<p>Next, you will declare its class with the Class Calculator.</p>';
    return $out;
}

function renderGarageCarHtml(array $vm): string {
    $car = $vm['car'];
    $id = (int)$car['id'];
    $csrf = (string)$vm['csrf'];
    $archived = $car['archived_at'] !== null;
    $cur = $vm['class']['current'];

    $out = '<div class="garage-card-head"><span class="hub-plate hub-plate--lg">' . h((string)$car['car_number']) . '</span><div>'
        . '<h1>' . h(garageCarTitle($car)) . '</h1>';
    if (garageCarSub($car) !== '') $out .= '<p class="garage-card-sub">' . h(garageCarSub($car)) . '</p>';
    $out .= '</div></div>';
    if ($archived) {
        $out .= '<div class="form-messages show info">This car is archived. It is hidden from Home and from the tech sheet form. '
            . garagePostForm($csrf, 'restore', $id, 'Restore this car', 'hub-btn') . '</div>';
    }

    // Details
    $form = $vm['detailsForm'];
    $out .= '<section class="hub-card"><h2>Details</h2><details class="garage-edit"' . ($form !== null ? ' open' : '') . '><summary>Edit details</summary>';
    if ($form !== null) $out .= '<div class="form-messages show error" role="alert">' . h((string)$form['error']) . '</div>';
    $out .= '<form method="post" action="garage.php">' . garageCsrfField($csrf)
        . '<input type="hidden" name="action" value="update-car"><input type="hidden" name="car_id" value="' . $id . '">'
        . garageDetailsFields($form['values'] ?? $car) . '<button type="submit" class="hub-btn">Save details</button></form></details></section>';

    // Class
    $out .= '<section class="hub-card"><h2>Class</h2>' . garageClassHtml($vm['class']);
    if ($cur !== null) {
        $out .= '<p>Declared ' . h(date('M j, Y', strtotime((string)$cur['submitted_at']))) . ' · '
            . h((string)$cur['competition_weight']) . ' lbs · ' . h((string)$cur['declared_hp']) . ' HP</p>';
        if (trim((string)($cur['reviewer_note'] ?? '')) !== '') {
            $out .= '<p class="garage-note"><strong>Inspector\'s note:</strong> ' . h((string)$cur['reviewer_note']) . '</p>';
        }
    }
    $out .= '<p class="garage-card-actions">';
    if (!$archived) $out .= '<a class="hub-btn" href="calculator.php?car=' . $id . '">' . ($cur !== null ? 'Re-declare class' : 'Declare class') . '</a>';
    if ($cur !== null) $out .= '<a class="hub-btn hub-btn--secondary" href="garage.php?declaration=' . (int)$cur['id'] . '">View</a>';
    $out .= '</p>';
    $history = array_values(array_filter($vm['declarations'], fn(array $d): bool => $cur === null || (int)$d['id'] !== (int)$cur['id']));
    if ($history) {
        $out .= '<h3>History</h3><table class="data-table"><thead><tr><th>Declared</th><th>Class</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($history as $d) {
            $out .= '<tr><td>' . h(date('M j, Y', strtotime((string)$d['submitted_at']))) . '</td><td>' . h((string)$d['calculated_class'])
                . '</td><td>' . h(declarationReviewLabel((string)$d['review_status'])) . '</td>'
                . '<td><a href="garage.php?declaration=' . (int)$d['id'] . '">View</a></td></tr>';
        }
        $out .= '</tbody></table>';
    }
    $out .= '</section>';

    // Car tech
    $out .= '<section class="hub-card"><h2>Car tech ' . (int)$vm['season'] . '</h2>'
        . '<p><span class="hub-status ' . h(homeStatusClass((string)$vm['techState'])) . '">' . h((string)$vm['techLabel']) . '</span></p>';
    if ($vm['techAction'] !== null) {
        $out .= '<a class="hub-btn hub-btn--secondary" href="' . h($vm['techAction']['url']) . '">' . h($vm['techAction']['label']) . '</a>';
    } elseif ($vm['techState'] !== 'accepted') {
        $out .= '<p class="form-hint">Pre-tech with photos after you submit a tech sheet for an event, or bring the car to tech at the track.</p>';
    }
    $out .= '</section>';

    // Events
    $ev = $vm['events'];
    $out .= '<section class="hub-card"><h2>Events</h2>';
    if (!$ev['tagged']) $out .= '<p>This car isn\'t going to any events yet.</p>';
    foreach ($ev['tagged'] as $row) {
        $e = $row['event'];
        $eid = (int)$e['id'];
        $sheet = $row['sheet'];
        $out .= '<div class="garage-event"><div><strong>' . h((string)$e['name']) . '</strong> ' . h(date('M j', strtotime((string)$e['event_date']))) . '</div>';
        if ($sheet !== null) {
            $out .= '<span class="hub-status hub-status--ok">Tech sheet submitted</span> <a href="tech-sheets.php?action=view&amp;id=' . (int)$sheet['id'] . '">View</a>'
                . renderGearChips($row['gearLinks'], 'owner', ['sheet_season' => (int)($sheet['season'] ?? 0)]);
        } else {
            $out .= '<span class="hub-status hub-status--todo">No tech sheet yet</span> ';
            if ($archived) {
                $out .= 'Restore the car to submit a tech sheet';
            } else {
                $out .= $cur !== null
                    ? '<a class="hub-btn" href="tech-sheets.php?action=new&amp;car_id=' . $id . '&amp;event_id=' . $eid . '">Submit tech sheet</a>'
                    : '<a href="calculator.php?car=' . $id . '">Declare a class first</a>';
            }
        }
        if (!$archived) $out .= garagePostForm($csrf, 'untag', $id, 'Not going anymore', 'hub-btn hub-btn--link', '', ['event_id' => $eid]);
        $out .= '</div>';
    }
    if (!$archived && $ev['untagged']) {
        $out .= '<form method="post" action="garage.php" class="hub-line">' . garageCsrfField($csrf)
            . '<input type="hidden" name="action" value="tag"><input type="hidden" name="car_id" value="' . $id . '">'
            . '<label for="garage-tag-event">Bring this car to another event</label><select id="garage-tag-event" name="event_id">';
        foreach ($ev['untagged'] as $e) {
            $out .= '<option value="' . (int)$e['id'] . '">' . h((string)$e['name']) . ' — ' . h(date('M j', strtotime((string)$e['event_date']))) . '</option>';
        }
        $out .= '</select><button type="submit" class="hub-btn">I\'m going</button></form><p class="form-hint">' . EVENTS_NOT_REGISTERING . '</p>';
    }
    if ($ev['earlierSheets']) {
        $out .= '<h3>Earlier tech sheets</h3><ul>';
        foreach ($ev['earlierSheets'] as $row) {
            $out .= '<li>' . h($row['event_name']) . ' — <a href="tech-sheets.php?action=view&amp;id=' . (int)$row['sheet']['id'] . '">View</a></li>';
        }
        $out .= '</ul>';
    }
    $out .= '</section>';

    if (!$archived) {
        $out .= '<section class="hub-card"><h2>Archive</h2><p>Archiving hides the car from Home and the tech sheet form. Its history is kept.</p>'
            . garagePostForm($csrf, 'archive', $id, 'Archive car', 'hub-btn hub-btn--secondary', 'Archive ' . carDisplayName($car) . '? It will be hidden, and its history is kept.')
            . '</section>';
    }
    return $out;
}

/** "Which car is this tech sheet for?": the given (active) cars, each opening the form for that car. */
function renderTechSheetCarPickerHtml(array $cars, int $eventId): string {
    $out = '<h1>Submit a tech sheet</h1><p class="hub-intro">Which car is this tech sheet for?</p><div class="hub-card">';
    foreach ($cars as $car) {
        $url = 'tech-sheets.php?action=new&car_id=' . (int)$car['id'] . ($eventId > 0 ? '&event_id=' . $eventId : '');
        $out .= '<div class="hub-line"><span><span class="hub-plate">' . h((string)$car['car_number']) . '</span> ' . h(garageCarTitle($car)) . '</span>'
            . '<a class="hub-btn" href="' . h($url) . '">Choose</a></div>';
    }
    return $out . '</div><p><a href="garage.php?action=add">+ Add a car</a></p>';
}

function renderDeclarationHtml(array $s, string $csrf): string {
    $id = (int)$s['id'];
    $vehicle = trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model']);
    $out = '<h1>Class declaration: ' . h($vehicle) . '</h1><div class="hub-grid-2"><section class="hub-card"><h2>Vehicle and class</h2>'
        . '<table class="detail-table">'
        . '<tr><td>Vehicle</td><td>' . h($vehicle) . '</td></tr>'
        . '<tr><td>Weight</td><td>' . h((string)$s['competition_weight']) . ' lbs</td></tr>'
        . '<tr><td>Declared HP</td><td>' . h((string)$s['declared_hp']) . '</td></tr>'
        . '<tr><td>Calculated class</td><td><strong>' . h((string)($s['calculated_class'] ?? '—')) . '</strong></td></tr>'
        . '<tr><td>Submitted</td><td>' . h(date('F j, Y \a\t g:i A', strtotime((string)$s['submitted_at']))) . '</td></tr>'
        . '<tr><td>Review</td><td><span class="hub-status ' . h(homeStatusClass((string)$s['review_status'])) . '">' . h(declarationReviewLabel((string)$s['review_status'])) . '</span></td></tr>'
        . '</table>';
    if (trim((string)($s['reviewer_note'] ?? '')) !== '') {
        $out .= '<p class="garage-note"><strong>Inspector\'s note:</strong> ' . h((string)$s['reviewer_note']) . '</p>';
    }
    $out .= '</section><section class="hub-card"><h2>Actions</h2>'
        . '<form method="post" action="garage.php">' . garageCsrfField($csrf) . '<input type="hidden" name="action" value="resend-declaration">'
        . '<input type="hidden" name="id" value="' . $id . '"><button type="submit" class="hub-btn">Resend confirmation to my email</button></form>'
        . '<form method="post" action="garage.php" data-confirm="Permanently delete this class declaration and its files?">' . garageCsrfField($csrf)
        . '<input type="hidden" name="action" value="delete-declaration"><input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="hub-btn hub-btn--link">Delete this declaration</button></form>'
        . '<h2>Uploaded files</h2>';
    $files = ['car_image' => ['Car image', $s['car_image_path'] ?? null], 'dyno_chart' => ['Dyno chart', $s['dyno_chart_path'] ?? null], 'dyno_table' => ['Dyno table', $s['dyno_table_path'] ?? null]];
    $any = false;
    foreach ($files as $field => [$label, $path]) {
        if (!$path) continue;
        $any = true;
        $url = 'garage.php?action=file&id=' . $id . '&field=' . $field;
        $isImage = in_array(strtolower(pathinfo((string)$path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true);
        $out .= '<p><strong>' . h($label) . '</strong></p>' . ($isImage
            ? '<img src="' . h($url) . '" class="file-thumb" alt="' . h($label) . '">'
            : '<a href="' . h($url) . '" target="_blank">' . h(basename((string)$path)) . '</a>');
    }
    if (!$any) $out .= '<p>No files uploaded.</p>';
    return $out . '</section></div><p><a href="garage.php?car=' . (int)$s['car_id'] . '">&larr; Back to the car</a></p>';
}
