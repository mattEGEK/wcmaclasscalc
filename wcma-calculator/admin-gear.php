<?php
// wcma-calculator/admin-gear.php
//
// Admin "Gear" tab: driver gear records for a season, and the review page (accept in person,
// accept photos remotely, send photos back). Included by admin.php, which provides requireAuth(),
// the router and the CSRF/POST checks.

const GEAR_ADMIN_FILTERS = [
    'all' => 'All drivers',
    'needs_gear' => 'Needs gear check at the track',
    'pending_review' => 'Photos awaiting review',
    'accepted' => 'Accepted',
];

function handleGearAdminList(PDO $pdo): void {
    $season = isset($_GET['season']) ? (int)$_GET['season'] : gearSeasonNow();
    if ($season < 2000 || $season > 2100) $season = gearSeasonNow();
    $filter = (string)($_GET['filter'] ?? 'all');
    if (!isset(GEAR_ADMIN_FILTERS[$filter])) $filter = 'all';

    $records = db_get_gear_records_for_season($pdo, $season);
    $counts = [
        'all' => count($records),
        'accepted' => count(gearRosterFilter($records, 'accepted')),
        'needs_gear' => count(gearRosterFilter($records, 'needs_gear')),
        'pending_review' => count(gearRosterFilter($records, 'pending_review')),
    ];
    renderGearAdminListPage(gearRosterFilter($records, $filter), $season, $filter, $counts, getFlash());
}

function renderGearAdminListPage(array $records, int $season, string $filter, array $counts, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gear — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Gear', renderAdminNav('gear') . renderCommonNav('admin')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <form method="get" action="admin.php" class="detail-card" style="margin-bottom:1rem">
    <input type="hidden" name="action" value="gear">
    <label for="gear-season">Season</label>
    <input type="number" id="gear-season" name="season" value="<?= (int)$season ?>" min="2000" max="2100">
    <label for="gear-filter">Show</label>
    <select id="gear-filter" name="filter">
      <?php foreach (GEAR_ADMIN_FILTERS as $value => $label): ?>
      <option value="<?= h($value) ?>"<?= $value === $filter ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Apply</button>
    <p class="form-hint" style="margin-top:.5rem"><?= (int)$counts['all'] ?> drivers: <?= (int)$counts['accepted'] ?> accepted, <?= (int)$counts['pending_review'] ?> with photos awaiting review, <?= (int)$counts['needs_gear'] ?> still need a gear check at the track.</p>
  </form>

  <table class="data-table" id="gear-admin-table">
    <thead><tr><th>Driver</th><th>Licence</th><th>Entered by</th><th>Gear status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($records)): ?>
      <tr><td colspan="5" class="empty-row">No gear records match.</td></tr>
    <?php else: foreach ($records as $g): $st = gearStatus($g); ?>
      <tr>
        <td><?= h($g['driver_name']) ?></td>
        <td><?= h((string)($g['licence_no'] ?? '')) ?></td>
        <td><?= h((string)($g['owner_name'] ?? '')) ?></td>
        <td class="<?= h(gearStatusBadgeClass($st['state'])) ?>"><?= h(gearStatusLabel($st, (int)$g['season'])) ?></td>
        <td class="actions"><a href="admin.php?action=gear-record&amp;id=<?= (int)$g['id'] ?>"><?= $st['state'] === 'accepted' ? 'View' : 'Review' ?></a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
</body>
</html><?php
}

function handleGearAdminView(PDO $pdo, int $id): void {
    $gear = db_get_gear_record($pdo, $id);
    if ($gear === null) {
        setFlash('Gear record not found.', 'error');
        header('Location: admin.php?action=gear');
        exit;
    }
    $owner = db_find_user_by_id($pdo, (int)$gear['owner_user_id']);
    $reviewer = !empty($gear['reviewed_by_user_id']) ? db_find_user_by_id($pdo, (int)$gear['reviewed_by_user_id']) : null;
    renderGearAdminViewPage($gear, gearSnapshot($pdo, $id), $owner, $reviewer, generateCsrfToken(), getFlash());
}

function gearAdminBaseUrl(): string {
    return feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', ''));
}

