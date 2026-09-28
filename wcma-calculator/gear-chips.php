<?php
// wcma-calculator/gear-chips.php
//
// One-line gear status chips per driver on a tech sheet or roster row. Pure output: the links come
// from gearLinksForSheet() (gear-lib.php).
require_once __DIR__ . '/view_helpers.php';
require_once __DIR__ . '/gear-lib.php';

/** The inspector's one-tap form for a driver with no gear record (posts to inspect.php). */
function gearChipCreateForm(string $csrf, int $sheetId, int $driverNumber, array $hidden, ?string $defaultLevel = null, bool $isIce = false): string {
    $out = '<form method="post" action="inspect.php?action=gear-create-accept" class="gear-inline-form">'
        . '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">'
        . '<input type="hidden" name="sheet_id" value="' . $sheetId . '">'
        . '<input type="hidden" name="driver_number" value="' . $driverNumber . '">';
    foreach ($hidden as $name => $value) {
        $out .= '<input type="hidden" name="' . h((string)$name) . '" value="' . h((string)$value) . '">';
    }
    if ($isIce) {
        $out .= '<label class="gear-level-label">Gear level <select name="level" required>';
        $out .= $defaultLevel === null ? '<option value="">Choose</option>' : '';
        foreach (ICE_GEAR_LEVEL_LABELS as $value => $label) {
            $out .= '<option value="' . h($value) . '"' . ($value === $defaultLevel ? ' selected' : '') . '>' . h($label) . '</option>';
        }
        $out .= '</select></label> ';
    }
    return $out . '<button type="submit" class="btn btn-secondary">Create and accept gear in person</button></form>';
}

/**
 * @param array $links    gearLinksForSheet() result
 * @param string $audience 'owner' (competitor pages) or 'admin' (inspector pages)
 * @param array{sheet_season?: int, csrf?: string, sheet_id?: int, hidden?: array<string,string>} $opts
 *   sheet_season: the sheet's season; the competitor "Go to Drivers" link and the inspector button
 *     are only offered when it is the current season (0 or absent = not restricted).
 *   csrf + sheet_id: admin only; when both are given a driver with no record gets a
 *     "Create and accept gear in person" form. hidden: extra hidden inputs for that form.
 *   sheet_id: for the owner audience, an ice driver with no gear record gets an "Add gear photos"
 *     link to start ice gear photos from that sheet.
 */
function renderGearChips(array $links, string $audience, array $opts = []): string {
    if (!$links) return '';
    $sheetSeason = (int)($opts['sheet_season'] ?? 0);
    $csrf = (string)($opts['csrf'] ?? '');
    $sheetId = (int)($opts['sheet_id'] ?? 0);
    $hidden = is_array($opts['hidden'] ?? null) ? $opts['hidden'] : [];

    $html = '<ul class="gear-chips">';
    foreach ($links as $l) {
        $name = h($l['name']);
        $gear = $l['gear'];
        $linkDiscipline = (string)($l['discipline'] ?? DISCIPLINE_SUMMER);
        $isIce = $linkDiscipline === DISCIPLINE_ICE;
        $seasonOk = $sheetSeason === 0 || $sheetSeason === gearSeasonNow($linkDiscipline);
        if ($gear === null) {
            if ($isIce && $audience === 'owner') {
                $html .= '<li class="gear-chip">' . $name . ': <span class="badge-pending">No gear record</span>'
                    . ($seasonOk && $sheetId > 0
                        ? ' <a href="gear.php?action=start-ice&amp;sheet_id=' . $sheetId
                            . ((int)$l['driver_number'] >= 2 ? '&amp;driver=' . (int)$l['driver_number'] : '')
                            . '">Add gear photos</a> or have it checked at the track.'
                        : ' Gear is checked at the track.')
                    . '</li>';
                continue;
            }
            $html .= '<li class="gear-chip">' . $name . ': <span class="badge-pending">No gear record</span>';
            if ($audience === 'owner' && $seasonOk) {
                $html .= ' <a href="drivers.php">Go to Drivers</a>';
            } elseif ($audience === 'admin' && $seasonOk && $csrf !== '' && $sheetId > 0) {
                $html .= ' ' . gearChipCreateForm($csrf, $sheetId, (int)$l['driver_number'], $hidden, $l['default_level'] ?? null, $isIce);
            }
            $html .= '</li>';
            continue;
        }
        $label = gearStatusLabel($l['status'], (int)$gear['season'], (string)($gear['discipline'] ?? DISCIPLINE_SUMMER));
        if (($gear['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE && !empty($gear['level']) && $l['status']['state'] === 'accepted') {
            $label .= ' · ' . (ICE_GEAR_LEVEL_LABELS[$gear['level']] ?? $gear['level']);
        }
        $class = gearStatusBadgeClass($l['status']['state']);
        $href = $audience === 'owner'
            ? 'gear.php?action=pretech&amp;id=' . (int)$gear['id']
            : 'inspect.php?action=gear-record&amp;id=' . (int)$gear['id'];
        $html .= '<li class="gear-chip">' . $name . ': <a class="' . h($class) . '" href="' . $href . '">' . h($label) . '</a></li>';
    }
    return $html . '</ul>';
}
