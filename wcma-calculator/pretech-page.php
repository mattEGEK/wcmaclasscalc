<?php
// wcma-calculator/pretech-page.php
//
// Markup for the competitor's "Get pre-teched" page (tech-sheets.php?action=pretech). Pure output;
// the decisions (mode, completeness) are made by pretech-lib.php. Callers must have loaded
// photo-requirements.php, inspection-lib.php (inspectionPublicPhoto) and view_helpers.php.
require_once __DIR__ . '/tech-status.php';   // iceSeasonLabel()

require_once __DIR__ . '/photo-requirements.php';

/**
 * The status word on a photo card. A photo is needed when it is required, or conditional and ticked
 * as applying; anything else without a photo is grey, not amber (UX review 2026-09-30 §M6).
 */
function pretechPhotoNeeded(string $tier, bool $applies): bool {
    return $tier === 'required' || ($tier === 'conditional' && $applies);
}

function pretechPhotoStatusText(?array $photo, string $tier, bool $applies = false): string {
    if ($photo === null || $photo['file_path'] === '') {
        if (pretechPhotoNeeded($tier, $applies)) return 'Photo needed';
        return $tier === 'conditional' ? 'Only if it applies' : 'Optional';
    }
    if ($photo['review_status'] === 'retake') return 'Retake requested';
    if ($photo['review_status'] === 'accepted') return 'Accepted';
    return 'Added';
}

function pretechRenderTypedField(array $field, array $typed, bool $locked): string {
    $id = 'typed-' . $field['name'] . '-' . bin2hex(random_bytes(3));
    $value = (string)($typed[$field['name']] ?? '');
    $out = '<label for="' . h($id) . '">' . h($field['label']) . '</label>';
    if ($field['type'] === 'select') {
        $out .= '<select id="' . h($id) . '" data-typed="' . h($field['name']) . '"' . ($locked ? ' disabled' : '') . '><option value="">—</option>';
        foreach ($field['options'] as $option) {
            $out .= '<option value="' . h($option) . '"' . ($option === $value ? ' selected' : '') . '>' . h($option) . '</option>';
        }
        return $out . '</select>';
    }
    $placeholder = $field['type'] === 'month_year' ? ' placeholder="MM/YYYY" inputmode="numeric" maxlength="7"' : '';
    return $out . '<input type="text" id="' . h($id) . '" data-typed="' . h($field['name']) . '" value="' . h($value) . '"' . $placeholder . ($locked ? ' disabled' : '') . '>';
}

function pretechRenderCard(string $key, array $req, ?array $photoRow, bool $applies, bool $locked, string $appliesLabel = 'This applies to my car'): string {
    $hasPhoto = $photoRow !== null && $photoRow['file_path'] !== '';
    $public = $hasPhoto ? inspectionPublicPhoto($photoRow) : null;
    $typed = $public['typed'] ?? [];
    $isRetake = $hasPhoto && $photoRow['review_status'] === 'retake';
    $appliesLabel = (string)($req['applies_label'] ?? $appliesLabel);

    $out = '<div class="pretech-card" data-key="' . h($key) . '" data-tier="' . h($req['tier']) . '">';
    $out .= '<h3>' . h($req['label']) . ' <span class="pretech-status ' . ($isRetake ? 'badge-fail' : ($hasPhoto ? 'badge-ok' : (pretechPhotoNeeded($req['tier'], $applies) ? 'badge-pending' : 'badge-optional'))) . '" data-status>'
        . h(pretechPhotoStatusText($photoRow, $req['tier'], $applies)) . '</span></h3>';
    $out .= '<p class="form-hint">' . h($req['guidance']) . '</p>';
    if ($req['tier'] === 'recommended') {
        $out .= '<p class="form-hint">Recommended, not required.</p>';
    }
    if ($isRetake && !empty($photoRow['reviewer_note'])) {
        $out .= '<p class="pretech-note badge-fail">Inspector note: ' . h((string)$photoRow['reviewer_note']) . '</p>';
    }
    if ($req['tier'] === 'conditional') {
        $out .= '<label class="pretech-toggle"><input type="checkbox" data-applies-toggle' . ($applies ? ' checked' : '') . ($locked ? ' disabled' : '')
            . '> ' . h($appliesLabel) . '</label>';
    }
    $out .= '<img class="pretech-thumb" data-thumb alt="' . h($req['label']) . '"' . ($hasPhoto ? ' src="' . h($public['url']) . '"' : ' hidden') . '>';
    foreach ($req['typed'] as $field) {
        $out .= '<div class="pretech-typed">' . pretechRenderTypedField($field, $typed, $locked) . '</div>';
    }
    if (!$locked) {
        $out .= '<label class="btn btn-secondary pretech-upload">' . ($hasPhoto ? 'Retake photo' : 'Take or choose photo')
            . '<input type="file" accept="image/*" capture="environment" data-photo-input hidden></label>';
    }
    $out .= '<p class="badge-fail" data-error hidden></p>';
    return $out . '</div>';
}