function handleGearAdminAcceptInPerson(PDO $pdo, int $id): void {
    $user = current_user();
    $r = gearAcceptInPerson($pdo, $id, (int)$user['id']);
    setFlash($r['ok'] ? 'Gear accepted (teched in person).' : $r['error'], $r['ok'] ? 'success' : 'error');
    header('Location: admin.php?action=gear-record&id=' . $id);
    exit;
}

function handleGearAdminRevoke(PDO $pdo, int $id): void {
    $r = gearRevoke($pdo, $id);
    setFlash($r['ok'] ? 'Acceptance revoked. The gear record is open again.' : $r['error'], $r['ok'] ? 'success' : 'error');
    header('Location: admin.php?action=gear-record&id=' . $id);
    exit;
}

function handleGearAdminPhotosAccept(PDO $pdo, int $id): void {
    $user = current_user();
    $r = gearAcceptByPhotos($pdo, $id, (int)$user['id']);
    if (!$r['ok']) {
        setFlash($r['error'], 'error');
    } else {
        $sent = gearNotify($pdo, 'accepted', db_get_gear_record($pdo, $id), gearAdminBaseUrl(), ['email' => TECH_EMAIL, 'name' => TECH_NAME], 'emailSmtpSend');
        setFlash('Photos accepted: the gear is pre-teched.' . ($sent ? ' The driver\'s account holder and the club were emailed.' : ' The notification email could not be sent.'), $sent ? 'success' : 'error');
    }
    header('Location: admin.php?action=gear-record&id=' . $id);
    exit;
}

function handleGearAdminPhotosSendBack(PDO $pdo, int $id): void {
    $flagged = isset($_POST['retake']) && is_array($_POST['retake']) ? array_keys($_POST['retake']) : [];
    $noteInput = isset($_POST['note']) && is_array($_POST['note']) ? $_POST['note'] : [];
    $notes = [];
    foreach ($flagged as $key) {
        $notes[(string)$key] = is_string($noteInput[$key] ?? null) ? $noteInput[$key] : '';
    }

    $r = gearSendBack($pdo, $id, $notes);
    if (!$r['ok']) {
        setFlash($r['error'], 'error');
    } else {
        $sent = gearNotify($pdo, 'sent_back', db_get_gear_record($pdo, $id), gearAdminBaseUrl(), ['email' => TECH_EMAIL, 'name' => TECH_NAME], 'emailSmtpSend', $r['retakes']);
        $n = count($r['retakes']);
        setFlash($n . ' ' . ($n === 1 ? 'photo' : 'photos') . ' sent back for a retake.' . ($sent ? ' The account holder was emailed.' : ' The notification email could not be sent.'), $sent ? 'success' : 'error');
    }
    header('Location: admin.php?action=gear-record&id=' . $id);
    exit;
}

function renderGearAdminViewPage(array $gear, array $snapshot, ?array $owner, ?array $reviewer, string $csrf, ?array $flash): void {
    $id = (int)$gear['id'];
    $st = gearStatus($gear);
    $accepted = $gear['status'] === 'accepted';
    $acceptedLine = '';
    if ($accepted) {
        $how = ($gear['accepted_via'] ?? 'in_person') === 'photos' ? 'remotely' : 'in person';
        $who = $reviewer ? ' by ' . $reviewer['name'] : '';
        $when = !empty($gear['reviewed_at']) ? ' on ' . date('M j, Y g:i A', strtotime($gear['reviewed_at'])) : '';
        $acceptedLine = 'Accepted ' . $how . $who . $when . '.';
    }
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gear #<?= $id ?> — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Gear #' . $id, '<a href="admin.php?action=gear&amp;season=' . (int)$gear['season'] . '">← Back to gear list</a>' . renderCommonNav('admin')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <div class="detail-card">
    <h2>Gear review</h2>
    <p><?= h($gear['driver_name']) ?> — <?= (int)$gear['season'] ?><?= !empty($gear['licence_no']) ? ' (licence ' . h($gear['licence_no']) . ')' : '' ?></p>
    <?php if ($owner): ?><p>Entered by <?= h($owner['name']) ?> (<?= h($owner['email']) ?>)</p><?php endif; ?>
    <p>Gear status: <strong class="<?= h(gearStatusBadgeClass($st['state'])) ?>"><?= h(gearStatusLabel($st, (int)$gear['season'])) ?></strong></p>

    <?php if ($accepted): ?>
    <p><?= h($acceptedLine) ?></p>
    <form method="post" action="admin.php?action=gear-record-revoke" data-confirm="Revoke this acceptance? The gear record goes back to open<?= ($gear['accepted_via'] ?? '') === 'photos' ? ' and its photos return to the review queue' : '' ?>.">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="btn btn-secondary">Revoke acceptance</button>
    </form>
    <?php else: ?>
    <p class="form-hint">Accepting in person records that the gear you are looking at matches what the driver declared. To review photos instead, use the photo review below.</p>
    <form method="post" action="admin.php?action=gear-record-accept">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="btn btn-primary" id="gear-inperson-btn">Accept — gear teched in person</button>
    </form>
    <?php endif; ?>
  </div>

  <?php renderGearReviewCard($gear, $snapshot, $csrf); ?>
</div>
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
</body>
</html><?php
}

