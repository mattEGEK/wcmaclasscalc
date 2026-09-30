<?php
// wcma-calculator/gear-page.php
//
// Markup for a driver's gear pre-tech page (gear.php?action=pretech). Pure output; decisions live
// in gear-lib.php. Callers must have loaded photo-requirements.php, inspection-lib.php,
// pretech-page.php (pretechRenderCard), gear-lib.php and view_helpers.php.
require_once __DIR__ . '/tech-status.php';   // iceSeasonLabel()

function renderGearPretechPage(array $gear, array $snapshot, string $csrf, ?array $flash): void {
    $id = (int)$gear['id'];
    $requirements = photoRequirementsFor($gear, 'gear');
    $photoStatus = $gear['photo_status'] ?? null;
    $upgrading = gearIsRaceUpgrade($gear);
    $accepted = ($gear['status'] ?? 'open') === 'accepted' && !$upgrading;
    $locked = $accepted || in_array($photoStatus, ['submitted', 'accepted'], true);
    $missing = count($snapshot['missing']);
    $requiredTotal = gearRequiredTotal($requirements, $snapshot['applicable']);
    $done = $requiredTotal - $missing;

    $clientPhotos = [];
    foreach ($snapshot['photos'] as $key => $row) {
        if ($row['file_path'] !== '') $clientPhotos[$key] = inspectionPublicPhoto($row);
    }
    $clientRequirements = [];
    foreach ($requirements as $key => $req) {
        $clientRequirements[] = ['key' => $key, 'tier' => $req['tier'], 'typed' => array_map(fn(array $f): array => ['name' => $f['name'], 'type' => $f['type']], $req['typed'])];
    }
    $state = [
        'subjectType' => 'gear_record', 'subjectId' => $id, 'csrf' => $csrf, 'locked' => $locked,
        'requirements' => $clientRequirements, 'photos' => (object)$clientPhotos, 'applicable' => $snapshot['applicable'],
    ];
    $isIce = ($gear['discipline'] ?? 'summer') === 'ice';
    $driverLine = $gear['driver_name'] . ' — ' . ($isIce ? iceSeasonLabel((int)$gear['season']) : (string)(int)$gear['season']);
    $backLink = $isIce ? '<a href="garage.php">&larr; Back to Garage</a>' : '<a href="drivers.php">&larr; Back to Drivers</a>';
    renderPageStart('Gear pre-tech', $isIce ? 'garage' : 'drivers', ['flash' => $flash, 'subnav' => $backLink]);
    ?>
  <h1 class="hub-page-title">Gear pre-tech</h1>
  <?= revokeNoticeHtml($gear['revoke_note'] ?? null, 'Gear') ?>

  <div class="detail-card">
    <h2><?= h($driverLine) ?></h2>
    <?php if ($accepted && ($gear['discipline'] ?? 'summer') === 'summer' && ($gear['level'] ?? null) === GEAR_LEVEL_TA_DRIFT): ?>
      <p>This driver's gear is teched for TA/Drift in <?= (int)$gear['season'] ?>. To race as well, send photos of the full race gear.</p>
      <form method="post" action="gear.php?action=upgrade-race">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn btn-secondary">Send race gear photos</button>
      </form>
    <?php elseif ($accepted): ?>
      <p>This driver's gear is already teched for <?= h(($gear['discipline'] ?? 'summer') === 'ice' ? iceSeasonLabel((int)$gear['season']) : (string)(int)$gear['season']) ?>. You do not need to submit photos.</p>
    <?php else: ?>
      <?php if ($upgrading): ?><p><strong>Race gear photos.</strong> This driver's gear stays teched for TA/Drift while an inspector reviews them.</p><?php endif; ?>
      <p>Optional: submit photos of this driver's gear so an inspector can review them before the event. If they are accepted, the gear does not need to be checked at the track and you just collect your decals. The gear can still be checked in person instead.</p>
      <?php if ($photoStatus === 'submitted'): ?>
        <p class="hub-note hub-note--info">These photos are with an inspector. You will get an email when they have been looked at.</p>
      <?php elseif ($photoStatus === 'needs_changes'): ?>
        <p class="hub-note hub-note--todo">An inspector asked for some photos to be retaken. Retake the flagged photos below, then submit again.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php if (!$accepted): ?>
  <div class="checklist-progress-wrap">
    <div class="checklist-progress-label" id="pretech-progress"><?= (int)$done ?> of <?= (int)$requiredTotal ?> required photos</div>
    <div class="checklist-progress-bar"><div class="checklist-progress-fill" id="pretech-fill" style="width:<?= $requiredTotal > 0 ? (int)round($done / $requiredTotal * 100) : 0 ?>%"></div></div>
  </div>

  <div class="review-grid">
  <?php foreach ($requirements as $key => $req): ?>
    <?= pretechRenderCard($key, $req, $snapshot['photos'][$key] ?? null, in_array($key, $snapshot['applicable'], true), $locked, 'This applies to this driver') ?>
  <?php endforeach; ?>
  </div>

  <?php if (!$locked): ?>
  <form method="post" action="gear.php?action=pretech-submit" id="pretech-submit-form" class="detail-card">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <button type="submit" class="btn btn-primary" id="pretech-submit-btn"<?= $missing > 0 ? ' disabled' : '' ?>>Submit for gear pre-tech review</button>
    <p class="form-hint" id="pretech-submit-hint"><?= $missing > 0 ? 'Add every required photo to enable submitting.' : 'Everything required is in. Submit when you are ready.' ?></p>
  </form>
  <?php endif; ?>

  <script>window.PRETECH_STATE = <?= json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
  <script src="js/photo-resize.js"></script>
  <script src="js/photo-upload.js"></script>
  <script src="js/pretech-progress.js"></script>
  <script src="js/pretech-form.js"></script>
<?php endif; ?>
<?php
    renderPageEnd(['scripts' => '<script src="js/form-feedback.js"></script>']);
}
