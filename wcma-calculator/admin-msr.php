<?php
// wcma-calculator/admin-msr.php
//
// Admin → Events → From MotorsportReg (spec 2026-09-29-msr-calendar-import-design.md §3): new race
// events from the clubs' MSR calendars (add / attach / ignore) and changes to ones already in the hub
// (apply / keep). Loaded by admin.php, which has already checked the admin role; every action is a
// CSRF-checked POST through adminRequirePost(). The actions themselves live in msr-lib.php.

function msrDialogKey(string $msrId): string {
    return strtolower($msrId);
}

function handleMsrList(PDO $pdo): void {
    $rows = db_get_msr_events($pdo);
    $edit = adminEditTarget($_GET);
    $place = adminFlashPlacement(getFlash(), $edit, array_column($rows, 'msr_id'));
    $body = renderMsrPageHtml($rows, db_get_all_events($pdo), db_get_clubs($pdo), msrSyncStatus($pdo), generateCsrfToken(), $place['dialog'], $edit);
    adminRenderPage('From MotorsportReg', 'events', $body, $place['top']);
}

function adminMsrIdFromPost(): string {
    $id = strtoupper(trim((string)($_POST['msr_id'] ?? '')));
    return preg_match(MSR_ID_PATTERN, $id) ? $id : '';
}

function handleMsrAdd(PDO $pdo): void {
    $id = adminMsrIdFromPost();
    $f = adminEventFromPost($_POST, array_column(db_get_clubs($pdo, true), 'code'));
    if (!$f['ok']) {
        setFlash($f['error'], 'error');
        adminRedirect('admin.php?action=msr&edit=' . rawurlencode($id));
    }
    $error = msrAddToHub($pdo, $id, $f);
    setFlash($error ?? 'Added ' . $f['name'] . ' to the hub.', $error === null ? 'success' : 'error');
    adminRedirect('admin.php?action=msr');
}

function handleMsrAttach(PDO $pdo): void {
    $error = msrAttach($pdo, adminMsrIdFromPost(), is_scalar($_POST['event_id'] ?? null) ? (int)$_POST['event_id'] : 0);
    setFlash($error ?? 'Attached to the hub event.', $error === null ? 'success' : 'error');
    adminRedirect('admin.php?action=msr');
}

/** Ignore, restore, apply or keep: one MSR id, one lib call. */
function handleMsrSimple(PDO $pdo, string $action): void {
    $fn = ['msr-ignore' => 'msrIgnore', 'msr-restore' => 'msrRestore', 'msr-apply' => 'msrApply', 'msr-keep' => 'msrKeep'][$action];
    $done = ['msr-ignore' => 'Ignored.', 'msr-restore' => 'Restored.', 'msr-apply' => 'Hub event updated.', 'msr-keep' => 'Kept as is.'][$action];
    $error = $fn($pdo, adminMsrIdFromPost());
    setFlash($error ?? $done, $error === null ? 'success' : 'error');
    adminRedirect('admin.php?action=msr');
}

function handleMsrCheck(PDO $pdo): void {
    $results = msrSyncAll($pdo, 'msrHttpGet', date('Y-m-d'), date('Y-m-d H:i:s'));
    if (!$results) {
        setFlash('No clubs are connected to MotorsportReg yet. Add a club\'s MotorsportReg page on the Clubs tab.', 'error');
    } else {
        $failed = array_values(array_filter($results, fn(array $r): bool => !$r['ok']));
        setFlash($failed ? 'Checked MotorsportReg. ' . implode(' ', array_map(fn(array $r): string => $r['code'] . ': ' . $r['error'], $failed))
            : 'Checked MotorsportReg.', $failed ? 'error' : 'success');
    }
    adminRedirect('admin.php?action=msr');
}

/** One MSR event's cells: dates, name (+ chips), club, MSR link. */
function adminMsrEventCells(array $r): string {
    $chip = $r['type'] === 'Ice Racing' ? adminChip('Ice', 'info') : adminChip('Race', 'info');
    if ((int)$r['cancelled'] === 1) $chip .= adminChip('Cancelled', 'fail');
    return '<td data-label="Date">' . h(msrDateRange((string)$r['start_date'], (string)$r['end_date'])) . '</td>'
        . '<td data-label="Event"><strong>' . h((string)$r['name']) . '</strong> <span class="admin-chips">' . $chip . '</span>'
        . ((string)$r['venue'] !== '' ? '<span class="admin-sub">' . h((string)$r['venue']) . '</span>' : '')
        . ((string)$r['detail_url'] !== '' ? '<a class="admin-link" href="' . h((string)$r['detail_url']) . '" target="_blank" rel="noopener">Open on MotorsportReg ↗</a>' : '')
        . '</td><td data-label="Club">' . h((string)$r['club_code']) . '</td>';
}

