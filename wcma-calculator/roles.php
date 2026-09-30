<?php
// wcma-calculator/roles.php
//
// Pure role helpers (no session, no DB). user < inspector < admin. Inspectors work in inspect.php
// (classing, car tech, gear); admins also run the admin.php back office.

const ROLE_LEVELS = ['user' => 0, 'inspector' => 1, 'admin' => 2];

function user_has_role(?array $user, string $min): bool {
    if ($user === null) return false;
    $have = ROLE_LEVELS[$user['role'] ?? ''] ?? null;
    return $have !== null && $have >= (ROLE_LEVELS[$min] ?? PHP_INT_MAX);
}

/** Pure decision behind require_role(): 'login' (send to sign in), 'forbidden' (403), or 'ok'. */
function roleGateOutcome(?array $user, string $min): string {
    if ($user === null) return 'login';
    return user_has_role($user, $min) ? 'ok' : 'forbidden';
}

/** inspect.php actions only admins may use. Everything else in the Inspector section is open to inspectors. */
const INSPECT_ADMIN_ONLY_ACTIONS = ['declaration-delete', 'declarations-bulk-delete', 'declaration-update-contact'];

/** inspect.php actions that change data: POST-only and CSRF-checked in inspect.php before its router runs. */
const INSPECT_POST_ACTIONS = [
    'tech-sheet-accept', 'tech-sheet-revoke', 'tech-sheet-photos-accept', 'tech-sheet-photos-send-back',
    'gear-record-accept', 'gear-record-revoke', 'gear-photos-accept', 'gear-photos-send-back', 'gear-create-accept', 'gear-record-upgrade-race',
    'declaration-accept', 'declaration-send-back', 'declaration-resend',
    'declaration-delete', 'declarations-bulk-delete', 'declaration-update-contact',
];

function inspectActionMinRole(string $action): string {
    return in_array($action, INSPECT_ADMIN_ONLY_ACTIONS, true) ? 'admin' : 'inspector';
}

/**
 * admin.php GET actions that moved to inspect.php in Phase 4: action => [inspect.php action, query keys
 * carried over]. Emails already sent and bookmarks use the old URLs, so admin.php redirects them.
 */
const ADMIN_MOVED_ACTIONS = [
    'tech-sheets' => ['roster', ['event']],
    'tech-sheet' => ['tech-sheet', ['id']],
    'tech-sheet-sig' => ['tech-sheet-sig', ['id', 'which']],
    'gear' => ['gear', ['season', 'filter']],
    'gear-record' => ['gear-record', ['id']],
    'list' => ['classing', []],
    'view' => ['declaration', ['id']],
    'file' => ['declaration-file', ['id', 'field']],
    'export' => ['declarations-export', []],
];

/** The inspect.php URL for a moved admin.php action, or null when $action has not moved. */
function adminMovedActionUrl(string $action, array $query): ?string {
    if (!isset(ADMIN_MOVED_ACTIONS[$action])) return null;
    [$to, $keys] = ADMIN_MOVED_ACTIONS[$action];
    $params = ['action' => $to];
    foreach ($keys as $key) {
        if (isset($query[$key]) && is_scalar($query[$key]) && (string)$query[$key] !== '') $params[$key] = (string)$query[$key];
    }
    return 'inspect.php?' . http_build_query($params);
}

/** Staff review emails name the reviewer, so staff accounts need at least two name words. */
function userHasFirstAndLastName(string $name): bool {
    return count(preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY)) >= 2;
}

/** The Media section (announcer sheet, media kit, public review): admins, and accounts flagged is_media. */
function mediaCanAccess(?array $user): bool {
    if ($user === null) return false;
    return user_has_role($user, 'admin') || !empty($user['is_media']);
}
