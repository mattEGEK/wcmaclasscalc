<?php
// wcma-calculator/admin-clubs.php
//
// Admin: host clubs (clubs spec 2026-09-29 §1). Each club has a name and a MotorsportReg link that
// competitors see after submitting a tech sheet. Loaded by admin.php, which has already checked
// the admin role; POST handlers are reached through adminRequirePost() (CSRF).

function handleClubsList(PDO $pdo): void {
    renderClubsPage(db_get_clubs($pdo), generateCsrfToken(), getFlash());
}

function handleClubSave(PDO $pdo): void {
    $isNew = ($_POST['is_new'] ?? '') === '1';
    $v = clubValidate((string)($_POST['code'] ?? ''), (string)($_POST['name'] ?? ''), (string)($_POST['msr_url'] ?? ''));
    if (!$v['ok']) {
        setFlash((string)$v['error'], 'error');
    } elseif ($isNew && db_get_club($pdo, $v['code']) !== null) {
        setFlash('A club with the code ' . $v['code'] . ' already exists.', 'error');
    } elseif ($isNew) {
        db_create_club($pdo, $v['code'], $v['name'], $v['url']);
        setFlash('Club added.', 'success');
    } elseif (db_get_club($pdo, $v['code']) === null) {
        setFlash('Club not found.', 'error');
    } else {
        db_update_club($pdo, $v['code'], $v['name'], $v['url'], !empty($_POST['active']));
        setFlash('Club saved.', 'success');
    }
    header('Location: admin.php?action=clubs');
    exit;
}

/** The page body: one editable card per club, then an Add form. Pure. */
function renderClubsPageHtml(array $clubs, string $csrf): string {
    $csrfField = '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">';
    $out = '<div class="detail-card"><p>Clubs host events. A club\'s MotorsportReg link is shown to competitors after they '
        . 'submit a tech sheet, so they can register for the event. NASCC and WSCC run the ice races.</p></div>';
    foreach ($clubs as $c) {
        $code = (string)$c['code'];
        $id = 'club-' . strtolower($code);
        $out .= '<form method="post" action="admin.php?action=club-save" class="detail-card">' . $csrfField
            . '<input type="hidden" name="code" value="' . h($code) . '">'
            . '<h2>' . h($code) . '</h2>'
            . '<label for="' . h($id) . '-name">Name</label>'
            . '<input type="text" id="' . h($id) . '-name" name="name" maxlength="120" required value="' . h((string)$c['name']) . '">'
            . '<label for="' . h($id) . '-url">MotorsportReg link (optional)</label>'
            . '<input type="url" id="' . h($id) . '-url" name="msr_url" placeholder="https://" value="' . h((string)$c['msr_url']) . '">'
            . '<label class="checkbox-label"><input type="checkbox" name="active" value="1"' . ((int)$c['active'] === 1 ? ' checked' : '') . '> Shown</label>'
            . '<div class="form-actions"><button type="submit" class="btn btn-primary">Save</button></div></form>';
    }
    $out .= '<form method="post" action="admin.php?action=club-save" class="detail-card">' . $csrfField
        . '<input type="hidden" name="is_new" value="1"><h2>Add a club</h2>'
        . '<label for="club-new-code">Short code (letters, numbers or dashes)</label>'
        . '<input type="text" id="club-new-code" name="code" maxlength="12" required placeholder="ESCC">'
        . '<label for="club-new-name">Name</label><input type="text" id="club-new-name" name="name" maxlength="120" required>'
        . '<label for="club-new-url">MotorsportReg link (optional)</label>'
        . '<input type="url" id="club-new-url" name="msr_url" placeholder="https://">'
        . '<div class="form-actions"><button type="submit" class="btn btn-primary">Add club</button></div></form>';
    return $out;
}

function renderClubsPage(array $clubs, string $csrf, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Clubs — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Archivo+Narrow:wght@600;700&display=swap">
<link rel="stylesheet" href="<?= hubAsset('css/calculator.css') ?>">
<link rel="stylesheet" href="<?= hubAsset('css/hub.css') ?>">
</head>
<body class="hub">
<div class="container">
  <?php renderSiteHeader('Clubs', adminSubnavHtml('clubs'), 'admin'); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <?= renderClubsPageHtml($clubs, $csrf) ?>
</div>
<script src="js/form-feedback.js"></script>
<?php renderSiteFooter(); ?>
</body>
</html><?php
}
