<?php
// wcma-calculator/admin-tech-sheets.php
//
// Admin "Tech Sheets" tab: event roster (who still needs tech at the track) and the
// in-person review page. Included by admin.php, which provides requireAuth(), the router
// and the CSRF/POST checks.

const TECH_SHEET_FILTERS = [
    'all' => 'All sheets',
    'needs_tech' => 'Needs tech at the track',
    'pending_review' => 'Photos awaiting review',
    'accepted' => 'Accepted',
];

const ADMIN_TECH_NAV = '<a href="admin.php">Submissions</a> <a href="admin.php?action=users">Manage Users</a> <a href="admin.php?action=events">Events</a> <a href="admin.php?action=settings">Settings</a> <a href="admin.php?action=feedback">Feedback</a>';

function handleTechSheetsList(PDO $pdo): void {
    $events = db_get_all_events($pdo);
    $eventId = isset($_GET['event']) ? max(0, (int)$_GET['event']) : techDefaultEventId($events, date('Y-m-d'));
    $filter = (string)($_GET['filter'] ?? 'all');
    if (!isset(TECH_SHEET_FILTERS[$filter])) $filter = 'all';

    $eventSheets = db_get_event_tech_sheets($pdo, $eventId);
    $seasonSheets = [];
    foreach (array_unique(array_map(fn(array $s): int => (int)$s['season'], $eventSheets)) as $season) {
        $seasonSheets = array_merge($seasonSheets, db_get_season_sheets($pdo, $season));
    }
    $rows = techBuildRoster($eventSheets, $seasonSheets);

    $counts = [
        'all' => count($rows),
        'needs_tech' => count(techRosterFilter($rows, 'needs_tech')),
        'accepted' => count(techRosterFilter($rows, 'accepted')),
    ];
    renderTechSheetsListPage($events, $eventId, $filter, techRosterFilter($rows, $filter), $counts, getFlash());
}

function renderTechSheetsListPage(array $events, int $eventId, string $filter, array $rows, array $counts, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tech Sheets — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Tech Sheets', ADMIN_TECH_NAV . renderCommonNav('admin')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <form method="get" action="admin.php" class="detail-card" style="margin-bottom:1rem">
    <input type="hidden" name="action" value="tech-sheets">
    <label for="tech-event">Event</label>
    <select id="tech-event" name="event">
      <option value="0"<?= $eventId === 0 ? ' selected' : '' ?>>All events</option>
      <?php foreach ($events as $e): ?>
      <option value="<?= (int)$e['id'] ?>"<?= (int)$e['id'] === $eventId ? ' selected' : '' ?>><?= h($e['name']) ?> (<?= h(date('M j, Y', strtotime($e['event_date']))) ?>)</option>
      <?php endforeach; ?>
    </select>
    <label for="tech-filter">Show</label>
    <select id="tech-filter" name="filter">
      <?php foreach (TECH_SHEET_FILTERS as $value => $label): ?>
      <option value="<?= h($value) ?>"<?= $value === $filter ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Apply</button>
    <p class="form-hint" style="margin-top:.5rem"><?= (int)$counts['all'] ?> sheets: <?= (int)$counts['accepted'] ?> accepted, <?= (int)$counts['needs_tech'] ?> still need tech at the track.</p>
  </form>

  <table class="data-table" id="tech-sheets-table">
    <thead><tr><th>Car #</th><th>Vehicle</th><th>Entrant</th><th>Class</th><th>Event</th><th>Car status</th><th>Sheet</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="8" class="empty-row">No tech sheets match.</td></tr>
    <?php else: foreach ($rows as $row): $s = $row['sheet']; $st = $row['status']; ?>
      <tr>
        <td><?= h($s['car_number']) ?></td>
        <td><?= h(trim($s['car_make'] . ' ' . $s['car_model'])) ?></td>
        <td><?= h($s['entrant_name']) ?></td>
        <td><?= h($s['class']) ?></td>
        <td><?= h($s['event_name'] ?? '—') ?></td>
        <td class="<?= h(techCarStatusBadgeClass($st['state'])) ?>"><?= h(techCarStatusLabel($st, (int)$s['season'])) ?></td>
        <td><?= $s['status'] === 'teched' ? 'Reviewed' : 'Submitted ' . h(date('M j', strtotime($s['created_at']))) ?></td>
        <td class="actions"><a href="admin.php?action=tech-sheet&id=<?= (int)$s['id'] ?>"><?= $s['status'] === 'teched' ? 'View' : 'Review' ?></a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
</body>
</html><?php
}

