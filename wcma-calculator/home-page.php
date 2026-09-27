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
            . "<button type=\"submit\" class=\"hub-btn hub-btn--link\">I'll do it at the track</button></form>";
    }
    $out .= '</div></li>';
    return $out;
}

/** The tag ("I'm going") form for one untagged event. */
function homeRenderTagForm(array $event, array $cars, string $csrf, bool $offerReminders = false): string {
    $eid = (int)$event['id'];
    $out = '<form method="post" action="index.php" class="hub-line hub-tag-form">' . homeCsrfField($csrf)
        . '<input type="hidden" name="action" value="tag">'
        . '<input type="hidden" name="event_id" value="' . h((string)$eid) . '">';
    $out .= '<span>' . h((string)$event['name']) . '</span>';
    if (count($cars) === 1) {
        $car = array_values($cars)[0];
        $out .= '<input type="hidden" name="car_id" value="' . h((string)$car['id']) . '">';
    } else {
        $out .= '<select name="car_id">';
        foreach ($cars as $car) {
            $out .= '<option value="' . h((string)$car['id']) . '">' . h(carDisplayName($car)) . '</option>';
        }
        $out .= '</select>';
    }
    if ($offerReminders) $out .= reminderOptInFieldsHtml();
    $out .= '<button type="submit" class="hub-btn">I\'m going</button></form>';
    return $out;
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

        if ($infoItems) {
            $out .= '<h2>With an inspector</h2>';
            foreach ($infoItems as $item) {
                $out .= '<div class="hub-card"><strong>' . h($item['label']) . '</strong>';
                if ($item['detail'] !== '') {
                    $out .= '<div>' . h($item['detail']) . '</div>';
                }
                $out .= '</div>';
            }
        }

        if ($doneItems) {
            $out .= '<details class="hub-done" open><summary>Already done for ' . h((string)$first['event']['name'])
                . ' (' . h((string)count($doneItems)) . ')</summary><ul>';
            foreach ($doneItems as $item) {
                $out .= '<li>' . h($item['label']) . '</li>';
            }
            $out .= '</ul></details>';
        }
    }

    // Tagged cars per event, derived from the first event's tech_sheet items (for "not going anymore").
    $out .= '<h2>Upcoming events: are you going?</h2>';
    if (!$events && !$untagged) {
        $out .= '<p>No upcoming events yet.</p>';
    } else {
        if ($events) {
            $out .= '<h3>Events you\'re going to</h3>';
            foreach ($events as $ev) {
                $eventCarIds = [];
                foreach ($ev['items'] as $item) {
                    if ($item['kind'] === 'tech_sheet') {
                        $eventCarIds[(int)$item['subject_id']] = true;
                    }
                }
                foreach (array_keys($eventCarIds) as $carId) {
                    if (isset($cars[$carId])) {
                        $out .= homeRenderUntagForm($ev['event'], $cars[$carId], $csrf);
                    }
                }
                $untaggedCars = array_diff_key($cars, $eventCarIds);
                if ($untaggedCars) {
                    $out .= homeRenderTagForm($ev['event'], $untaggedCars, $csrf, $offerReminders);
                }
            }
        }
        if ($untagged) {
            foreach ($untagged as $event) {
                if ($cars) {
                    $out .= homeRenderTagForm($event, $cars, $csrf, $offerReminders);
                } else {
                    $out .= '<p class="hub-line"><span>' . h((string)$event['name']) . '</span></p>';
                }
            }
        }
        $out .= '<p class="form-hint">' . EVENTS_NOT_REGISTERING . '</p>';
    }

    // At a glance
    $out .= '<h2>At a glance</h2><div class="hub-grid-2">';
    $out .= '<div class="hub-card"><h3>Garage</h3>';
    if (!$vm['cars']) {
        $out .= '<p>Start by adding your car and declaring its class</p><a class="hub-btn" href="garage.php?action=add">Add a car</a>';
    } else {
        foreach ($vm['garage'] as $g) {
            $car = $g['car'];
            $decl = $g['declaration'];
            $out .= '<div class="hub-line"><span class="hub-plate">' . h((string)$car['car_number']) . '</span>'
                . '<span>' . h(carDisplayName($car));
            if ($decl !== null) {
                $out .= ' <span class="hub-class">' . h((string)$decl['calculated_class']) . '</span>'
                    . ' <span class="hub-status ' . h(homeStatusClass($decl['review_status'])) . '">' . h(declarationReviewLabel($decl['review_status'])) . '</span>';
            }
            $out .= '</span><span class="hub-status ' . h(homeStatusClass($g['techState'])) . '">' . h($g['techLabel']) . '</span></div>';
        }
        $out .= '<a class="hub-btn hub-btn--secondary" href="garage.php">Open garage &rarr;</a>';
    }
    $out .= '</div>';

    $out .= '<div class="hub-card"><h3>Drivers</h3>';
    foreach ($vm['drivers'] as $d) {
        $out .= '<div class="hub-line"><span>' . h($d['name']) . ($d['isSelf'] ? ' (you)' : '') . '</span>'
            . '<span class="hub-status ' . h(homeStatusClass($d['gearState'])) . '">' . h($d['gearLabel']) . '</span></div>';
    }
    $out .= '<a class="hub-btn hub-btn--secondary" href="drivers.php">Manage drivers &rarr;</a></div>';
    $out .= '</div>';

    // This season on MotorsportReg
    if ($vm['seasonLinks']) {
        $out .= '<div class="hub-card"><h3>This season on MotorsportReg</h3>';
        foreach ($vm['seasonLinks'] as $link) {
            $out .= '<div class="hub-line"><span>' . h((string)$link['label']) . '</span>'
                . '<a href="' . h((string)$link['url']) . '" target="_blank" rel="noopener">Open &#8599;</a></div>';
        }
        $out .= '</div>';
    }

    // Next after that
    if (isset($events[1])) {
        $second = $events[1];
        $secondTodo = count(array_filter($second['items'], fn(array $i): bool => $i['state'] === 'todo'));
        $out .= '<p class="hub-intro">Next after that: <strong>' . h((string)$second['event']['name']) . '</strong>, '
            . h((string)$secondTodo) . ' ' . h(homePlural($secondTodo, 'thing', 'things')) . ' to do.</p>';
    }

    return $out;
}

function renderLandingHtml(array $seasonLinks): string
{
    $out = '<section class="hub-hero"><h1>WCMA Hub</h1>';
    $out .= '<p class="hub-hero-tagline">Declare your class, submit tech sheets and track car and gear tech for the season.</p>';
    $out .= '<div class="hub-hero-actions">';
    $out .= '<a class="hub-btn" href="calculator.php">Class Calculator</a>';
    $out .= '<a class="hub-btn hub-btn--secondary" href="auth.php?action=login&amp;redirect=index.php">Sign in</a>';
    $out .= '<a class="hub-btn hub-btn--secondary" href="auth.php?action=register&amp;redirect=index.php">Create account</a>';
    $out .= '</div></section>';

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
