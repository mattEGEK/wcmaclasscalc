<?php
// wcma-calculator/roles.php
//
// Pure role helpers (no session, no DB). user < inspector < admin. Inspectors do classing,
// car tech and gear; admins also run the back office.

const ROLE_LEVELS = ['user' => 0, 'inspector' => 1, 'admin' => 2];

/** admin.php actions an inspector may use. Everything else in admin.php is admin-only. */
const ADMIN_INSPECTOR_ACTIONS = [
    'list', 'view', 'file', 'resend', 'export',
    'tech-sheets', 'tech-sheet', 'tech-sheet-accept', 'tech-sheet-revoke', 'tech-sheet-sig',
    'tech-sheet-photos-accept', 'tech-sheet-photos-send-back',
    'gear', 'gear-record', 'gear-record-accept', 'gear-record-revoke', 'gear-photos-accept',
    'gear-photos-send-back', 'gear-create-accept',
];

function user_has_role(?array $user, string $min): bool {
    if ($user === null) return false;
    $have = ROLE_LEVELS[$user['role'] ?? ''] ?? null;
    return $have !== null && $have >= (ROLE_LEVELS[$min] ?? PHP_INT_MAX);
}

function adminActionMinRole(string $action): string {
    return in_array($action, ADMIN_INSPECTOR_ACTIONS, true) ? 'inspector' : 'admin';
}

/** Staff review emails name the reviewer, so staff accounts need at least two name words. */
function userHasFirstAndLastName(string $name): bool {
    return count(preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY)) >= 2;
}