function handleTechSheetView(PDO $pdo, int $id): void {
    $sheet = db_get_tech_sheet($pdo, $id);
    if ($sheet === null) {
        setFlash('Tech sheet not found.', 'error');
        header('Location: admin.php?action=tech-sheets');
        exit;
    }
    $event = db_get_event($pdo, (int)$sheet['event_id']) ?? [];
    $drivers = db_get_tech_sheet_drivers($pdo, $id);
    $carStatus = techCarStatus(db_get_identity_sheets($pdo, (int)$sheet['user_id'], (string)$sheet['car_number_norm'], (int)$sheet['season']));
    $reviewer = !empty($sheet['reviewed_by_user_id']) ? db_find_user_by_id($pdo, (int)$sheet['reviewed_by_user_id']) : null;
    renderTechSheetViewPage($sheet, $drivers, $event, $carStatus, $reviewer, generateCsrfToken(), getFlash(), pretechSnapshot($pdo, $id));
}

function handleTechSheetAccept(PDO $pdo, int $id): void {
    $user = current_user();
    $result = techReviewAcceptInPerson($pdo, __DIR__, $id, (int)$user['id'], (string)($_POST['tech_signature'] ?? ''));
    setFlash($result['ok'] ? 'Sheet accepted (teched in person).' : $result['error'], $result['ok'] ? 'success' : 'error');
    header('Location: admin.php?action=tech-sheet&id=' . $id);
    exit;
}

function handleTechSheetRevoke(PDO $pdo, int $id): void {
    $result = techReviewRevoke($pdo, __DIR__, $id);
    setFlash($result['ok'] ? 'Acceptance revoked. The sheet is back to submitted.' : $result['error'], $result['ok'] ? 'success' : 'error');
    header('Location: admin.php?action=tech-sheet&id=' . $id);
    exit;
}

/** Serves a signature PNG to an admin (uploads/ is Deny-from-all, so it cannot be linked directly). */
function handleTechSheetSig(PDO $pdo, int $id, string $which): void {
    $columns = ['entrant' => 'entrant_signature_path', 'driver' => 'driver_signature_path', 'tech' => 'tech_signature_path'];
    $sheet = isset($columns[$which]) ? db_get_tech_sheet($pdo, $id) : null;
    $path = $sheet[$columns[$which] ?? ''] ?? null;
    $full = $path ? __DIR__ . '/' . $path : null;
    if ($full === null || !is_file($full)) { http_response_code(404); exit; }

    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($full));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($full);
    exit;
}

function adminTechSheetSigResolver(int $techSheetId): callable {
    return function (string $which, string $path) use ($techSheetId): ?string {
        return 'admin.php?action=tech-sheet-sig&id=' . $techSheetId . '&which=' . rawurlencode($which);
    };
}

function renderTechSheetViewPage(array $sheet, array $drivers, array $event, array $carStatus, ?array $reviewer, string $csrf, ?array $flash, array $snapshot): void {
    $id = (int)$sheet['id'];
    $accepted = $sheet['status'] === 'teched';
    $statusLabel = techCarStatusLabel($carStatus, (int)$sheet['season']);
    $acceptedLine = '';
    if ($accepted) {
        $how = ($sheet['accepted_via'] ?? 'in_person') === 'photos' ? 'remotely' : 'in person';
        $who = $reviewer ? ' by ' . $reviewer['name'] : '';
        $when = !empty($sheet['reviewed_at']) ? ' on ' . date('M j, Y g:i A', strtotime($sheet['reviewed_at'])) : '';
        $acceptedLine = 'Accepted ' . $how . $who . $when . '.';
    }
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tech Sheet #<?= $id ?> — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Tech Sheet #' . $id, '<a href="admin.php?action=tech-sheets&amp;event=' . (int)$sheet['event_id'] . '">← Back to roster</a>' . renderCommonNav('admin')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <div class="detail-card">
    <h2>Tech review</h2>
    <p>Car #<?= h($sheet['car_number']) ?> — <?= h(trim($sheet['car_make'] . ' ' . $sheet['car_model'])) ?> (<?= h($sheet['entrant_name']) ?>)</p>
    <p>Car status: <strong class="<?= h(techCarStatusBadgeClass($carStatus['state'])) ?>"><?= h($statusLabel) ?></strong></p>

    <?php if ($accepted): ?>
    <p><?= h($acceptedLine) ?></p>
    <form method="post" action="admin.php?action=tech-sheet-revoke" data-confirm="Revoke this acceptance? The sheet goes back to submitted and the inspector signature is removed.">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="btn btn-secondary">Revoke acceptance</button>
    </form>
    <?php else: ?>
    <p class="form-hint">Accepting records that what the competitor submitted matches the car in front of you. <?= h(TECH_ACCEPTANCE_DISCLAIMER) ?></p>
    <form method="post" action="admin.php?action=tech-sheet-accept" id="tech-accept-form">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="tech_signature" id="tech_signature">
      <p id="tech-accept-error" class="form-messages" role="alert" hidden></p>
      <label>Tech representative signature</label>
      <div class="sig-pad-wrap"><canvas id="tech-sig-canvas"></canvas></div>
      <div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="tech">Clear</button></div>
      <button type="submit" class="btn btn-primary" style="margin-top:.75rem">Accept — teched in person</button>
    </form>
    <?php endif; ?>
  </div>

  <?php renderPretechReviewCard($sheet, $snapshot, $csrf); ?>

  <?= renderTechSheetHtml($sheet, $drivers, $event, adminTechSheetSigResolver($id), 'assets/wcma-logo.png') ?>
</div>
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
<script src="js/signature-pad.js"></script>
<script src="js/admin-tech-review.js"></script>
</body>
</html><?php
}

