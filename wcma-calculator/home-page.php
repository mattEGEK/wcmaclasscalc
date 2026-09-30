<?php
// wcma-calculator/home-page.php
//
// The hub front door: the signed-out landing page and the signed-in Home to-do list (spec §3,
// mockup C). Pure view functions — no DB, no session, no echo — so they are unit-testable.
// Callers must have loaded view_helpers.php (h()), cars-lib.php (carDisplayName(),
// declarationReviewLabel(), declarationReviewBadgeClass()), events-lib.php (EVENTS_NOT_REGISTERING),
// reminders-lib.php (reminderOptInFieldsHtml()) and clubs-lib.php (eventRegisterUrl()), and
// ta-drift-lib.php (ENTRY_FORMATS).
require_once __DIR__ . '/ta-drift-lib.php';

/** A labelled status pill for the At a glance cards: "● Class: With an inspector". */
function homePillHtml(string $state, string $name, string $label): string {
    return '<span class="hub-pill hub-status ' . h(homeStatusClass($state)) . '"><span class="hub-pill-k">' . h($name) . ':</span> ' . h($label) . '</span>';
}

function homePlural(int $n, string $singular, string $plural): string {
    return $n === 1 ? $singular : $plural;
}

/**
 * Which going-to event fills the top to-do list: the one a card's "N things to do" link chose
 * (?event=<id>), else the soonest. Returns an index into $events (0 when $events is empty).
 */
function homeFocusIndex(array $events, ?int $eventId): int {
    foreach ($events as $i => $ev) {
        if ($eventId !== null && (int)$ev['event']['id'] === $eventId) return $i;
    }
    return 0;
}

/**
 * The <h1> for Home, per spec §3, for the event at $focus (see homeFocusIndex()). With no event
 * tagged it names what matters most (UX review 2026-09-30 §H3, §M1): work an inspector sent back
 * ($extra['attention'] items), then a first car ($extra['hasCars'] false), then picking events.
 */
function homeHeadline(array $readiness, int $focus = 0, array $extra = []): string
{
    $events = $readiness['events'];
    if (!$events) {
        $attention = (int)($extra['attention'] ?? 0);
        if ($attention > 0) {
            return $attention === 1 ? '1 thing needs your attention' : $attention . ' things need your attention';
        }
        if (($extra['hasCars'] ?? true) === false) return 'Start by adding your car';
        return 'Which events are you going to?';
    }
    $first = $events[$focus] ?? $events[0];
    $todoCount = count(array_filter($first['items'], fn(array $i): bool => $i['state'] === 'todo'));
    $name = (string)$first['event']['name'];
    if ($todoCount === 0) {
        return "You're all set for $name \u{2713}";
    }
    return $todoCount . ' ' . homePlural($todoCount, 'thing', 'things') . " to do before $name";
}

/**
 * Work an inspector or media staff sent back, whatever events are tagged (UX review 2026-09-30 §H3):
 * a class declaration that needs changes, and car or gear photos to retake. Built from the At a
 * glance data ($vm['garage'], $vm['drivers']). Anything already a to-do in $shownItems (the event
 * list at the top of Home) is left out, so nothing is listed twice.
 * @return array<int, array{label: string, detail: string, action: ?array, at_track: null}>
 */
function homeAttentionItems(array $vm, array $shownItems = []): array {
    $shown = [];
    foreach ($shownItems as $i) {
        if (($i['state'] ?? '') === 'todo') $shown[$i['kind'] . ':' . $i['subject_id']] = true;
    }
    $item = fn(string $label, string $detail, string $button, string $url): array =>
        ['label' => $label, 'detail' => $detail, 'action' => ['label' => $button, 'url' => $url], 'at_track' => null];
    $out = [];
    foreach ($vm['garage'] ?? [] as $g) {
        $car = $g['car'];
        $id = (int)$car['id'];
        $n = '#' . $car['car_number'];
        $decl = $g['declaration'] ?? null;
        if (($g['usesRace'] ?? $g['usesSummer'] ?? true) && $decl !== null && ($decl['review_status'] ?? '') === 'needs_changes' && !isset($shown["declaration:$id"])) {
            $note = trim((string)($decl['reviewer_note'] ?? ''));
            $out[] = $item("Your class declaration for $n needs changes", $note !== '' ? 'Inspector\'s note: ' . $note : 'An inspector asked for changes.',
                'Re-declare class', 'calculator.php?car=' . $id);
        }
        if (isset($shown["car_tech:$id"])) continue;
        if (($g['techState'] ?? '') === 'needs_changes') {
            $out[] = $item("Retake car photos for $n", 'An inspector asked for some photos to be retaken.', 'Retake photos', (string)($g['techRetakeUrl'] ?? 'garage.php?car=' . $id));
        } elseif (($g['ice']['state'] ?? '') === 'needs_changes') {
            $out[] = $item("Retake ice car photos for $n", 'An inspector asked for some photos to be retaken.', 'Open the car', 'garage.php?car=' . $id);
        } else {
            foreach ($g['taDrift'] ?? [] as $t) {
                if (($t['state'] ?? '') !== 'needs_changes') continue;
                $out[] = $item("Retake TA/Drift car photos for $n", 'An inspector asked for some photos to be retaken.', 'Open the car', 'garage.php?car=' . $id);
                break;
            }
        }
    }
    foreach ($vm['drivers'] ?? [] as $d) {
        $did = (int)($d['id'] ?? 0);
        if (isset($shown["gear:$did"])) continue;
        $name = (string)$d['name'];
        if (($d['gearState'] ?? '') === 'needs_changes' && ($d['showSummer'] ?? true)) {
            $out[] = $item("Retake gear photos for $name", 'An inspector asked for some photos to be retaken.', 'Retake photos', (string)($d['gearRetakeUrl'] ?? 'drivers.php'));
        } elseif (($d['ice']['state'] ?? '') === 'needs_changes') {
            $gearId = $d['ice']['gearId'] ?? null;
            $out[] = $item("Retake ice gear photos for $name", 'An inspector asked for some photos to be retaken.', 'Retake photos',
                $gearId !== null ? 'gear.php?action=pretech&id=' . (int)$gearId : 'drivers.php');
        }
    }
    return $out;
}

