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
