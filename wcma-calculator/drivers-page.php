<?php
// wcma-calculator/drivers-page.php
//
// Markup for the Drivers page (spec §4). Pure: no DB, no session, no echo. Callers must have loaded
// view_helpers.php (h()) and home-page.php (homeStatusClass()).

function driversRenderRow(array $row, string $csrf): string {
    $d = $row['driver'];
    $id = (int)$d['id'];
    $out = '<div class="hub-card drivers-row"><div class="drivers-row-head"><h3>' . h((string)$d['name']) . ($row['isSelf'] ? ' (you)' : '') . '</h3>'
        . '<span class="hub-status ' . h(homeStatusClass($row['state'])) . '">' . h($row['label']) . '</span>'
        . '<a class="hub-btn hub-btn--secondary" href="' . h($row['action']['url']) . '">' . h($row['action']['label']) . '</a></div>'
        . '<form method="post" action="drivers.php" class="hub-line">'
        . '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">'
        . '<input type="hidden" name="action" value="licence"><input type="hidden" name="driver_id" value="' . $id . '">'
        . '<label for="licence-' . $id . '">Licence number</label>'
        . '<input type="text" id="licence-' . $id . '" name="licence_no" maxlength="40" value="' . h((string)($d['licence_no'] ?? '')) . '">'
        . '<button type="submit" class="hub-btn hub-btn--link">Save</button></form>';
    $m = $row['media'];
    $out .= '<p class="hub-line">Media profile: <span class="hub-status ' . h($m['class']) . '">' . h($m['label']) . '</span> '
        . '<a href="media-profile.php?driver_id=' . $id . '">' . ($m['state'] === 'none' ? 'Set up media profile' : 'Edit media profile') . '</a></p>';
    if ($row['isSelf']) {
        $out .= '<p class="form-hint">Your name comes from your account. <a href="profile.php">Change it in Profile</a>.</p>';
    }
    return $out . '</div>';
}

function renderDriversHtml(array $vm): string {
    $csrf = (string)$vm['csrf'];
    $self = array_values(array_filter($vm['rows'], fn(array $r): bool => $r['isSelf']));
    $others = array_values(array_filter($vm['rows'], fn(array $r): bool => !$r['isSelf']));

    $out = '<h1>Drivers</h1><p class="hub-intro">Gear is checked every season. From January 1, each driver needs gear tech for '
        . (int)$vm['season'] . ': pre-tech it with photos, or have it checked at the track. Names and licence numbers carry over from year to year.</p>'
        . '<p class="form-hint">Licences are managed on MotorsportReg.';
    if ($vm['licenceLink'] !== null) {
        $out .= ' <a href="' . h((string)$vm['licenceLink']['url']) . '" target="_blank" rel="noopener">' . h((string)$vm['licenceLink']['label']) . ' &#8599;</a>';
    }
    $out .= '</p><h2>You</h2>';
    foreach ($self as $row) $out .= driversRenderRow($row, $csrf);

    $out .= '<h2>Co-drivers you manage</h2>';
    if (!$others) $out .= '<p>No co-drivers yet.</p>';
    foreach ($others as $row) $out .= driversRenderRow($row, $csrf);

    $out .= '<form method="post" action="drivers.php" class="hub-card" id="drivers-add-form">'
        . '<input type="hidden" name="csrf_token" value="' . h($csrf) . '"><input type="hidden" name="action" value="add">'
        . '<h3>Add a co-driver</h3>'
        . '<label for="driver-name">Name</label><input type="text" id="driver-name" name="name" maxlength="100" required>'
        . '<label for="driver-licence">Licence number (optional)</label><input type="text" id="driver-licence" name="licence_no" maxlength="40">'
        . '<button type="submit" class="hub-btn">Add co-driver</button></form>';
    return $out;
}