/** The "Needs your attention" list: the to-do look, with "!" in place of a number. '' when empty. */
function homeAttentionHtml(array $items, string $csrf, bool $withHeading): string {
    if (!$items) return '';
    $out = ($withHeading ? '<h2 id="attention">Needs your attention</h2>' : '') . '<ul class="hub-todo hub-todo--attention">';
    foreach ($items as $item) {
        $out .= '<li class="hub-todo-item"><span class="hub-todo-n" aria-hidden="true">!</span>'
            . '<div class="hub-todo-txt"><strong>' . h($item['label']) . '</strong>'
            . ($item['detail'] !== '' ? '<span>' . h($item['detail']) . '</span>' : '') . '</div>'
            . '<div class="hub-todo-actions"><a class="hub-btn" href="' . h($item['action']['url']) . '">' . h($item['action']['label']) . '</a></div></li>';
    }
    return $out . '</ul>';
}

/**
 * The first-run steps, shown while no event is tagged and a step before events is still open
 * (UX review 2026-09-30 §M1): add a car, declare its class (summer race cars only), say which
 * events. A finished step is ticked, the first open one carries the button. '' once there is a car
 * and no race car is waiting for its class.
 */
function homeGettingStartedHtml(array $vm): string {
    $hasCars = !empty($vm['cars']);
    $undeclared = null;
    foreach ($vm['garage'] ?? [] as $g) {
        if (($g['usesRace'] ?? $g['usesSummer'] ?? true) && ($g['declaration'] ?? null) === null) { $undeclared = $g['car']; break; }
    }
    if ($hasCars && $undeclared === null) return '';
    $steps = [
        ['Add your car', 'Its number, make, model and colour. It takes a minute.', $hasCars,
            ['Add a car', 'garage.php?action=add']],
        ['Declare its class', 'Summer race cars need a class from the calculator. Ice and TA/Drift cars skip this step.', $hasCars && $undeclared === null,
            $undeclared !== null ? ['Declare class', 'calculator.php?car=' . (int)$undeclared['id']] : null],
        ['Say which events you\'re going to', 'Pick them from the list below. Home then shows what is left to do before each one.', false, null],
    ];
    $out = '<ol class="hub-todo hub-steps">';
    $current = true;   // the first open step gets the button
    foreach ($steps as $i => [$label, $detail, $done, $action]) {
        $isCurrent = !$done && $current;
        if ($isCurrent) $current = false;
        $out .= '<li class="hub-todo-item' . ($done ? ' hub-todo-item--done' : ($isCurrent ? '' : ' hub-todo-item--optional')) . '">'
            . '<span class="hub-todo-n"' . ($done ? ' aria-label="Done"' : '') . '>' . ($done ? '&#10003;' : (string)($i + 1)) . '</span>'
            . '<div class="hub-todo-txt"><strong>' . h($label) . '</strong><span>' . h($detail) . '</span></div>';
        if ($isCurrent && $action !== null) {
            $out .= '<div class="hub-todo-actions"><a class="hub-btn" href="' . h($action[1]) . '">' . h($action[0]) . '</a></div>';
        }
        $out .= '</li>';
    }
    return $out . '</ol>';
}

/** Hidden csrf field shared by every POST form on this page. */
function homeCsrfField(string $csrf): string {
    return '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">';
}

