<?php
// wcma-calculator/gear-page.php
//
// Markup for a driver's gear pre-tech page (gear.php?action=pretech). Pure output; decisions live
// in gear-lib.php. Callers must have loaded photo-requirements.php, inspection-lib.php,
// pretech-page.php (pretechRenderCard), gear-lib.php and view_helpers.php.

function renderGearPretechPage(array $gear, array $snapshot, string $csrf, ?array $flash): void {
    $id = (int)$gear['id'];
    $requirements = photoRequirements('gear');
    $photoStatus = $gear['photo_status'] ?? null;
    $accepted = ($gear['status'] ?? 'open') === 'accepted';
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
    $driverLine = $gear['driver_name'] . ' — ' . (int)$gear['season'];
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gear pre-tech — WCMA Calculator</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Archivo+Narrow:wght@600;700&display=swap">
<link rel="stylesheet" href="<?= hubAsset('css/calculator.css') ?>">
<link rel="stylesheet" href="<?= hubAsset('css/hub.css') ?>">
</head>
<body class="hub">
<div class="container">
  <?php renderSiteHeader('Gear pre-tech', '<a href="drivers.php">← Back to Drivers</a>', 'drivers'); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <div class="detail-card">
    <h2><?= h($driverLine) ?></h2>
    <?php if ($accepted): ?>
      <p>This driver's gear is already teched for <?= (int)$gear['season'] ?>. You do not need to submit photos.</p>
    <?php else: ?>
      <p>Optional: submit photos of this driver's gear so an inspector can review them before the event. If they are accepted, the gear does not need to be checked at the track and you just collect your decals. The gear can still be checked in person instead.</p>
      <?php if ($photoStatus === 'submitted'): ?>
        <p class="badge-pending">These photos were submitted for review. You will get an email when an inspector has looked at them.</p>
      <?php elseif ($photoStatus === 'needs_changes'): ?>
        <p class="badge-fail">An inspector asked for some photos to be retaken. Retake the flagged photos below, then submit again.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php if (!$accepted): ?>
  <div class="checklist-progress-wrap">
    <div class="checklist-progress-label" id="pretech-progress"><?= (int)$done ?> of <?= (int)$requiredTotal ?> required photos</div>
    <div class="checklist-progress-bar"><div class="checklist-progress-fill" id="pretech-fill" style="width:<?= $requiredTotal > 0 ? (int)round($done / $requiredTotal * 100) : 0 ?>%"></div></div>
  </div>

  <?php foreach ($requirements as $key => $req): ?>
    <?= pretechRenderCard($key, $req, $snapshot['photos'][$key] ?? null, in_array($key, $snapshot['applicable'], true), $locked, 'This applies to this driver') ?>
  <?php endforeach; ?>

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
</div>
<script src="js/form-feedback.js"></script>
<?php renderSiteFooter(); ?>
</body>
</html><?php
}
