<?php
// wcma-calculator/admin-season-links.php
//
// Admin: this season's links for competitors (MotorsportReg waiver, licences, number reservation).
// Loaded by admin.php, which has already checked the admin role and CSRF.

function handleSeasonLinkSave(PDO $pdo, int $id): void {
    $v = seasonLinkValidate((string)($_POST['label'] ?? ''), (string)($_POST['url'] ?? ''), (string)($_POST['sort_order'] ?? '0'));
    if (!$v['ok']) {
        // The error shows inside the dialog it came from, which reopens.
        setFlash($v['error'], 'error');
        adminRedirect('admin.php?action=season-links&edit=' . ($id > 0 ? $id : 'new'));
    } elseif ($id > 0) {
        db_update_season_link($pdo, $id, $v['label'], $v['url'], $v['sort_order'], !empty($_POST['active']));
        setFlash('Link updated.', 'success');
    } else {
        db_create_season_link($pdo, $v['label'], $v['url'], $v['sort_order']);
        setFlash('Link added.', 'success');
    }
    adminRedirect('admin.php?action=season-links');
}

function handleSeasonLinkDelete(PDO $pdo, int $id): void {
    db_delete_season_link($pdo, $id);
    setFlash('Link removed.', 'success');
    adminRedirect('admin.php?action=season-links');
}

function handleSeasonLinksList(PDO $pdo): void {
    $links = db_get_season_links($pdo);
    $edit = adminEditTarget($_GET);
    $place = adminFlashPlacement(getFlash(), $edit, array_merge(['new'], array_column($links, 'id')));
    adminRenderPage('Season links', 'season-links', renderSeasonLinksPageHtml($links, generateCsrfToken(), $place['dialog'], $edit), $place['top']);
}

/** The label, web address, order (and, on an edit, Shown) fields of one link's dialog. Pure. */
function adminSeasonLinkFieldsHtml(string $p, array $l, bool $isEdit, int $defaultOrder): string {
    return adminField($p . '-label', 'Label', '<input type="text" id="' . h($p) . '-label" name="label" maxlength="120" required value="'
            . h((string)($l['label'] ?? '')) . '" placeholder="2026 Annual Waiver / Hardcard">', true)
        . adminField($p . '-url', 'Web address', '<input type="url" id="' . h($p) . '-url" name="url" required value="'
            . h((string)($l['url'] ?? '')) . '" placeholder="https://www.motorsportreg.com/events/…">', true)
        . adminField($p . '-sort', 'Order (lower shows first)', '<input type="number" id="' . h($p) . '-sort" name="sort_order" value="'
            . (int)($l['sort_order'] ?? $defaultOrder) . '">')
        . ($isEdit ? '<label class="admin-form-wide"><input type="checkbox" name="active" value="1"' . ((int)($l['active'] ?? 1) === 1 ? ' checked' : '')
            . '> Shown to competitors</label>' : '');
}

/**
 * The page body: an Add button, a read-only list, then the add dialog and one Edit dialog per link,
 * like the other admin tabs (UX review 2026-09-30 §L5). Pure.
 */
function renderSeasonLinksPageHtml(array $links, string $csrf, ?array $dialogFlash = null, ?string $edit = null): string {
    $csrfField = adminCsrfField($csrf);
    $out = '<p class="admin-intro">These links are shown to competitors as "This season on MotorsportReg". MotorsportReg gives each '
        . 'season\'s waiver and licences new web addresses, so update them at the start of every season.</p>'
        . '<div class="admin-toolbar">' . adminAddButton('link-dialog-new', 'Add link') . '</div>'
        . '<table class="data-table admin-table" id="season-links-table"><thead><tr><th class="admin-col-order">Order</th><th>Label</th><th>Web address</th>'
        . '<th>Shown</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
    $dialogs = adminDialogHtml('link-dialog-new', 'Add a link',
        '<form method="post" action="admin.php?action=season-link-save" class="admin-form">' . $csrfField
        . '<input type="hidden" name="id" value="0">'
        . adminSeasonLinkFieldsHtml('link-new', [], false, count($links) + 1) . adminDialogActions('Add link') . '</form>',
        $edit === 'new' ? $dialogFlash : null);
    if (!$links) {
        $out .= '<tr><td colspan="5" class="empty-row">No links yet. Add one with the button above.</td></tr>';
    }
    foreach ($links as $l) {
        $id = (int)$l['id'];
        $hidden = $csrfField . '<input type="hidden" name="id" value="' . $id . '">';
        $out .= '<tr id="link-' . $id . '">'
            . '<td class="admin-col-order" data-label="Order">' . (int)$l['sort_order'] . '</td>'
            . '<td data-label="Label">' . h((string)$l['label']) . '</td>'
            . '<td data-label="Web address"><a class="admin-link" href="' . h((string)$l['url']) . '" target="_blank" rel="noopener">Open ↗</a>'
            . '<span class="admin-sub">' . h((string)parse_url((string)$l['url'], PHP_URL_HOST)) . '</span></td>'
            . '<td data-label="Shown">' . ((int)$l['active'] === 1 ? adminChip('Shown', 'ok') : adminChip('Hidden', 'info')) . '</td>'
            . '<td class="admin-cell-actions">' . adminEditButton('link-dialog-' . $id, (string)$l['label']) . '</td></tr>';
        $dialogs .= adminDialogHtml('link-dialog-' . $id, 'Edit link',
            '<form method="post" action="admin.php?action=season-link-save" class="admin-form">' . $hidden
            . adminSeasonLinkFieldsHtml('link-' . $id, $l, true, 0) . adminDialogActions('Save') . '</form>'
            . adminDangerHtml('Remove this link', 'Competitors stop seeing it. To hide it for now, untick Shown instead.',
                '<form method="post" action="admin.php?action=season-link-delete" data-confirm="Remove “' . h((string)$l['label']) . '”? Competitors will stop seeing it.">'
                . $hidden . '<button type="submit" class="btn btn-secondary">Remove</button></form>'),
            $edit === (string)$id ? $dialogFlash : null, (string)$l['label']);
    }
    return $out . '</tbody></table><div class="admin-dialogs">' . $dialogs . '</div>';
}
