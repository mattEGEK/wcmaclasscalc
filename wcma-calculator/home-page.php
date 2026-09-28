<?php
// wcma-calculator/home-page.php
//
// The hub front door: the signed-out landing page and the signed-in Home to-do list (spec §3,
// mockup C). Pure view functions — no DB, no session, no echo — so they are unit-testable.
// Callers must have loaded view_helpers.php (h()), cars-lib.php (carDisplayName(),
// declarationReviewLabel(), declarationReviewBadgeClass()), events-lib.php (EVENTS_NOT_REGISTERING)
// and reminders-lib.php (reminderOptInFieldsHtml()).

/** Status word class for the Garage/Drivers "at a glance" cards. */
function homeStatusClass(string $state): string {
    switch ($state) {
        case 'accepted':       return 'hub-status--ok';
        case 'needs_changes':  return 'hub-status--todo';
        case 'pending_review':
        case 'submitted':      return 'hub-status--info';
        default:                return 'hub-status--warn';
    }
}

/** A labelled status pill for the At a glance cards: "● Class: With an inspector". */
function homePillHtml(string $state, string $name, string $label): string {
    return '<span class="hub-pill hub-status ' . h(homeStatusClass($state)) . '"><span class="hub-pill-k">' . h($name) . ':</span> ' . h($label) . '</span>';
}

function homePlural(int $n, string $singular, string $plural): string {
    return $n === 1 ? $singular : $plural;
}

/** The <h1> for Home, per spec §3. */
function homeHeadline(array $readiness): string
{
    $events = $readiness['events'];
    if (!$events) {
        return 'Which events are you going to?';
    }
    $first = $events[0];
    $todoCount = count(array_filter($first['items'], fn(array $i): bool => $i['state'] === 'todo'));
    $name = (string)$first['event']['name'];
    if ($todoCount === 0) {
        return "You're all set for $name \u{2713}";
    }
    return $todoCount . ' ' . homePlural($todoCount, 'thing', 'things') . " to do before $name";
}

/** Hidden csrf field shared by every POST form on this page. */
function homeCsrfField(string $csrf): string {
    return '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">';
}

/** One numbered todo item, with its primary action and optional "at the track" secondary form. */
function homeRenderTodoItem(int $n, array $item, string $csrf): string {
    $out = '<li class="hub-todo-item"><span class="hub-todo-n">' . h((string)$n) . '</span>';
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

/** The tag ("I'm going") form for one event, inside that event's card: pick the car (or name it). */
function homeRenderTagForm(array $event, array $cars, string $csrf, bool $offerReminders = false): string {
    $eid = (int)$event['id'];
    $out = '<form method="post" action="index.php" class="hub-line hub-tag-form">' . homeCsrfField($csrf)
        . '<input type="hidden" name="action" value="tag">'
        . '<input type="hidden" name="event_id" value="' . h((string)$eid) . '">';
    if (count($cars) === 1) {
        $car = array_values($cars)[0];
        $out .= '<span>' . h(carDisplayName($car)) . '</span>'
            . '<input type="hidden" name="car_id" value="' . h((string)$car['id']) . '">';
    } else {
        $out .= '<label class="visually-hidden" for="tag-car-' . $eid . '">Car for ' . h((string)$event['name']) . '</label>'
            . '<select id="tag-car-' . $eid . '" name="car_id">';
        foreach ($cars as $car) {
            $out .= '<option value="' . h((string)$car['id']) . '">' . h(carDisplayName($car)) . '</option>';
        }
        $out .= '</select>';
    }
    if ($offerReminders) $out .= reminderOptInFieldsHtml();
    $out .= '<button type="submit" class="hub-btn">I\'m going</button></form>';
    return $out;
}

/** The cars (keyed by id) that can go to $event: a car stored for the other season can't (mobile UX spec §A4). */
function homeCarsForEvent(array $cars, array $event): array {
    $other = ($event['discipline'] ?? 'summer') === 'ice' ? 'summer' : 'ice';
    return array_filter($cars, fn(array $c): bool => ($c['disciplines'] ?? null) !== $other);
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
        return (new DateTime(substr($eventDate, 0, 10)))->format('D, M j');
    } catch (Exception $e) {
        return '';
    }
}

/**
 * One upcoming event as a card: name, date and (when you're going) a to-do badge in the header;
 * inside, each car you're taking with "Not going anymore", then an "I'm going" row for the rest.
 * $readinessEvent is the buildReadiness() entry when you're going, null otherwise.
 */