/**
 * One numbered todo item, with its primary action and optional "at the track" secondary form. A
 * suggested item (TA/Drift spec §4) gets the quieter optional style and a "+" instead of a number.
 */
function homeRenderTodoItem(int $n, array $item, string $csrf, bool $suggested = false): string {
    $out = $suggested
        ? '<li class="hub-todo-item hub-todo-item--optional"><span class="hub-todo-n" aria-hidden="true">+</span>'
        : '<li class="hub-todo-item"><span class="hub-todo-n">' . h((string)$n) . '</span>';
    $out .= '<div class="hub-todo-txt"><strong>' . h($item['label']) . '</strong>';
    if ($item['detail'] !== '') {
        $out .= '<span>' . h($item['detail']) . '</span>';
    }
    $out .= '</div><div class="hub-todo-actions">';
    if ($item['action'] !== null) {
        $out .= '<a class="hub-btn" href="' . h($item['action']['url']) . '">' . h($item['action']['label']) . '</a>';
    }
    if ($item['at_track'] !== null) {
        $at = $item['at_track'];
        $out .= '<form method="post" action="index.php">' . homeCsrfField($csrf)
            . '<input type="hidden" name="action" value="at-track">'
            . '<input type="hidden" name="subject_type" value="' . h((string)$at['subject_type']) . '">'
            . '<input type="hidden" name="subject_id" value="' . h((string)$at['subject_id']) . '">'
            . '<input type="hidden" name="season" value="' . h((string)$at['season']) . '">'
            . (isset($at['discipline']) ? '<input type="hidden" name="discipline" value="' . h((string)$at['discipline']) . '">' : '')
            . (isset($at['club']) ? '<input type="hidden" name="club" value="' . h((string)$at['club']) . '">' : '')
            . "<button type=\"submit\" class=\"hub-btn hub-btn--link\">I'll do it at the track</button></form>";
    }
    $out .= '</div></li>';
    return $out;
}

/**
 * The Race / Time Attack / Drift checkboxes and the supplementary-regulations tick (TA/Drift spec §3
 * Entry). $event null: the event isn't chosen yet (Garage's event select), so every box is enabled
 * and the wording names no club; the server still checks the host club. An event with no host club
 * runs Race only: the choice is fixed and no disabled boxes are shown (UX review 2026-09-30 §M12).
 */
function homeFormatsFieldsHtml(?array $event, array $checked, bool $suppsTicked = false): string {
    $club = $event === null ? null : trim((string)($event['host_club'] ?? ''));
    if ($club === '') {
        return '<input type="hidden" name="formats_shown" value="1"><input type="hidden" name="formats[]" value="race">'
            . '<p class="hub-formats-fixed"><strong>Running:</strong> Race</p>';
    }
    $out = '<fieldset class="hub-formats"><legend>Running</legend><input type="hidden" name="formats_shown" value="1"><div class="hub-formats-options">';
    foreach (ENTRY_FORMATS as $value => $label) {
        $out .= '<label><input type="checkbox" name="formats[]" value="' . h($value) . '"'
            . (in_array($value, $checked, true) ? ' checked' : '') . '> ' . h($label) . '</label>';
    }
    $out .= '</div>';
    $who = $club === null ? "the host club's" : "the $club";
    return $out . '<label class="hub-formats-supps"><input type="checkbox" name="supps_ack" value="1"' . ($suppsTicked ? ' checked' : '') . '> '
        . h("For Time Attack and Drift: I have read $who supplementary regulations and my car complies") . '</label></fieldset>';
}

/** "Who's driving?" for one entry (co-drivers spec §3): the owner ("You"), then the car's co-drivers. */
function homeDriversFieldsHtml(array $drivers, array $tickedIds): string {
    $ticked = array_map('intval', $tickedIds);
    $out = '<fieldset class="hub-formats hub-drivers"><legend>Who\'s driving?</legend><input type="hidden" name="drivers_shown" value="1"><div class="hub-formats-options">';
    foreach ($drivers as $d) {
        $out .= '<label><input type="checkbox" name="drivers[]" value="' . (int)$d['id'] . '"' . (in_array((int)$d['id'], $ticked, true) ? ' checked' : '') . '> '
            . h($d['isSelf'] ? 'You' : (string)$d['name']) . '</label>';
    }
    return $out . '</div></fieldset>';
}

/** "Driving: You · Sam Lee" (plain text; escape when printing). */
function homeDrivingLabel(array $drivers, array $tickedIds): string {
    $ticked = array_map('intval', $tickedIds);
    $names = [];
    foreach ($drivers as $d) {
        if (in_array((int)$d['id'], $ticked, true)) $names[] = $d['isSelf'] ? 'You' : (string)$d['name'];
    }
    return 'Driving: ' . implode(' · ', $names);
}