/** The gear photos, with accept / send-back controls while they are awaiting review. */
function renderGearReviewCard(array $gear, array $snapshot, string $csrf): void {
    $photos = array_filter($snapshot['photos'], fn(array $p): bool => $p['file_path'] !== '');
    $photoStatus = $gear['photo_status'] ?? null;
    if ($photoStatus === null && !$photos) return;

    $id = (int)$gear['id'];
    $awaiting = $photoStatus === 'submitted' && $gear['status'] === 'open';
    $statusLabels = [
        'draft' => 'The driver\'s account holder has started adding photos (not submitted yet).',
        'submitted' => 'Submitted: awaiting review.',
        'needs_changes' => 'Sent back: waiting for photos to be retaken.',
        'accepted' => 'Photos reviewed and accepted.',
    ];
    ?>
  <div class="detail-card" id="gear-review">
    <h2>Gear photos</h2>
    <p><?= h($statusLabels[$photoStatus] ?? 'No photo set yet.') ?> <?= count($photos) ?> <?= count($photos) === 1 ? 'photo' : 'photos' ?> on file.</p>
    <?php if ($awaiting): ?>
    <p class="form-hint">Accepting these photos makes the gear pre-teched for the season. To send photos back, tick each one, say what is wrong, and use "Send back for retakes".</p>
    <?php endif; ?>

    <form method="post" action="admin.php?action=gear-photos-send-back" id="gear-review-form">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <?php foreach ($photos as $key => $row):
          $req = photoRequirementByKey($key);
          $public = inspectionPublicPhoto($row);
      ?>
      <div class="pretech-card" data-key="<?= h($key) ?>">
        <h3><?= h($req['label'] ?? $key) ?>
          <span class="pretech-status <?= $row['review_status'] === 'retake' ? 'badge-fail' : ($row['review_status'] === 'accepted' ? 'badge-ok' : 'badge-pending') ?>">
            <?= h($row['review_status'] === 'retake' ? 'Retake requested' : ($row['review_status'] === 'accepted' ? 'Accepted' : 'Pending')) ?></span></h3>
        <a href="<?= h($public['url']) ?>" target="_blank" rel="noopener"><img class="pretech-thumb" src="<?= h($public['url']) ?>" alt="<?= h($req['label'] ?? $key) ?>"></a>
        <?php foreach ($public['typed'] as $name => $value): ?>
          <p class="form-hint"><?= h(ucfirst((string)$name)) ?>: <strong><?= h((string)$value) ?></strong></p>
        <?php endforeach; ?>
        <?php if ($row['review_status'] === 'retake' && !empty($row['reviewer_note'])): ?>
          <p class="badge-fail">Note sent: <?= h((string)$row['reviewer_note']) ?></p>
        <?php endif; ?>
        <?php if ($awaiting): ?>
          <label><input type="checkbox" name="retake[<?= h($key) ?>]" value="1"> Needs a retake</label>
          <input type="text" name="note[<?= h($key) ?>]" maxlength="500" placeholder="What is wrong with this photo?">
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <?php if ($awaiting): ?>
      <button type="submit" class="btn btn-secondary" id="gear-sendback-btn">Send back for retakes</button>
      <?php endif; ?>
    </form>

    <?php if ($awaiting): ?>
    <form method="post" action="admin.php?action=gear-photos-accept" style="margin-top:.75rem">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="btn btn-primary" id="gear-accept-btn">Accept photos (pre-teched)</button>
    </form>
    <?php endif; ?>
  </div>
<?php
}
