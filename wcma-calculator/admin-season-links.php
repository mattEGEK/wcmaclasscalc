<?php
// wcma-calculator/admin-season-links.php
//
// Admin: this season's links for competitors (MotorsportReg waiver, licences, number reservation).
// Loaded by admin.php, which has already checked the admin role and CSRF.

function handleSeasonLinkSave(PDO $pdo, int $id): void {
    $v = seasonLinkValidate((string)($_POST['label'] ?? ''), (string)($_POST['url'] ?? ''), (string)($_POST['sort_order'] ?? '0'));
    if (!$v['ok']) {
        setFlash($v['error'], 'error');
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
    adminRenderPage('Season links', 'season-links', renderSeasonLinksPageHtml(db_get_season_links($pdo), generateCsrfToken()), getFlash());
}

/** The page body: links edited in the table (updated together each season), then an Add form. Pure. */
function renderSeasonLinksPageHtml(array $links, string $csrf): string {
    $csrfField = adminCsrfField($csrf);
    $out = '<p class="admin-intro">These links are shown to competitors as "This season on MotorsportReg". MotorsportReg gives each '
        . 'season\'s waiver and licences new web addresses, so update them at the start of every season.</p>'
        . '<table class="data-table admin-table"><thead><tr><th class="admin-col-order">Order</th><th>Label</th><th>Web address</th>'
        . '<th>Shown</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
    if (!$links) {
        $out .= '<tr><td colspan="5" class="empty-row">No links yet. Add one below.</td></tr>';
    }
    foreach ($links as $l) {
        $id = (int)$l['id'];
        $fid = 'link-' . $id;
        $hidden = $csrfField . '<input type="hidden" name="id" value="' . $id . '">';
        $out .= '<tr>'
            . '<td class="admin-col-order" data-label="Order"><input form="' . $fid . '" type="number" name="sort_order" value="' . (int)$l['sort_order'] . '" aria-label="Order"></td>'
            . '<td data-label="Label"><input form="' . $fid . '" type="text" name="label" value="' . h((string)$l['label']) . '" maxlength="120" required aria-label="Label"></td>'
            . '<td data-label="Web address"><input form="' . $fid . '" type="url" name="url" value="' . h((string)$l['url']) . '" required aria-label="Web address"></td>'
            . '<td data-label="Shown"><input form="' . $fid . '" type="checkbox" name="active" value="1"' . ((int)$l['active'] === 1 ? ' checked' : '') . ' aria-label="Shown"></td>'
            . '<td class="admin-cell-actions"><div class="admin-row-actions">'
            . '<form id="' . $fid . '" method="post" action="admin.php?action=season-link-save">' . $hidden
            . '<button type="submit" class="btn btn-secondary">Save</button></form>'
            . '<form method="post" action="admin.php?action=season-link-delete" data-confirm="Remove “' . h((string)$l['label']) . '”? Competitors will stop seeing it.">'
            . $hidden . '<button type="submit" class="link-button">Remove</button></form>'
            . '</div></td></tr>';
    }
    $out .= '</tbody></table>'
        . '<section class="detail-card"><h2>Add a link</h2>'
        . '<form method="post" action="admin.php?action=season-link-save" class="admin-form">' . $csrfField
        . '<input type="hidden" name="id" value="0">'
        . adminField('new-label', 'Label', '<input type="text" id="new-label" name="label" maxlength="120" required placeholder="2026 Annual Waiver / Hardcard">')
        . adminField('new-url', 'Web address', '<input type="url" id="new-url" name="url" required placeholder="https://www.motorsportreg.com/events/…">')
        . adminField('new-sort', 'Order (lower shows first)', '<input type="number" id="new-sort" name="sort_order" value="' . count($links) . '">')
        . '<div class="admin-form-actions admin-form-wide"><button type="submit" class="btn btn-primary">Add link</button></div>'
        . '</form></section>';
    return $out;
}