/**
 * A going car's entry on its event card, with a "Change" form: what it runs (summer) and, when
 * $drivers is given, who's driving (every discipline).
 */
function homeRenderEntryFormatsHtml(array $event, array $car, array $entry, string $csrf, array $drivers = []): string {
    $isIce = ($event['discipline'] ?? 'summer') === 'ice';
    if ($isIce && $drivers === []) return '';
    $ticked = $entry['driverIds'] ?? [];
    $summary = [];
    if (!$isIce) $summary[] = entryFormatsLabel($entry['formats']);
    if ($drivers !== []) $summary[] = homeDrivingLabel($drivers, $ticked);
    return '<details class="hub-entry-formats"><summary>' . h(implode(' · ', $summary)) . ' · <span class="hub-entry-change">Change</span></summary>'
        . '<form method="post" action="index.php" class="hub-line hub-tag-form">' . homeCsrfField($csrf)
        . '<input type="hidden" name="action" value="formats">'
        . '<input type="hidden" name="event_id" value="' . (int)$event['id'] . '">'
        . '<input type="hidden" name="car_id" value="' . (int)$car['id'] . '">'
        . ($isIce ? '' : homeFormatsFieldsHtml($event, $entry['formats'], ($entry['supps_ack_at'] ?? null) !== null))
        . ($drivers !== [] ? homeDriversFieldsHtml($drivers, $ticked) : '')
        . '<button type="submit" class="hub-btn hub-btn--secondary">Save</button></form></details>';
}

/**
 * The tag ("I'm going") form for one event: pick the car (or name it) and, for summer, what it runs
 * ($defaults: the first car's eventsDefaultFormats()). The choices stay folded behind the button
 * until it is tapped, so an event is one row on Home (UX review 2026-09-30 §H4). With nothing to
 * choose (one car, an ice event, no reminder question) the button tags the car straight away.
 * $another: a car is already going, so this adds a second one and says so.
 */
function homeRenderTagForm(array $event, array $cars, string $csrf, bool $offerReminders = false, array $defaults = ['race'], bool $another = false): string {
    $eid = (int)$event['id'];
    $isIce = ($event['discipline'] ?? 'summer') === 'ice';
    $hidden = homeCsrfField($csrf)
        . '<input type="hidden" name="action" value="tag">'
        . '<input type="hidden" name="event_id" value="' . h((string)$eid) . '">';
    $one = count($cars) === 1 ? array_values($cars)[0] : null;
    if ($one !== null && $isIce && !$offerReminders) {
        return '<form method="post" action="index.php" class="hub-line hub-tag-form">' . $hidden
            . '<span>' . h(carDisplayName($one)) . '</span><input type="hidden" name="car_id" value="' . h((string)$one['id']) . '">'
            . '<button type="submit" class="hub-btn' . ($another ? ' hub-btn--secondary' : '') . '">' . ($another ? 'Add this car' : 'I\'m going') . '</button></form>';
    }
    $out = '<details class="hub-event-add"><summary class="hub-btn' . ($another ? ' hub-btn--secondary' : '') . '">' . ($another ? 'Add another car' : 'I\'m going') . '</summary>'
        . '<form method="post" action="index.php" class="hub-tag-form hub-tag-form--stack">' . $hidden;
    if ($one !== null) {
        $out .= '<p class="hub-tag-car"><strong>Car:</strong> ' . h(carDisplayName($one)) . '</p>'
            . '<input type="hidden" name="car_id" value="' . h((string)$one['id']) . '">';
    } else {
        $out .= '<label for="tag-car-' . $eid . '">Which car?<span class="visually-hidden"> For ' . h((string)$event['name']) . '</span></label>'
            . '<select id="tag-car-' . $eid . '" name="car_id">';
        foreach ($cars as $car) {
            $out .= '<option value="' . h((string)$car['id']) . '">' . h(carDisplayName($car)) . '</option>';
        }
        $out .= '</select>';
    }
    if (!$isIce) $out .= homeFormatsFieldsHtml($event, $defaults);
    if ($offerReminders) $out .= reminderOptInFieldsHtml();
    return $out . '<button type="submit" class="hub-btn">' . ($another ? 'Add this car' : 'Confirm I\'m going') . '</button></form></details>';
}

/**
 * The cars (keyed by id) that can go to $event: a car stored for the other season can't (mobile UX
 * spec §A4), and a summer TA/Drift-only car never goes to an ice event.
 */
function homeCarsForEvent(array $cars, array $event): array {
    $exclude = ($event['discipline'] ?? 'summer') === 'ice' ? ['summer', 'ta_drift'] : ['ice'];
    return array_filter($cars, fn(array $c): bool => !in_array($c['disciplines'] ?? null, $exclude, true));
}

