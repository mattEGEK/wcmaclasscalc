<?php
// wcma-calculator/admin-clubs.php
//
// Admin: host clubs (clubs spec 2026-09-29 §1; admin desktop UX spec §5): a table with add and edit
// modals. Each club has a name and a MotorsportReg link that competitors see after submitting a tech sheet. Loaded by admin.php, which has already checked
// the admin role; POST handlers are reached through adminRequirePost() (CSRF).

function handleClubsList(PDO $pdo): void {
    $clubs = db_get_clubs($pdo);
    $edit = adminEditTarget($_GET);
    $place = adminFlashPlacement(getFlash(), $edit, array_merge(['new'], array_column($clubs, 'code')));
    adminRenderPage('Clubs', 'clubs', renderClubsPageHtml($clubs, generateCsrfToken(), $place['dialog'], $edit), $place['top']);
}

function handleClubSave(PDO $pdo): void {
    $isNew = ($_POST['is_new'] ?? '') === '1';
    $v = clubValidate((string)($_POST['code'] ?? ''), (string)($_POST['name'] ?? ''), (string)($_POST['msr_url'] ?? ''));
    if (!$isNew && db_get_club($pdo, $v['code']) === null) {
        setFlash('Club not found.', 'error');
        adminRedirect('admin.php?action=clubs');
    }
    $reopen = 'admin.php?action=clubs&edit=' . ($isNew ? 'new' : rawurlencode($v['code']));
    if (!$v['ok']) {
        setFlash((string)$v['error'], 'error');
        adminRedirect($reopen);
    }
    if ($isNew && db_get_club($pdo, $v['code']) !== null) {
        setFlash('A club with the code ' . $v['code'] . ' already exists.', 'error');
        adminRedirect($reopen);
    }
    $msrInput = trim((string)($_POST['msr_org'] ?? ''));
    $currentOrgId = $isNew ? '' : (string)(db_get_club($pdo, $v['code'])['msr_org_id'] ?? '');
    $orgId = $currentOrgId;
    if ($msrInput !== $currentOrgId) {   // only ask MotorsportReg when the field changed
        $org = msrOrgIdFromInput($msrInput, 'msrHttpGet');
        if (!$org['ok']) {
            setFlash($org['error'], 'error');
            adminRedirect($reopen);
        }
        $orgId = $org['id'];
    }
    if ($isNew) {
        db_create_club($pdo, $v['code'], $v['name'], $v['url']);
        setFlash('Club added.', 'success');
    } else {
        db_update_club($pdo, $v['code'], $v['name'], $v['url'], !empty($_POST['active']));
        setFlash('Club saved.', 'success');
    }
    db_set_club_msr_org_id($pdo, $v['code'], $orgId);
    adminRedirect('admin.php?action=clubs');
}

/** The page body: intro, Add club, a read-only table, then the add modal and one modal per club. Pure. */
function renderClubsPageHtml(array $clubs, string $csrf, ?array $dialogFlash = null, ?string $edit = null): string {
    $csrfField = adminCsrfField($csrf);
    $out = '<p class="admin-intro">Clubs host events. A club\'s MotorsportReg link is shown to competitors after they '
        . 'submit a tech sheet, so they can register for the event. NASCC and WSCC run the ice races.</p>'
        . '<div class="admin-toolbar">' . adminAddButton('club-dialog-new', 'Add club') . '</div>'
        . '<table class="data-table admin-table" id="clubs-table"><thead><tr><th>Code</th><th>Name</th><th>MotorsportReg</th>'
        . '<th>Calendar</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
    $dialogs = adminDialogHtml('club-dialog-new', 'Add a club',
        '<form method="post" action="admin.php?action=club-save" class="admin-form">' . $csrfField
        . '<input type="hidden" name="is_new" value="1">'
        . adminField('club-new-code', 'Short code (letters, numbers or dashes)', '<input type="text" id="club-new-code" name="code" maxlength="12" required placeholder="ESCC">')
        . adminField('club-new-name', 'Name', '<input type="text" id="club-new-name" name="name" maxlength="120" required>')
        . adminField('club-new-url', 'MotorsportReg link (optional)', '<input type="url" id="club-new-url" name="msr_url" placeholder="https://">', true)
        . adminField('club-new-msr', 'MotorsportReg calendar (club page address or organization ID)',
            '<input type="text" id="club-new-msr" name="msr_org" value="" placeholder="https://www.motorsportreg.com/orgs/…">', true)
        . '<p class="form-hint admin-form-wide">New race events on this club\'s MotorsportReg calendar are listed under Events → From MotorsportReg. Leave blank to turn this off.</p>'
        . adminDialogActions('Add club') . '</form>',
        $edit === 'new' ? $dialogFlash : null);
    if (!$clubs) {
        $out .= '<tr><td colspan="6" class="empty-row">No clubs yet.</td></tr>';
    }
    foreach ($clubs as $c) {
        $code = (string)$c['code'];
        $id = 'club-' . strtolower($code);
        $url = (string)$c['msr_url'];
        $active = (int)$c['active'] === 1;
        $out .= '<tr id="' . h($id) . '"><td data-label="Code"><strong>' . h($code) . '</strong></td>'
            . '<td data-label="Name">' . h((string)$c['name']) . '</td>'
            . '<td data-label="MotorsportReg">' . ($url !== ''
                ? '<a class="admin-link" href="' . h($url) . '" target="_blank" rel="noopener">Open ↗</a>' : '—') . '</td>'
            . '<td data-label="Calendar">' . ((string)($c['msr_org_id'] ?? '') !== '' ? adminChip('Connected', 'ok') : '—') . '</td>'
            . '<td data-label="Status">' . ($active ? adminChip('Active', 'ok') : adminChip('Inactive', 'fail')) . '</td>'
            . '<td class="admin-cell-actions">' . adminEditButton('club-dialog-' . strtolower($code), $code) . '</td></tr>';
        $dialogs .= adminDialogHtml('club-dialog-' . strtolower($code), 'Edit ' . $code,
            '<form method="post" action="admin.php?action=club-save" class="admin-form">' . $csrfField
            . '<input type="hidden" name="code" value="' . h($code) . '">'
            . adminField($id . '-name', 'Name', '<input type="text" id="' . h($id) . '-name" name="name" maxlength="120" required value="' . h((string)$c['name']) . '">', true)
            . adminField($id . '-url', 'MotorsportReg link (optional)', '<input type="url" id="' . h($id) . '-url" name="msr_url" placeholder="https://" value="' . h($url) . '">', true)
            . adminField($id . '-msr', 'MotorsportReg calendar (club page address or organization ID)',
                '<input type="text" id="' . h($id) . '-msr" name="msr_org" value="' . h((string)($c['msr_org_id'] ?? '')) . '" placeholder="https://www.motorsportreg.com/orgs/…">', true)
            . '<p class="form-hint admin-form-wide">New race events on this club\'s MotorsportReg calendar are listed under Events → From MotorsportReg. Leave blank to turn this off.</p>'
            . '<label class="admin-form-wide"><input type="checkbox" name="active" value="1"' . ($active ? ' checked' : '') . '> Active — can host new events</label>'
            . adminDialogActions('Save') . '</form>',
            $edit === $code ? $dialogFlash : null);
    }
    return $out . '</tbody></table><div class="admin-dialogs">' . $dialogs . '</div>';
}