function renderPretechPage(array $sheet, array $event, array $mode, array $snapshot, string $csrf, ?array $flash): void {
    $id = (int)$sheet['id'];
    $requirements = photoRequirementsFor($sheet, 'car');
    $noRequirements = $requirements === [];
    $photoStatus = $sheet['photo_status'] ?? null;
    $locked = in_array($photoStatus, ['submitted', 'accepted'], true) || ($sheet['status'] ?? '') === 'teched';
    $formMode = $mode['mode'] === 'this_sheet';
    $missing = count($snapshot['missing']);
    $requiredTotal = count($requirements) - count(array_filter($requirements, fn(array $r): bool => $r['tier'] === 'recommended'))
        - count(array_filter($requirements, fn(array $r): bool => $r['tier'] === 'conditional'))
        + count($snapshot['applicable']);
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
        'sheetId' => $id, 'csrf' => $csrf, 'locked' => $locked,
        'requirements' => $clientRequirements, 'photos' => (object)$clientPhotos, 'applicable' => $snapshot['applicable'],
    ];
    $carLine = 'Car #' . $sheet['car_number'] . ' — ' . trim($sheet['car_make'] . ' ' . $sheet['car_model']) . ' — ' . ($event['name'] ?? '');
    renderPageStart('Get pre-teched', 'garage', ['flash' => $flash, 'subnav' => '<a href="tech-sheets.php?action=view&amp;id=' . $id . '">&larr; Back to the tech sheet</a>']);
    ?>
  <h1 class="hub-page-title">Get pre-teched</h1>

  <div class="detail-card">
    <h2><?= h($carLine) ?></h2>
    <?php if ($mode['mode'] === 'car_accepted' && !empty($mode['by_race'])): ?>
      <p>This car passed race tech for <?= (int)($sheet['season'] ?? date('Y')) ?>, which covers TA/Drift. You do not need to submit photos.</p>
    <?php elseif ($mode['mode'] === 'car_accepted'): ?>
      <p>This car is already teched for <?= h(($sheet['discipline'] ?? 'summer') === 'ice' ? iceSeasonLabel((int)($sheet['season'] ?? date('Y'))) : (string)(int)($sheet['season'] ?? date('Y'))) ?>. You do not need to submit photos.</p>
    <?php elseif ($mode['mode'] === 'held_elsewhere'): ?>
      <p>Your pre-tech photos for this car are on another of your tech sheets.
        <a href="tech-sheets.php?action=pretech&amp;id=<?= (int)$mode['sheet_id'] ?>">Open that page</a>.</p>
    <?php else: ?>
      <p>Optional: submit photos of your car so an inspector can review them before the event. If they are accepted, you skip inspection at the track and just collect your decals. You can still be teched in person instead.</p>
      <?php if ($photoStatus === 'submitted'): ?>
        <p class="hub-note hub-note--info">Your photos are with an inspector. You will get an email when they have been looked at.</p>
      <?php elseif ($photoStatus === 'needs_changes'): ?>
        <p class="hub-note hub-note--todo">An inspector asked for some photos to be retaken. Retake the flagged photos below, then submit again.</p>
      <?php elseif ($photoStatus === 'accepted'): ?>
        <p class="hub-note hub-note--ok">Your photos were reviewed and accepted.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php if ($formMode && $noRequirements): ?>
  <p class="hub-note hub-note--todo">This tech sheet's class isn't on the club's current list. Edit the sheet and pick a class before adding photos.</p>
<?php elseif ($formMode): ?>
  <div class="checklist-progress-wrap">
    <div class="checklist-progress-label" id="pretech-progress"><?= (int)$done ?> of <?= (int)$requiredTotal ?> required photos</div>
    <div class="checklist-progress-bar"><div class="checklist-progress-fill" id="pretech-fill" style="width:<?= $requiredTotal > 0 ? (int)round($done / $requiredTotal * 100) : 0 ?>%"></div></div>
  </div>

  <div class="review-grid">
  <?php foreach ($requirements as $key => $req): ?>
    <?= pretechRenderCard($key, $req, $snapshot['photos'][$key] ?? null, in_array($key, $snapshot['applicable'], true), $locked) ?>
  <?php endforeach; ?>
  </div>

  <?php if (!$locked): ?>
  <form method="post" action="tech-sheets.php?action=pretech-submit" id="pretech-submit-form" class="detail-card">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <button type="submit" class="btn btn-primary" id="pretech-submit-btn"<?= $missing > 0 ? ' disabled' : '' ?>>Submit for pre-tech review</button>
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