/** Whether the soonest active event on or after $today is an ice event (the landing hero leads with ice). */
function landingNextIsIce(array $activeEvents, string $today): bool {
    $upcoming = array_values(array_filter($activeEvents, fn(array $e): bool => (string)$e['event_date'] >= $today));
    usort($upcoming, fn(array $a, array $b): int => strcmp((string)$a['event_date'], (string)$b['event_date']));
    return $upcoming !== [] && ($upcoming[0]['discipline'] ?? 'summer') === 'ice';
}

/** Short event date for card headers ("Sat, Oct 11"); '' if the date can't be read. */
function homeShortDate(string $eventDate): string {
    try {
        return (new DateTime(substr($eventDate, 0, 10)))->format('D, M j, Y');
    } catch (Exception $e) {
        return '';
    }
}

/**
 * One upcoming event as a card: name, date and (when you're going) a to-do badge in the header;
 * inside, each car you're taking with "Not going anymore", then an "I'm going" row for the rest.
 * $readinessEvent is the buildReadiness() entry when you're going, null otherwise. The to-do badge
 * links to that event's list: up to the top when it is already there ($isFocus), else reloading
 * Home with that event's list at the top.
 */
function homeEventCardHtml(array $event, ?array $readinessEvent, array $cars, string $csrf, bool $offerReminders, bool $isFocus = false, array $tagDefaults = [], array $carDrivers = []): string {
    $out = '<section class="hub-card hub-event" id="event-' . (int)$event['id'] . '"><div class="hub-event-head"><h3>' . h((string)$event['name']) . '</h3>';
    $date = homeShortDate((string)$event['event_date']);
    if ($date !== '') $out .= '<span class="hub-event-date">' . h($date) . '</span>';
    if (($event['discipline'] ?? 'summer') === 'ice') {
        $out .= '<span class="hub-status hub-status--info">' . h('Ice · ' . (string)($event['host_club'] ?? '')) . '</span>';
    }
    $goingCarIds = [];
    if ($readinessEvent !== null) {
        foreach ($readinessEvent['items'] as $item) {
            if ($item['kind'] === 'tech_sheet') $goingCarIds[(int)$item['subject_id']] = true;
        }
        $todo = count(array_filter($readinessEvent['items'], fn(array $i): bool => $i['state'] === 'todo'));
        $out .= $todo > 0
            ? '<a class="hub-status hub-status--todo hub-todo-link" href="' . ($isFocus ? '#todo' : 'index.php?event=' . (int)$event['id'] . '#todo') . '">'
                . h($todo . ' ' . homePlural($todo, 'thing', 'things') . ' to do') . '</a>'
            : '<span class="hub-status hub-status--ok">All set</span>';
    }
    $out .= '</div>';
    $msr = eventRegisterUrl($event);
    if ($msr !== '') {
        $out .= '<p><a class="hub-btn hub-btn--secondary hub-register-link" href="' . h($msr) . '" target="_blank" rel="noopener">Register on MotorsportReg &#8599;</a></p>';
    }
    foreach (array_keys($goingCarIds) as $carId) {
        if (!isset($cars[$carId])) continue;
        $out .= homeRenderUntagForm($event, $cars[$carId], $csrf)
            . homeRenderEntryFormatsHtml($event, $cars[$carId], $readinessEvent['entries'][$carId] ?? ['formats' => ['race'], 'supps_ack_at' => null, 'driverIds' => []], $csrf, $carDrivers[$carId] ?? []);
    }
    $notGoing = homeCarsForEvent(array_diff_key($cars, $goingCarIds), $event);
    if ($notGoing) {
        $out .= homeRenderTagForm($event, $notGoing, $csrf, $offerReminders, $tagDefaults[array_key_first($notGoing)] ?? ['race'], (bool)$goingCarIds);
    } elseif (!$goingCarIds) {
        $out .= '<p><a class="hub-btn hub-btn--secondary" href="garage.php?action=add&amp;event_id=' . (int)$event['id'] . '">Add a car for this event</a></p>';
    }
    return $out . '</section>';
}