function adminMsrPostForm(string $action, string $msrId, string $csrf, string $button, string $class = 'btn btn-secondary', string $confirm = ''): string {
    return '<form method="post" action="admin.php?action=' . h($action) . '"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . adminCsrfField($csrf)
        . '<input type="hidden" name="msr_id" value="' . h($msrId) . '"><button type="submit" class="' . h($class) . '">' . h($button) . '</button></form>';
}

/** The review page body. Pure. */
function renderMsrPageHtml(array $rows, array $hubEvents, array $clubs, array $status, string $csrf, ?array $dialogFlash, ?string $edit): string {
    $byId = [];
    foreach ($hubEvents as $e) $byId[(int)$e['id']] = $e;
    $changed = $new = $ignored = [];
    foreach ($rows as $r) {
        if ($r['status'] === 'new') $new[] = $r;
        elseif ($r['status'] === 'ignored') $ignored[] = $r;
        elseif (msrChanges($r)) $changed[] = $r;
    }
    $out = '<p><a class="hub-back-link" href="admin.php?action=events">&larr; Back to events</a></p>'
        . '<p class="admin-intro">Race events from the clubs\' MotorsportReg calendars. Add them to the hub, attach extra events to a '
        . 'weekend that\'s already in the hub, or ignore them. Drivers see nothing here until you add it.</p>';
    $dialogs = '';

    if ($changed) {
        $out .= '<h2>Changed on MotorsportReg</h2><table class="data-table admin-table" id="msr-changed"><thead><tr>'
            . '<th>Date</th><th>Event</th><th>Club</th><th>Hub event</th><th>What changed</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
        foreach ($changed as $r) {
            $changes = msrChanges($r);
            $hub = $byId[(int)$r['hub_event_id']] ?? null;
            $list = '<ul class="admin-msr-changes">';
            foreach ($changes as $c) $list .= '<li>' . h(msrChangeText($c)) . '</li>';
            $list .= '</ul>';
            if ((int)$r['is_primary'] !== 1) {
                $list .= '<p class="form-hint">One of several MotorsportReg events for this hub event — change the hub event by hand if needed.</p>';
            }
            $actions = '';
            if ((int)$r['is_primary'] === 1 && $r['status'] !== 'gone') {
                $cancels = in_array(['field' => 'cancelled', 'old' => '0', 'new' => '1'], $changes, true);
                $confirm = '';
                if ($cancels) {
                    $others = array_map('msrChangeText', array_values(array_filter($changes, fn(array $c): bool => $c['field'] !== 'cancelled')));
                    $confirm = 'Deactivate ' . ($hub !== null ? (string)$hub['name'] : 'this event') . '? It was cancelled on MotorsportReg, so drivers won\'t be able to tag it or pick it for new tech sheets.'
                        . ($others ? ' This also applies: ' . implode('; ', $others) . '.' : '');
                }
                $actions .= adminMsrPostForm('msr-apply', (string)$r['msr_id'], $csrf, $cancels ? 'Deactivate hub event' : 'Apply to hub event', 'btn btn-primary', $confirm);
            }
            $actions .= adminMsrPostForm('msr-keep', (string)$r['msr_id'], $csrf, 'Keep as is');
            $out .= '<tr>' . adminMsrEventCells($r)
                . '<td data-label="Hub event">' . h($hub !== null ? (string)$hub['name'] : '—') . '</td>'
                . '<td data-label="What changed">' . $list . '</td>'
                . '<td class="admin-cell-actions"><div class="admin-row-actions">' . $actions . '</div></td></tr>';
        }
        $out .= '</tbody></table>';
    }

    if ($new) {
        $out .= '<h2>New on MotorsportReg</h2><table class="data-table admin-table" id="msr-new"><thead><tr>'
            . '<th>Date</th><th>Event</th><th>Club</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
        foreach ($new as $r) {
            $id = (string)$r['msr_id'];
            $key = msrDialogKey($id);
            $actions = '';
            if ((int)$r['cancelled'] !== 1) {
                $actions .= '<button type="button" class="btn btn-primary admin-edit" data-dialog-open="msr-add-' . h($key) . '">Add to hub</button>'
                    . '<button type="button" class="btn btn-secondary admin-edit" data-dialog-open="msr-attach-' . h($key) . '">Add to an existing event</button>';
                $prefill = ['name' => $r['name'], 'event_date' => $r['start_date'], 'location' => $r['venue'],
                            'discipline' => $r['type'] === 'Ice Racing' ? 'ice' : 'summer', 'host_club' => $r['club_code'], 'msr_url' => $r['detail_url']];
                $flash = $edit === $id ? $dialogFlash : null;
                $dialogs .= adminDialogHtml('msr-add-' . $key, 'Add to the hub',
                    '<form method="post" action="admin.php?action=msr-add" class="admin-form">' . adminCsrfField($csrf)
                    . '<input type="hidden" name="msr_id" value="' . h($id) . '">'
                    . adminEventFieldsHtml('msr-' . $key, $prefill, $clubs) . adminDialogActions('Add event') . '</form>', $flash, (string)$r['name']);
                $suggest = msrSuggestEvent($r, $hubEvents);
                $options = '<option value="">Choose an event</option>';
                foreach ($hubEvents as $e) {
                    if ((int)$e['active'] !== 1) continue;
                    $options .= '<option value="' . (int)$e['id'] . '"' . ((int)$e['id'] === $suggest ? ' selected' : '') . '>'
                        . h(date('M j, Y', strtotime((string)$e['event_date'])) . ' — ' . $e['name']) . '</option>';
                }
                $dialogs .= adminDialogHtml('msr-attach-' . $key, 'Add to an existing event',
                    '<form method="post" action="admin.php?action=msr-attach" class="admin-form">' . adminCsrfField($csrf)
                    . '<input type="hidden" name="msr_id" value="' . h($id) . '">'
                    . adminField('msr-attach-' . $key . '-event', 'Hub event', '<select id="msr-attach-' . h($key) . '-event" name="event_id" required>' . $options . '</select>', true)
                    . '<p class="form-hint admin-form-wide">For another part of a weekend that is already in the hub (e.g. its Time Attack or Enduro).</p>'
                    . adminDialogActions('Attach') . '</form>', null, (string)$r['name']);
            }
            $actions .= adminMsrPostForm('msr-ignore', $id, $csrf, 'Ignore', 'link-button');
            $out .= '<tr>' . adminMsrEventCells($r) . '<td class="admin-cell-actions"><div class="admin-row-actions">' . $actions . '</div></td></tr>';
        }
        $out .= '</tbody></table>';
    }

    if (!$changed && !$new) $out .= '<p>Nothing new on MotorsportReg.</p>';

    if ($ignored) {
        $out .= '<details class="admin-msr-ignored"><summary>' . count($ignored) . ' ignored</summary><ul>';
        foreach ($ignored as $r) {
            $out .= '<li>' . h(msrDateRange((string)$r['start_date'], (string)$r['end_date']) . ' — ' . $r['name'] . ' (' . $r['club_code'] . ')')
                . adminMsrPostForm('msr-restore', (string)$r['msr_id'], $csrf, 'Restore', 'link-button') . '</li>';
        }
        $out .= '</ul></details>';
    }

    $out .= '<section class="detail-card"><h2>Last checked</h2><ul class="admin-msr-status">';
    foreach ($status as $s) {
        $when = $s['ok_at'] !== '' ? date('M j, g:i a', strtotime($s['ok_at'])) : 'never';
        $out .= '<li><strong>' . h($s['name']) . ':</strong> '
            . h($s['error'] !== '' ? 'Failed: ' . $s['error'] . ' (last success ' . $when . ')' : $when) . '</li>';
    }
    if (!$status) $out .= '<li>No clubs are connected yet. Add a club\'s MotorsportReg page on the <a href="admin.php?action=clubs">Clubs</a> tab.</li>';
    $out .= '</ul><form method="post" action="admin.php?action=msr-check">' . adminCsrfField($csrf)
        . '<button type="submit" class="btn btn-secondary" data-loading-text="Checking…">Check now</button></form>'
        . '<p class="form-hint">Event data from MotorsportReg.com.</p></section>';

    return $out . '<div class="admin-dialogs">' . $dialogs . '</div>';
}
