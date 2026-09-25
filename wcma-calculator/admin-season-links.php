<?php
// wcma-calculator/admin-season-links.php
//
// Admin: this season's links for competitors (MotorsportReg waiver, licences, number reservation).
// Loaded by admin.php, which has already checked the admin role and CSRF.

function handleSeasonLinksList(PDO $pdo): void {
    renderSeasonLinksPage(db_get_season_links($pdo), generateCsrfToken(), getFlash());
}

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
    header('Location: admin.php?action=season-links');
    exit;
}

function handleSeasonLinkDelete(PDO $pdo, int $id): void {
    db_delete_season_link($pdo, $id);
    setFlash('Link removed.', 'success');
    header('Location: admin.php?action=season-links');
    exit;
}

function renderSeasonLinksPage(array $links, string $csrf, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Season Links — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Archivo+Narrow:wght@600;700&display=swap">
<link rel="stylesheet" href="css/calculator.css">
<link rel="stylesheet" href="css/hub.css">
</head>
<body class="hub">
<div class="container">
  <?php renderSiteHeader('Season Links', renderAdminNav('season-links', (string)(current_user()['role'] ?? 'user')), 'staff'); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <div class="detail-card">
    <p>These links are shown to competitors as "This season on MotorsportReg". MotorsportReg gives each season's waiver and licences new web addresses, so update them at the start of every season.</p>
  </div>

  <table class="data-table">
    <thead><tr><th>Order</th><th>Label</th><th>Web address</th><th>Shown</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (!$links): ?>
      <tr><td colspan="5" class="empty-row">No links yet. Add one below.</td></tr>
    <?php endif; ?>
    <?php foreach ($links as $l): $fid = 'link-' . (int)$l['id']; ?>
      <tr>
        <td><input form="<?= $fid ?>" type="number" name="sort_order" value="<?= (int)$l['sort_order'] ?>" style="width:5rem" aria-label="Order"></td>
        <td><input form="<?= $fid ?>" type="text" name="label" value="<?= h($l['label']) ?>" maxlength="120" required aria-label="Label"></td>
        <td><input form="<?= $fid ?>" type="url" name="url" value="<?= h($l['url']) ?>" required aria-label="Web address"></td>
        <td><input form="<?= $fid ?>" type="checkbox" name="active" value="1"<?= (int)$l['active'] === 1 ? ' checked' : '' ?> aria-label="Shown"></td>
        <td class="actions">
          <form id="<?= $fid ?>" method="post" action="admin.php?action=season-link-save" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button type="submit" class="link-button">Save</button>
          </form>
          <form method="post" action="admin.php?action=season-link-delete" style="display:inline" data-confirm="Remove this link?">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button type="submit" class="link-button">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <form method="post" action="admin.php?action=season-link-save" class="detail-card" style="margin-top:1.5rem">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="0">
    <h3>Add a link</h3>
    <label for="new-label">Label</label>
    <input type="text" id="new-label" name="label" maxlength="120" required placeholder="2026 Annual Waiver / Hardcard">
    <label for="new-url">Web address</label>
    <input type="url" id="new-url" name="url" required placeholder="https://www.motorsportreg.com/events/…">
    <label for="new-sort">Order (lower shows first)</label>
    <input type="number" id="new-sort" name="sort_order" value="<?= count($links) ?>">
    <button type="submit" class="btn btn-primary" style="margin-top:.75rem">Add link</button>
  </form>
</div>
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
</body>
</html><?php
}
