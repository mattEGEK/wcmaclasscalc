<?php
// wcma-calculator/media-page.php
//
// Markup for the Media section (Announcer, Media kit, Public review) and the public driver page.
// Pure: no DB, no session, no echo. Callers must have loaded view_helpers.php (h()) and media-lib.php.

function renderMediaEntryHtml(array $e, bool $forKit): string {
    $id = (int)$e['driver_id'];
    $out = '<article class="hub-card media-entry">';
    if ($e['has_photo']) {
        $out .= '<img class="media-entry-photo" src="media-photo.php?driver_id=' . $id . '" alt="' . h($e['name']) . '" loading="lazy">';
    }
    $out .= '<div class="media-entry-body"><h3>' . ($e['number'] !== '' ? '<span class="media-number">#' . h($e['number']) . '</span> ' : '') . h($e['name']) . '</h3>';
    if ((string)$e['pronunciation'] !== '') $out .= '<p class="media-say">Say it: ' . h((string)$e['pronunciation']) . '</p>';
    if ($e['car'] !== '' || $e['class'] !== '') $out .= '<p class="media-car">' . h(trim($e['car'] . ($e['class'] !== '' ? ' · ' . $e['class'] : ''))) . '</p>';
    $facts = mediaFactsLine($e);
    if ($facts !== '') $out .= '<p class="media-facts">' . h($facts) . '</p>';
    if (trim($e['blurb']) !== '') $out .= '<p class="media-blurb">' . nl2br(h($e['blurb'])) . '</p>';
    if ($e['sponsors']) {
        $links = array_map(fn(array $s): string => $s['url']
            ? '<a href="' . h($s['url']) . '" target="_blank" rel="sponsored noopener">' . h($s['name']) . '</a>'
            : h($s['name']), $e['sponsors']);
        $out .= '<p class="media-sponsors-line">Supported by: ' . implode(', ', $links) . '</p>';
    }
    if ($forKit) {
        $out .= '<textarea class="media-copy-src" readonly hidden>' . h(mediaCopyText($e)) . '</textarea>'
            . '<p class="hub-line"><button type="button" class="hub-btn hub-btn--secondary" data-copy>Copy text</button>'
            . ($e['has_photo'] ? ' <a href="media-photo.php?driver_id=' . $id . '" download>Download photo</a>' : '')
            . ((string)$e['social_handle'] !== '' ? ' <span class="form-hint">@' . h((string)$e['social_handle']) . '</span>' : '')
            . '</p>';
    }
    return $out . '</div></article>';
}

function mediaEventPickerHtml(array $events, int $eventId, string $action, bool $allowAll): string {
    $out = '<form method="get" action="media.php" class="hub-line media-picker">'
        . ($action !== '' ? '<input type="hidden" name="action" value="' . h($action) . '">' : '')
        . '<label for="media-event">Event</label><select id="media-event" name="event">';
    if ($allowAll) $out .= '<option value="0"' . ($eventId === 0 ? ' selected' : '') . '>All drivers who shared a profile</option>';
    foreach ($events as $ev) {
        $out .= '<option value="' . (int)$ev['id'] . '"' . ((int)$ev['id'] === $eventId ? ' selected' : '') . '>'
            . h($ev['name'] . ' — ' . $ev['event_date']) . '</option>';
    }
    return $out . '</select><button type="submit" class="hub-btn hub-btn--secondary">Show</button></form>';
}

function renderAnnouncerHtml(array $vm): string {
    $out = '<h1>Announcer</h1>';
    if (!$vm['events']) return $out . '<p>No events yet.</p>';
    $out .= mediaEventPickerHtml($vm['events'], (int)$vm['eventId'], '', false)
        . '<p class="form-hint no-print">Cars in number order. Drivers come from tech sheets for this event, or the car\'s last tech sheet, or the car owner.</p>';
    if (!$vm['roster']) $out .= '<p>No cars on this event yet.</p>';
    foreach ($vm['roster'] as $car) {
        $out .= '<section class="media-car-block"><h2><span class="media-number">#' . h($car['number']) . '</span> '
            . h($car['car']) . ($car['class'] !== '' ? ' <span class="media-class">' . h($car['class']) . '</span>' : '') . '</h2>';
        foreach ($car['drivers'] as $d) {
            $out .= $d['entry'] !== null ? renderMediaEntryHtml($d['entry'], false)
                : '<p class="media-bare">' . h($d['name']) . ' <span class="form-hint">No media profile</span></p>';
        }
        $out .= '</section>';
    }
    $out .= '<form method="get" action="media.php" class="hub-card no-print"><input type="hidden" name="event" value="' . (int)$vm['eventId'] . '">'
        . '<label for="media-q">Add a driver who is not on the list</label>'
        . '<input type="search" id="media-q" name="q" value="' . h((string)$vm['q']) . '" maxlength="100">'
        . '<button type="submit" class="hub-btn hub-btn--secondary">Find</button></form>';
    if ((string)$vm['q'] !== '') {
        $out .= '<h2>Added for this view</h2>';
        if (!$vm['extra']) $out .= '<p>No shared profiles match "' . h((string)$vm['q']) . '".</p>';
        foreach ($vm['extra'] as $e) $out .= renderMediaEntryHtml($e, false);
    }
    return $out;
}

function renderMediaKitHtml(array $vm): string {
    $out = '<h1>Media kit</h1>' . mediaEventPickerHtml($vm['events'], (int)$vm['eventId'], 'kit', true);
    if (!$vm['entries']) return $out . '<p>No drivers have shared a profile yet.</p>';
    if ($vm['zip']) {
        $out .= '<p><a class="hub-btn" href="media.php?action=kit-zip&amp;event=' . (int)$vm['eventId'] . '">Download all (.zip)</a></p>';
    }
    foreach ($vm['entries'] as $e) $out .= renderMediaEntryHtml($e, true);
    return $out;
}
