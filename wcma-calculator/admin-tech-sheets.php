<?php
// wcma-calculator/admin-tech-sheets.php
//
// Inspector section: the tech sheet review page (accept in person, photo pre-tech review) and its
// POST handlers. Included by inspect.php, which provides the role gate, the POST/CSRF checks and
// the router. (The file keeps its old name; the event roster that used to live here is in
// inspect-page.php.)

function handleTechSheetView(PDO $pdo, int $id): void {
    $sheet = db_get_tech_sheet($pdo, $id);
    if ($sheet === null) {
        setFlash('Tech sheet not found.', 'error');
        header('Location: inspect.php');
        exit;
    }
    $event = db_get_event($pdo, (int)$sheet['event_id']) ?? [];
    $drivers = db_get_tech_sheet_drivers($pdo, $id);
    $carStatus = techSheetIsTaDrift($sheet)
        ? taDriftSheetCarStatus($sheet, db_get_user_tech_sheets($pdo, (int)$sheet['user_id']))
        : techCarStatus(db_get_sheet_identity_sheets($pdo, $sheet));
    $reviewer = !empty($sheet['reviewed_by_user_id']) ? db_find_user_by_id($pdo, (int)$sheet['reviewed_by_user_id']) : null;
    $gearLinks = gearLinksForSheet($sheet, $drivers, db_get_user_gear_records($pdo, (int)$sheet['user_id']));
    renderTechSheetViewPage($sheet, $drivers, $event, $carStatus, $reviewer, generateCsrfToken(), getFlash(), pretechSnapshot($pdo, $id), $gearLinks);
}

function handleTechSheetAccept(PDO $pdo, int $id): void {
    $user = current_user();
    $result = techReviewAcceptInPerson($pdo, __DIR__, $id, (int)$user['id'], (string)($_POST['tech_signature'] ?? ''));
    if ($result['ok']) {
        $sheet = db_get_tech_sheet($pdo, $id);
        $sent = pretechNotify($pdo, 'accepted_in_person', $sheet, db_get_event($pdo, (int)$sheet['event_id']) ?? [],
            feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', '')),
            ['email' => TECH_EMAIL, 'name' => TECH_NAME], 'emailSmtpSend');
        setFlash('Sheet accepted (teched in person).' . ($sent ? ' The competitor was emailed.' : ' The email could not be sent.'), $sent ? 'success' : 'error');
    } else {
        setFlash($result['error'], 'error');
    }
    header('Location: inspect.php?action=tech-sheet&id=' . $id);
    exit;
}

function handleTechSheetRevoke(PDO $pdo, int $id): void {
    $result = techReviewRevoke($pdo, __DIR__, $id, $_POST['revoke_note'] ?? null);
    setFlash($result['ok'] ? 'Acceptance revoked. The sheet is back to submitted.' : $result['error'], $result['ok'] ? 'success' : 'error');
    header('Location: inspect.php?action=tech-sheet&id=' . $id);
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
        return 'inspect.php?action=tech-sheet-sig&id=' . $techSheetId . '&which=' . rawurlencode($which);
    };
}

