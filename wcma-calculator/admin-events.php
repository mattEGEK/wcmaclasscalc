<?php
// wcma-calculator/admin-events.php
//
// Admin: Events (admin desktop UX spec 2026-09-29 §4). A read-only list; Add event and each row's Edit
// open a modal with name, date, location, discipline and host club. Loaded by admin.php, which has
// already checked the admin role; POST handlers are reached through adminRequirePost() (CSRF).

/** The club codes an event may take: the active clubs, plus the one it already has (even if inactive). */
function adminEventClubCodes(PDO $pdo, ?array $event): array {
    $codes = array_column(db_get_clubs($pdo, true), 'code');
    $current = (string)($event['host_club'] ?? '');
    if ($current !== '' && !in_array($current, $codes, true)) $codes[] = $current;
    return $codes;
}

function handleEventsList(PDO $pdo): void {
    $events = db_get_all_events($pdo);
    $edit = adminEditTarget($_GET);
    $place = adminFlashPlacement(getFlash(), $edit, array_merge(['new'], array_column($events, 'id')));
    $body = renderEventsPageHtml($events, db_count_event_plans($pdo), db_get_clubs($pdo), generateCsrfToken(), $place['dialog'], $edit);
    adminRenderPage('Events', 'events', $body, $place['top']);
}

function handleEventCreate(PDO $pdo): void {
    $name = trim($_POST['name'] ?? '');
    $date = trim($_POST['event_date'] ?? '');
    $location = trim($_POST['location'] ?? '');
    if ($name === '' || $date === '') {
        setFlash('Event name and date are required.', 'error');
        adminRedirect('admin.php?action=events&edit=new');
    }
    $fields = iceEventFields($_POST, array_column(db_get_clubs($pdo, true), 'code'));
    $msrUrl = trim((string)($_POST['msr_url'] ?? ''));
    $error = $fields['ok'] ? eventMsrUrlError($msrUrl) : (string)$fields['error'];
    if ($error !== null) {
        setFlash($error, 'error');
        adminRedirect('admin.php?action=events&edit=new');
    }
    $id = db_create_event($pdo, $name, $date, $location !== '' ? $location : null, $fields['discipline'], $fields['club']);
    db_set_event_msr_url($pdo, $id, $msrUrl);
    setFlash('Event created.', 'success');
    adminRedirect('admin.php?action=events');
}

function handleEventUpdate(PDO $pdo, int $id): void {
    $current = db_get_event($pdo, $id);
    if ($current === null) {
        setFlash('Event not found.', 'error');
        adminRedirect('admin.php?action=events');
    }
    $name = trim($_POST['name'] ?? '');
    $date = trim($_POST['event_date'] ?? '');
    $location = trim($_POST['location'] ?? '');
    if ($name === '' || $date === '') {
        setFlash('Event name and date are required.', 'error');
        adminRedirect('admin.php?action=events&edit=' . $id);
    }

    // A POST without "discipline" (a stale form or script) keeps the event's discipline and club.
    $disciplineInput = $_POST;
    if (!array_key_exists('discipline', $disciplineInput)) {
        $disciplineInput['discipline'] = $current['discipline'] ?? 'summer';
        if (!array_key_exists('host_club', $disciplineInput)) {
            $disciplineInput['host_club'] = $current['host_club'] ?? '';
        }
    }
    $fields = iceEventFields($disciplineInput, adminEventClubCodes($pdo, db_get_event($pdo, $id)));
    // A POST without msr_url (a stale form or script) keeps the event's link.
    $msrUrl = array_key_exists('msr_url', $_POST) ? trim((string)$_POST['msr_url']) : (string)($current['msr_url'] ?? '');
    $error = $fields['ok'] ? eventMsrUrlError($msrUrl) : (string)$fields['error'];
    if ($error !== null) {
        setFlash($error, 'error');
        adminRedirect('admin.php?action=events&edit=' . $id);
    }
    db_update_event($pdo, $id, $name, $date, $location !== '' ? $location : null, $fields['discipline'], $fields['club']);
    db_set_event_msr_url($pdo, $id, $msrUrl);
    setFlash('Event updated.', 'success');
    adminRedirect('admin.php?action=events');
}

function handleEventSetActive(PDO $pdo, int $id, bool $active): void {
    db_set_event_active($pdo, $id, $active);
    setFlash($active ? 'Event reactivated.' : 'Event deactivated.', 'success');
    adminRedirect('admin.php?action=events');
}

