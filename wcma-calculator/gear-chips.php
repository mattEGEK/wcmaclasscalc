<?php
// wcma-calculator/gear-chips.php
//
// One-line gear status chips per driver on a tech sheet or roster row. Pure output: the links come
// from gearLinksForSheet() (gear-lib.php).
require_once __DIR__ . '/view_helpers.php';
require_once __DIR__ . '/gear-lib.php';

/**
 * @param array $links   gearLinksForSheet() result
 * @param string $audience 'owner' (competitor pages) or 'admin' (inspector pages)
 */
function renderGearChips(array $links, string $audience): string {
    if (!$links) return '';
    $html = '<ul class="gear-chips">';
    foreach ($links as $l) {
        $name = h($l['name']);
        $gear = $l['gear'];
        if ($gear === null) {
            $html .= '<li class="gear-chip">' . $name . ': <span class="badge-pending">No gear record</span>';
            if ($audience !== 'admin') {
                $html .= ' <a href="gear.php?name=' . h(rawurlencode($l['name'])) . '">Add gear record</a>';
            }
            $html .= '</li>';
            continue;
        }
        $label = gearStatusLabel($l['status'], (int)$gear['season']);
        $class = gearStatusBadgeClass($l['status']['state']);
        $href = $audience === 'admin'
            ? 'admin.php?action=gear-record&amp;id=' . (int)$gear['id']
            : 'gear.php?action=pretech&amp;id=' . (int)$gear['id'];
        $html .= '<li class="gear-chip">' . $name . ': <a class="' . h($class) . '" href="' . $href . '">' . h($label) . '</a></li>';
    }
    return $html . '</ul>';
}