function renderTechSheetViewPage(array $sheet, array $drivers, array $event, array $carStatus, ?array $reviewer, string $csrf, ?array $flash, array $snapshot, array $gearLinks = []): void {
    $id = (int)$sheet['id'];
    $accepted = $sheet['status'] === 'teched';
    $statusLabel = techSheetIsTaDrift($sheet)
        ? taDriftCarTechStatusLabel($carStatus, (int)$sheet['season'], (string)$sheet['club'])
        : techCarStatusLabel($carStatus, (int)$sheet['season'], (string)($sheet['discipline'] ?? 'summer'));
    $acceptedLine = '';
    if ($accepted) {
        $how = ($sheet['accepted_via'] ?? 'in_person') === 'photos' ? 'remotely' : 'in person';
        $who = $reviewer ? ' by ' . $reviewer['name'] : '';
        $when = !empty($sheet['reviewed_at']) ? ' on ' . date('M j, Y g:i A', strtotime($sheet['reviewed_at'])) : '';
        $acceptedLine = 'Accepted ' . $how . $who . $when . '.';
    }
    renderPageStart('Tech Sheet #' . $id, 'inspect', ['flash' => $flash, 'subnav' => inspectSubnavHtml('roster')]);
    ?>
<p><a href="inspect.php?event=<?= (int)$sheet['event_id'] ?>">&larr; Back to the roster</a></p>
<h1 class="hub-page-title">Tech Sheet #<?= $id ?></h1>
  <div class="detail-card">
    <h2>Tech review</h2>
    <p>Car #<?= h($sheet['car_number']) ?> — <?= h(trim($sheet['car_make'] . ' ' . $sheet['car_model'])) ?> (<?= h($sheet['entrant_name']) ?>)</p>
    <?php if (techSheetIsTaDrift($sheet)): ?><p><span class="admin-chip admin-chip--info">TA/Drift</span> <?= h((string)$sheet['club']) ?> supplementary regulations<?= !empty($sheet['caged']) ? ' · roll bar or cage' : '' ?></p><?php endif; ?>
    <p>Car status: <strong class="<?= h(techCarStatusBadgeClass($carStatus['state'])) ?>"><?= h($statusLabel) ?></strong></p>
    <?php if ($gearLinks): ?><p>Driver gear:</p><?= renderGearChips($gearLinks, 'admin', ['sheet_season' => (int)($sheet['season'] ?? 0), 'csrf' => $csrf, 'sheet_id' => $id, 'hidden' => ['back' => 'sheet']]) ?><?php endif; ?>

    <?php if (!empty($sheet['revoke_note'])): ?><p class="form-hint">Revoked earlier: <?= h((string)$sheet['revoke_note']) ?></p><?php endif; ?>
    <?php if ($accepted): ?>
    <p><?= h($acceptedLine) ?></p>
    <form method="post" action="inspect.php?action=tech-sheet-revoke" data-confirm="Revoke this acceptance? The sheet goes back to submitted and the inspector signature is removed.">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <label for="revoke-note">Why are you revoking it? The competitor sees this.</label>
      <textarea id="revoke-note" name="revoke_note" maxlength="500" rows="2" required data-message="Say why you are revoking this acceptance." placeholder="For example: car changed, new engine"></textarea>
      <button type="submit" class="btn btn-secondary">Revoke acceptance</button>
    </form>
    <?php else: ?>
    <p class="form-hint">Accepting records that what the competitor submitted matches the car in front of you.</p>
    <form method="post" action="inspect.php?action=tech-sheet-accept" id="tech-accept-form">
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
<?php
    renderPageEnd(['scripts' => '<script src="js/confirm-modal.js"></script><script src="js/form-feedback.js"></script>'
        . '<script src="js/signature-pad.js"></script><script src="js/admin-tech-review.js"></script>']);
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
    header('Location: inspect.php?action=tech-sheet&id=' . $id);
    exit;
}

function handleTechSheetPhotosSendBack(PDO $pdo, int $id): void {
    $flagged = isset($_POST['retake']) && is_array($_POST['retake']) ? array_keys($_POST['retake']) : [];
    $noteInput = isset($_POST['note']) && is_array($_POST['note']) ? $_POST['note'] : [];
    $notes = [];
    foreach ($flagged as $key) {
        $notes[(string)$key] = is_string($noteInput[$key] ?? null) ? $noteInput[$key] : '';
    }

    $result = pretechSendBack($pdo, $id, $notes);
    if (!$result['ok']) {
        setFlash($result['error'], 'error');
    } else {
        $sheet = db_get_tech_sheet($pdo, $id);
        $sent = pretechNotify(
            $pdo, 'sent_back', $sheet, db_get_event($pdo, (int)$sheet['event_id']) ?? [],
            feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', '')),
            ['email' => TECH_EMAIL, 'name' => TECH_NAME], 'emailSmtpSend', $result['retakes'],
            db_find_user_by_id($pdo, (int)current_user()['id'])
        );
        setFlash(count($result['retakes']) . ' ' . (count($result['retakes']) === 1 ? 'photo' : 'photos') . ' sent back for a retake.'
            . ($sent ? ' The competitor was emailed.' : ' The notification email could not be sent.'), $sent ? 'success' : 'error');
    }
    header('Location: inspect.php?action=tech-sheet&id=' . $id);
    exit;
}

/** The pre-tech photos of a sheet, with accept / send-back controls while they are awaiting review. */
function renderPretechReviewCard(array $sheet, array $snapshot, string $csrf): void {
    $photos = array_filter(pretechCurrentPhotos($sheet, $snapshot['photos']), fn(array $p): bool => $p['file_path'] !== '');
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

    <form method="post" action="inspect.php?action=tech-sheet-photos-send-back" id="pretech-review-form">
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
    <form method="post" action="inspect.php?action=tech-sheet-photos-accept" style="margin-top:.75rem">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="btn btn-primary" id="pretech-accept-btn">Accept photos (pre-teched)</button>
    </form>
    <?php endif; ?>
  </div>
<?php
}