/** The add/edit fields for one event ($e = [] for a new one). Pure. */
function adminEventFieldsHtml(string $p, array $e, array $clubs): string {
    $discipline = ($e['discipline'] ?? 'summer') === 'ice' ? 'ice' : 'summer';
    // Every active club (plus this event's current one): the ice-only rule is checked on save, so an
    // admin can switch discipline and club in one go.
    $options = '';
    foreach (eventClubOptions($clubs, ['discipline' => 'summer', 'host_club' => $e['host_club'] ?? null], iceClubCodes()) as $o) {
        $options .= '<option value="' . h($o['code']) . '"' . ($o['selected'] ? ' selected' : '') . '>' . h($o['label']) . '</option>';
    }
    $radio = static fn(string $v, string $label): string =>
        '<label><input type="radio" name="discipline" value="' . $v . '"' . ($discipline === $v ? ' checked' : '') . '> ' . $label . '</label>';
    return adminField($p . '-name', 'Name', '<input type="text" id="' . h($p) . '-name" name="name" required value="' . h((string)($e['name'] ?? '')) . '">', true)
        . adminField($p . '-date', 'Date', '<input type="date" id="' . h($p) . '-date" name="event_date" required value="' . h(substr((string)($e['event_date'] ?? ''), 0, 10)) . '">')
        . adminField($p . '-location', 'Location', '<input type="text" id="' . h($p) . '-location" name="location" value="' . h((string)($e['location'] ?? '')) . '">')
        . '<fieldset class="radio-row"><legend>Discipline</legend>' . $radio('summer', 'Summer') . $radio('ice', 'Ice') . '</fieldset>'
        . adminField($p . '-club', 'Host club', '<select id="' . h($p) . '-club" name="host_club">' . $options . '</select>')
        . '<p class="form-hint admin-form-wide">Ice events need NASCC or WSCC. Add other clubs on the <a href="admin.php?action=clubs">Clubs</a> tab.</p>'
        . adminField($p . '-msr', 'MotorsportReg event link (optional)', '<input type="url" id="' . h($p) . '-msr" name="msr_url" placeholder="https://www.motorsportreg.com/events/…" value="' . h((string)($e['msr_url'] ?? '')) . '">', true)
        . '<p class="form-hint admin-form-wide">Competitors get a Register button for this event. Leave it blank to send them to the host club\'s MotorsportReg page.</p>';
}

/** The Events page body: Add button, read-only table, then the add modal and one modal per event. Pure. */
function renderEventsPageHtml(array $events, array $going, array $clubs, string $csrf, ?array $dialogFlash, ?string $edit): string {
    $out = '<div class="admin-toolbar">' . adminAddButton('event-dialog-new', 'Add event') . '</div>'
        . '<table class="data-table admin-table" id="events-table"><thead><tr><th>Date</th><th>Name</th><th>Location</th>'
        . '<th>Host club</th><th>Registration</th><th>Going</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
    $dialogs = adminDialogHtml('event-dialog-new', 'Add an event',
        '<form method="post" action="admin.php?action=event-create" class="admin-form">' . adminCsrfField($csrf)
        . adminEventFieldsHtml('event-new', [], $clubs) . adminDialogActions('Add event') . '</form>',
        $edit === 'new' ? $dialogFlash : null);
    if (!$events) {
        $out .= '<tr><td colspan="8" class="empty-row">No events yet.</td></tr>';
    }
    foreach ($events as $e) {
        $id = (int)$e['id'];
        $n = (int)($going[$id] ?? 0);
        $isIce = ($e['discipline'] ?? 'summer') === 'ice';
        $club = (string)($e['host_club'] ?? '');
        $location = (string)($e['location'] ?? '');
        $msr = eventRegisterUrl($e);
        $out .= '<tr id="event-' . $id . '">'
            . '<td data-label="Date">' . h(date('M j, Y', strtotime((string)$e['event_date']))) . '</td>'
            . '<td data-label="Name">' . h((string)$e['name']) . ($isIce ? ' ' . adminChip('Ice', 'pending') : '') . '</td>'
            . '<td data-label="Location">' . h($location !== '' ? $location : '—') . '</td>'
            . '<td data-label="Host club">' . h($club !== '' ? $club : '—') . '</td>'
            . '<td data-label="Registration">' . ($msr !== ''
                ? '<a class="admin-link" href="' . h($msr) . '" target="_blank" rel="noopener">Open ↗</a>' : '—') . '</td>'
            . '<td data-label="Going">' . $n . ' ' . ($n === 1 ? 'car' : 'cars') . '</td>'
            . '<td data-label="Status">' . ((int)$e['active'] === 1 ? adminChip('Active', 'ok') : adminChip('Inactive', 'fail')) . '</td>'
            . '<td class="admin-cell-actions">' . adminEditButton('event-dialog-' . $id, (string)$e['name']) . '</td></tr>';
        $hidden = adminCsrfField($csrf) . '<input type="hidden" name="id" value="' . $id . '">';
        $danger = (int)$e['active'] === 1
            ? adminDangerHtml('Deactivate this event', 'Competitors can\'t tag it or pick it for new tech sheets. Nothing is deleted.',
                '<form method="post" action="admin.php?action=event-deactivate" data-confirm="Deactivate ' . h((string)$e['name'])
                . '? Competitors won\'t be able to tag it or pick it for new tech sheets.">' . $hidden
                . '<button type="submit" class="btn btn-secondary">Deactivate</button></form>')
            : adminDangerHtml('Reactivate this event', 'Competitors can tag it and pick it for tech sheets again.',
                '<form method="post" action="admin.php?action=event-activate">' . $hidden
                . '<button type="submit" class="btn btn-secondary">Reactivate</button></form>');
        $dialogs .= adminDialogHtml('event-dialog-' . $id, 'Edit ' . $e['name'],
            '<form method="post" action="admin.php?action=event-update" class="admin-form">' . $hidden
            . adminEventFieldsHtml('event-' . $id, $e, $clubs) . adminDialogActions('Save') . '</form>' . $danger,
            $edit === (string)$id ? $dialogFlash : null);
    }
    return $out . '</tbody></table><div class="admin-dialogs">' . $dialogs . '</div>';
}