/** One-time invitation to add a media profile (spec 2026-09-27 §3). Not part of readiness. */
function homeMediaPromptHtml(string $csrf, array $prompt = ['kind' => 'invite']): string {
    if ($prompt['kind'] === 'attention') {
        $labels = ['sent_back' => 'Public page sent back', 'hidden' => 'Hidden by WCMA'];
        $out = '<div class="hub-card media-prompt"><h2>Media profile needs attention</h2>';
        foreach ($prompt['items'] as $i) {
            $out .= '<p><strong>' . h($i['name']) . '</strong> · ' . h($labels[$i['state']] ?? '')
                . ($i['note'] !== '' ? ': ' . h($i['note']) : '') . '</p>'
                . '<p><a class="hub-btn" href="media-profile.php?driver_id=' . (int)$i['driverId'] . '">Fix profile</a></p>';
        }
        return $out . '</div>';
    }
    return '<div class="hub-card media-prompt"><h2>Clubs would like to feature you</h2>'
        . '<p>Add a photo and a line about yourself. Announcers read it out at events, and clubs use it to promote racing. You choose whether it goes on a public page.</p>'
        . '<p><a class="hub-btn" href="media-profile.php?driver_id=self">Add profile</a></p>'
        . '<form method="post" action="index.php"><input type="hidden" name="csrf_token" value="' . h($csrf) . '">'
        . '<input type="hidden" name="action" value="media-prompt-dismiss">'
        . '<button type="submit" class="hub-btn hub-btn--link">No thanks</button></form></div>';
}

/** What the untag confirm box says: what is cleared and what is kept. Shared with the car page. */
function homeUntagConfirmText(array $event, array $car): string {
    return 'Take ' . carDisplayName($car) . ' off ' . $event['name'] . '? What it is running and who is driving are cleared. A tech sheet you already sent is kept.';
}

/** The untag ("Not going anymore") form for one car already tagged to an event. It asks first (js/confirm-modal.js). */
function homeRenderUntagForm(array $event, array $car, string $csrf): string {
    return '<form method="post" action="index.php" class="hub-line" data-confirm="' . h(homeUntagConfirmText($event, $car)) . '">' . homeCsrfField($csrf)
        . '<input type="hidden" name="action" value="untag">'
        . '<input type="hidden" name="event_id" value="' . h((string)$event['id']) . '">'
        . '<input type="hidden" name="car_id" value="' . h((string)$car['id']) . '">'
        . '<span>' . h(carDisplayName($car)) . '</span>'
        . '<button type="submit" class="hub-btn hub-btn--link">' . h('Not going anymore') . '</button></form>';
}