function handleTechSheetPhotosAccept(PDO $pdo, int $id): void {
    $user = current_user();
    $result = pretechAccept($pdo, $id, (int)$user['id']);
    if (!$result['ok']) {
        setFlash($result['error'], 'error');
    } else {
        $sheet = db_get_tech_sheet($pdo, $id);
        $sent = pretechNotify(
            $pdo, 'accepted', $sheet, db_get_event($pdo, (int)$sheet['event_id']) ?? [],
            feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', '')),
            ['email' => TECH_EMAIL, 'name' => TECH_NAME], 'emailSmtpSend'
        );
        setFlash('Photos accepted: the car is pre-teched.' . ($sent ? ' The competitor and the club were emailed.' : ' The notification email could not be sent.'), $sent ? 'success' : 'error');
    }
    header('Location: admin.php?action=tech-sheet&id=' . $id);
    exit;
}

function handleTechSheetPhotosSendBack(PDO $pdo, int $id): void {
    $flagged = isset($_POST['retake']) && is_array($_POST['retake']) ? array_keys($_POST['retake']) : [];
    $noteInput = isset($_POST['note']) && is_array($_POST['note']) ? $_POST['note'] : [];
    $notes = [];
    foreach ($flagged as $key) {
        $notes[(string)$key] = (string)($noteInput[$key] ?? '');
    }

    $result = pretechSendBack($pdo, $id, $notes);
    if (!$result['ok']) {
        setFlash($result['error'], 'error');
    } else {
        $sheet = db_get_tech_sheet($pdo, $id);
        $sent = pretechNotify(
            $pdo, 'sent_back', $sheet, db_get_event($pdo, (int)$sheet['event_id']) ?? [],
            feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', '')),
            ['email' => TECH_EMAIL, 'name' => TECH_NAME], 'emailSmtpSend', $result['retakes']
        );
        setFlash(count($result['retakes']) . ' ' . (count($result['retakes']) === 1 ? 'photo' : 'photos') . ' sent back for a retake.'
            . ($sent ? ' The competitor was emailed.' : ' The notification email could not be sent.'), $sent ? 'success' : 'error');
    }
    header('Location: admin.php?action=tech-sheet&id=' . $id);
    exit;
}

/** The pre-tech photos of a sheet, with accept / send-back controls while they are awaiting review. */
function renderPretechReviewCard(array $sheet, array $snapshot, string $csrf): void {
    $photos = array_filter($snapshot['photos'], fn(array $p): bool => $p['file_path'] !== '');
    $photoStatus = $sheet['photo_status'] ?? null;
    if ($photoStatus === null && !$photos) return;

    $id = (int)$sheet['id'];
    $awaiting = $photoStatus === 'submitted' && $sheet['status'] === 'submitted';
    $statusLabels = [
        'draft' => 'The competitor has started adding photos (not submitted yet).',
        'submitted' => 'Submitted: awaiting review.',
        'needs_changes' => 'Sent back: waiting for the competitor to retake photos.',
        'accepted' => 'Photos reviewed and accepted.',
    ];
    ?>
  <div class="detail-card" id="pretech-review">
    <h2>Pre-tech photos</h2>
    <p><?= h($statusLabels[$photoStatus] ?? 'No photo set yet.') ?> <?= count($photos) ?> <?= count($photos) === 1 ? 'photo' : 'photos' ?> on file.</p>
    <?php if ($awaiting): ?>
    <p class="form-hint">Accepting these photos makes the car pre-teched for the season. To send photos back, tick each one, say what is wrong, and use "Send back for retakes".</p>
    <?php endif; ?>

    <form method="post" action="admin.php?action=tech-sheet-photos-send-back" id="pretech-review-form">
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
      <button type="submit" class="btn btn-secondary" id="pretech-sendback-btn">Send back for retakes</button>
      <?php endif; ?>
    </form>

    <?php if ($awaiting): ?>
    <form method="post" action="admin.php?action=tech-sheet-photos-accept" style="margin-top:.75rem">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="btn btn-primary" id="pretech-accept-btn">Accept photos (pre-teched)</button>
    </form>
    <?php endif; ?>
  </div>
<?php
}