function homeEventCardHtml(array $event, ?array $readinessEvent, array $cars, string $csrf, bool $offerReminders): string {
    $out = '<section class="hub-card hub-event"><div class="hub-event-head"><h3>' . h((string)$event['name']) . '</h3>';
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
            ? '<span class="hub-status hub-status--todo">' . h($todo . ' ' . homePlural($todo, 'thing', 'things') . ' to do') . '</span>'
            : '<span class="hub-status hub-status--ok">All set</span>';
    }
    $out .= '</div>';
    foreach (array_keys($goingCarIds) as $carId) {
        if (isset($cars[$carId])) $out .= homeRenderUntagForm($event, $cars[$carId], $csrf);
    }
    $notGoing = homeCarsForEvent(array_diff_key($cars, $goingCarIds), $event);
    if ($notGoing) {
        $out .= homeRenderTagForm($event, $notGoing, $csrf, $offerReminders);
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

/** The untag ("Not going anymore") form for one car already tagged to an event. */
function homeRenderUntagForm(array $event, array $car, string $csrf): string {
    return '<form method="post" action="index.php" class="hub-line">' . homeCsrfField($csrf)
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

    $out = '<h1>' . h(homeHeadline($readiness)) . '</h1>';

    $first = $events[0] ?? null;

    if ($first !== null) {
        $eventDate = (string)$first['event']['event_date'];
        try {
            $date = new DateTime(substr($eventDate, 0, 10));
            $today = new DateTime(date('Y-m-d'));
            $days = (int)$today->diff($date)->format('%a');
            $out .= '<p class="hub-intro">' . h($date->format('l, F j')) . ' &middot; in ' . h((string)$days) . ' days</p>';
        } catch (Exception $e) {
            // Unparseable date: skip the date line.
        }

        $items = $first['items'];
        $todoItems = array_values(array_filter($items, fn(array $i): bool => $i['state'] === 'todo'));
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

    // A media profile that was sent back or hidden is something to act on, so it sits with the to-dos.
    $mediaPrompt = !empty($vm['mediaPrompt']) ? (is_array($vm['mediaPrompt']) ? $vm['mediaPrompt'] : ['kind' => 'invite']) : null;
    if ($mediaPrompt !== null && $mediaPrompt['kind'] === 'attention') {
        $out .= homeMediaPromptHtml($csrf, $mediaPrompt);
    }

    // Upcoming events: one card per event, soonest first.
    $out .= '<h2>Upcoming events</h2>';
    if (!$events && !$untagged) {
        $out .= '<p>No upcoming events yet.</p>';
    } else {
        $out .= '<p class="hub-section-intro">Say which events you\'re going to. ' . EVENTS_NOT_REGISTERING . '</p>';
        $cardsByDate = [];
        foreach ($events as $ev) $cardsByDate[] = [$ev['event'], $ev];
        foreach ($untagged as $event) $cardsByDate[] = [$event, null];
        usort($cardsByDate, fn(array $a, array $b): int => strcmp((string)$a[0]['event_date'], (string)$b[0]['event_date']));
        foreach ($cardsByDate as [$event, $readinessEvent]) {
            $out .= homeEventCardHtml($event, $readinessEvent, $cars, $csrf, $offerReminders);
        }
    }

    // At a glance
    $out .= '<h2>At a glance</h2><div class="hub-grid-2">';
    $out .= '<div class="hub-card"><h3>Garage</h3>';
    if (!$vm['cars']) {
        $out .= '<p>Start by adding your car.</p><a class="hub-btn" href="garage.php?action=add">Add a car</a>';
    } else {
        foreach ($vm['garage'] as $g) {
            $car = $g['car'];
            $decl = $g['declaration'];
            $usesSummer = $g['usesSummer'] ?? true;
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

/** The signed-out landing page. $iceNext (landingNextIsIce()) leads with ice tech instead of the summer calculator. */
function renderLandingHtml(array $seasonLinks, bool $iceNext = false): string
{
    $signIn = 'auth.php?action=login&amp;redirect=index.php';
    $register = 'auth.php?action=register&amp;redirect=index.php';
    $out = '<section class="hub-hero"><h1>WCMA Hub</h1>';
    if ($iceNext) {
        $out .= '<p class="hub-hero-tagline">Submit your ice tech sheet and track car and gear tech for the season.</p>'
            . '<div class="hub-hero-actions"><a class="hub-btn" href="' . $register . '">Create account</a>'
            . '<a class="hub-btn hub-btn--secondary" href="' . $signIn . '">Sign in</a></div>'
            . '<p><a href="calculator.php">Summer class calculator</a> · <a href="drivers-public.php">Meet the drivers</a></p></section>';
    } else {
        $out .= '<p class="hub-hero-tagline">Declare your class, submit tech sheets and track car and gear tech for the season.</p>'
            . '<div class="hub-hero-actions"><a class="hub-btn" href="calculator.php">Class Calculator</a>'
            . '<a class="hub-btn hub-btn--secondary" href="' . $signIn . '">Sign in</a>'
            . '<a class="hub-btn hub-btn--secondary" href="' . $register . '">Create account</a>'
            . '</div><p><a href="drivers-public.php">Meet the drivers</a></p></section>';
    }

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