function renderHomeHtml(array $vm): string
{
    $readiness = $vm['readiness'];
    $events = $readiness['events'];
    $untagged = $readiness['untagged'];
    $cars = $vm['cars'];
    $csrf = (string)$vm['csrf'];
    $offerReminders = !empty($vm['offerReminders']);

    $focus = homeFocusIndex($events, isset($vm['focusEventId']) ? (int)$vm['focusEventId'] : null);
    $first = $events[$focus] ?? null;
    $focusId = $first !== null ? (int)$first['event']['id'] : null;
    // Sent-back work shows whatever is tagged; what the event list above already asks for isn't repeated.
    $attention = homeAttentionItems($vm, $first !== null ? $first['items'] : []);
    // id="todo" is where every event card's "N things to do" link lands.
    $out = '<h1 id="todo" tabindex="-1">' . h(homeHeadline($readiness, $focus, ['attention' => count($attention), 'hasCars' => !empty($cars)])) . '</h1>';
    if ($first === null) {
        $out .= homeAttentionHtml($attention, $csrf, false);
        if (!$attention) $out .= homeGettingStartedHtml($vm);
    }

    if ($first !== null) {
        $eventDate = (string)$first['event']['event_date'];
        try {
            $date = new DateTime(substr($eventDate, 0, 10));
            $today = new DateTime(date('Y-m-d'));
            $days = (int)$today->diff($date)->format('%a');
            $out .= '<p class="hub-intro">' . h($date->format('D, M j, Y')) . ' &middot; in ' . h((string)$days) . ' days</p>';
        } catch (Exception $e) {
            // Unparseable date: skip the date line.
        }
        if ($focus !== 0) {
            $out .= '<p class="hub-intro"><a class="hub-back-link" href="index.php#todo">&larr; Back to ' . h((string)$events[0]['event']['name']) . '</a></p>';
        }

        $items = $first['items'];
        $todoItems = array_values(array_filter($items, fn(array $i): bool => $i['state'] === 'todo'));
        $suggestedItems = array_values(array_filter($items, fn(array $i): bool => $i['state'] === 'suggested'));
        $infoItems = array_values(array_filter($items, fn(array $i): bool => $i['state'] === 'info'));
        $doneItems = array_values(array_filter($items, fn(array $i): bool => $i['state'] === 'done'));

        if ($todoItems) {
            $out .= '<ol class="hub-todo">';
            $n = 1;
            foreach ($todoItems as $item) {
                $out .= homeRenderTodoItem($n, $item, $csrf);
                $n++;
            }
            $out .= '</ol>';
        }

        // Recommended, never counted (TA/Drift spec §4): e.g. going through the TA/Drift sheet again.
        if ($suggestedItems) {
            $out .= '<h2>Recommended</h2><ul class="hub-todo hub-todo--suggested">';
            foreach ($suggestedItems as $item) {
                $out .= homeRenderTodoItem(0, $item, $csrf, true);
            }
            $out .= '</ul>';
        }

        if ($infoItems || $doneItems) {
            $parts = [];
            if ($infoItems) $parts[] = count($infoItems) . ' with an inspector';
            if ($doneItems) $parts[] = count($doneItems) . ' already done';
            $out .= '<details class="hub-done"><summary>' . h(implode(' · ', $parts)) . '</summary>';
            if ($infoItems) {
                $out .= '<h3>With an inspector</h3><ul>';
                foreach ($infoItems as $item) {
                    $out .= '<li>' . h($item['label']) . ($item['detail'] !== '' ? ' <span class="form-hint">' . h($item['detail']) . '</span>' : '') . '</li>';
                }
                $out .= '</ul>';
            }
            if ($doneItems) {
                $out .= '<h3>Already done for ' . h((string)$first['event']['name']) . '</h3><ul>';
                foreach ($doneItems as $item) {
                    $out .= '<li>' . h($item['label']) . '</li>';
                }
                $out .= '</ul>';
            }
            $out .= '</details>';
        }
    }

    if ($first !== null) $out .= homeAttentionHtml($attention, $csrf, true);

    // A media profile that was sent back or hidden is something to act on, so it sits with the to-dos.
    $mediaPrompt = !empty($vm['mediaPrompt']) ? (is_array($vm['mediaPrompt']) ? $vm['mediaPrompt'] : ['kind' => 'invite']) : null;
    if ($mediaPrompt !== null && $mediaPrompt['kind'] === 'attention') {
        $out .= homeMediaPromptHtml($csrf, $mediaPrompt);
    }

    // Upcoming events: one card per event, soonest first. When the page title already asks "Which
    // events are you going to?" the section needs no heading of its own.
    $titleAsksEvents = $first === null && !$attention && homeGettingStartedHtml($vm) === '';
    $out .= $titleAsksEvents ? '<div id="events"></div>' : '<h2 id="events">Upcoming events</h2>';
    if (!$events && !$untagged) {
        $out .= '<p>No upcoming events yet.</p>';
    } else {
        $out .= '<p class="hub-section-intro">Say which events you\'re going to. ' . EVENTS_NOT_REGISTERING . '</p>';
        $cardsByDate = [];
        foreach ($events as $ev) $cardsByDate[] = [$ev['event'], $ev];
        foreach ($untagged as $event) $cardsByDate[] = [$event, null];
        usort($cardsByDate, fn(array $a, array $b): int => strcmp((string)$a[0]['event_date'], (string)$b[0]['event_date']));
        foreach ($cardsByDate as [$event, $readinessEvent]) {
            $out .= homeEventCardHtml($event, $readinessEvent, $cars, $csrf, $offerReminders,
                $readinessEvent !== null && (int)$event['id'] === $focusId, $vm['tagDefaults'][(int)$event['id']] ?? [], $vm['carDrivers'] ?? []);
        }
    }

    // At a glance
    $out .= '<h2>At a glance</h2><div class="hub-grid-2">';
    $out .= '<div class="hub-card"><h3>Garage</h3>';
    if (!$vm['cars']) {
        $out .= '<p>No cars yet.</p><a class="hub-btn hub-btn--secondary" href="garage.php?action=add">Add a car</a>';
    } else {
        foreach ($vm['garage'] as $g) {
            $car = $g['car'];
            $decl = $g['declaration'];
            $usesSummer = ($g['usesRace'] ?? $g['usesSummer'] ?? true);   // class and race tech: not for a TA/Drift-only car
            $name = trim(implode(' ', array_filter([(string)($car['year'] ?? ''), (string)$car['make'], (string)$car['model']], fn(string $p): bool => trim($p) !== '')));
            $out .= '<div class="hub-glance-item"><div class="hub-glance-head"><span class="hub-plate hub-plate--sm">' . h((string)$car['car_number']) . '</span>'
                . '<span class="hub-glance-name">' . h($name) . '</span>'
                . ($usesSummer && $decl !== null ? '<span class="hub-class">' . h((string)$decl['calculated_class']) . '</span>' : '') . '</div>'
                . '<div class="hub-glance-pills">';
            if ($usesSummer) {
                $out .= ($decl !== null
                    ? homePillHtml($decl['review_status'], 'Class', declarationReviewLabel($decl['review_status']))
                    : homePillHtml('none', 'Class', 'Not declared') . '<a href="calculator.php?car=' . (int)$car['id'] . '">Declare class</a>')
                    . homePillHtml($g['techState'], 'Car tech', $g['techLabel']);
            }
            if (!empty($g['ice'])) {
                $out .= homePillHtml($g['ice']['state'], 'Ice tech', $g['ice']['label']);
            }
            foreach ($g['taDrift'] ?? [] as $t) {
                $out .= homePillHtml($t['state'], 'TA/Drift ' . $t['club'], $t['label']);
            }
            $out .= '</div></div>';
        }
        $out .= '<a class="hub-btn hub-btn--secondary" href="garage.php">Open garage &rarr;</a>';
    }
    $out .= '</div>';

    $out .= '<div class="hub-card"><h3>Drivers</h3>';
    foreach ($vm['drivers'] as $d) {
        $out .= '<div class="hub-glance-item"><div class="hub-glance-head"><span class="hub-glance-name">' . h($d['name']) . ($d['isSelf'] ? ' (you)' : '') . '</span></div>'
            . '<div class="hub-glance-pills">' . (($d['showSummer'] ?? true) ? homePillHtml($d['gearState'], 'Gear tech', $d['gearLabel']) : '');
        if (!empty($d['ice'])) {
            $out .= homePillHtml($d['ice']['state'], 'Ice gear', $d['ice']['label']);
        }
        $out .= '</div></div>';
    }
    $out .= '<a class="hub-btn hub-btn--secondary" href="drivers.php">Manage drivers &rarr;</a></div>';
    $out .= '</div>';

    if ($mediaPrompt !== null && $mediaPrompt['kind'] === 'invite') {
        $out .= homeMediaPromptHtml($csrf, $mediaPrompt);
    }

    // This season on MotorsportReg
    if ($vm['seasonLinks']) {
        $out .= '<div class="hub-card"><h3>This season on MotorsportReg</h3>';
        foreach ($vm['seasonLinks'] as $link) {
            $out .= '<div class="hub-line"><span>' . h((string)$link['label']) . '</span>'
                . '<a href="' . h((string)$link['url']) . '" target="_blank" rel="noopener">Open &#8599;</a></div>';
        }
        $out .= '</div>';
    }

    return $out;
}

/**
 * The signed-out landing page: what the hub is for, the account buttons, then the three steps a
 * member takes (UX review 2026-09-30 §M1). $iceNext (landingNextIsIce()) leads with ice tech.
 */
function renderLandingHtml(array $seasonLinks, bool $iceNext = false): string
{
    $signIn = 'auth.php?action=login&amp;redirect=index.php';
    $register = 'auth.php?action=register&amp;redirect=index.php';
    $out = '<section class="hub-hero"><h1>WCMA Hub</h1>'
        . '<p class="hub-hero-tagline">' . ($iceNext
            ? 'Get your car and gear ready for the ice. Send your ice tech sheet, and see what is left to do before each event.'
            : 'Get your car and gear ready for race day. Declare your class, send your tech sheet, and see what is left to do before each event.') . '</p>'
        . '<div class="hub-hero-actions"><a class="hub-btn" href="' . $register . '">Create account</a>'
        . '<a class="hub-btn hub-btn--secondary" href="' . $signIn . '">Sign in</a></div>'
        . '<p class="hub-hero-links">' . ($iceNext ? '<a href="calculator.php">Summer class calculator</a>' : '<a href="calculator.php">Try the class calculator</a>')
        . '<a href="drivers-public.php">Meet the drivers</a></p></section>';

    $steps = $iceNext
        ? [['Add your car', 'Its number, make, model and colour.'],
           ['Say which ice events you\'re going to', 'This doesn\'t register you. You still register with the host club.'],
           ['Send an ice tech sheet', 'Then pre-tech with photos, or bring the car to tech at the track.']]
        : [['Add your car', 'Its number, make, model and colour.'],
           ['Declare its class', 'The calculator works it out from weight, power and modifications.'],
           ['Send a tech sheet for each event', 'Then pre-tech with photos, or bring the car to tech at the track.']];
    $out .= '<div class="hub-card"><h2>How it works</h2><ol class="hub-todo hub-steps hub-steps--plain">';
    foreach ($steps as $i => [$label, $detail]) {
        $out .= '<li class="hub-todo-item hub-todo-item--optional"><span class="hub-todo-n">' . ($i + 1) . '</span>'
            . '<div class="hub-todo-txt"><strong>' . h($label) . '</strong><span>' . h($detail) . '</span></div></li>';
    }
    $out .= '</ol></div>';

    if ($seasonLinks) {
        $out .= '<div class="hub-card"><h3>This season on MotorsportReg</h3>';
        foreach ($seasonLinks as $link) {
            $out .= '<div class="hub-line"><span>' . h((string)$link['label']) . '</span>'
                . '<a href="' . h((string)$link['url']) . '" target="_blank" rel="noopener">Open &#8599;</a></div>';
        }
        $out .= '</div>';
    }

    return $out;
}
