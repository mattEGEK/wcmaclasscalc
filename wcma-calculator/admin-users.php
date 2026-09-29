<?php
// wcma-calculator/admin-users.php
//
// Admin: Users & roles (admin desktop UX spec 2026-09-29 §3). A read-only list with one Edit modal per
// user; the modal saves name, role and media access together. Loaded by admin.php, which has already
// checked the admin role; POST handlers are reached through adminRequirePost() (CSRF).

/** A name as stored: trimmed, inner whitespace collapsed. */
function adminNormalizeName(string $name): string {
    return trim((string)preg_replace('/\s+/', ' ', $name));
}

/**
 * Why a user-save can't go ahead, or null. $activeAdmins counts active admin accounts: a deactivated
 * admin can't sign in, so demoting the last active one would leave nobody to run the hub. Pure.
 */
function adminUserSaveError(array $target, string $name, string $role, int $activeAdmins): ?string {
    if ($name === '' || mb_strlen($name, 'UTF-8') > 100) return 'Enter a name of 100 characters or fewer.';
    if (!isset(ROLE_LEVELS[$role])) return 'Choose a role.';
    $targetIsActiveAdmin = $target['role'] === 'admin' && (int)($target['active'] ?? 1) === 1;
    if ($targetIsActiveAdmin && $role !== 'admin' && $activeAdmins <= 1) return 'Cannot change the role of the last remaining active admin.';
    if ($role !== 'user' && !userHasFirstAndLastName($name)) {
        return 'Add a first and last name before giving this account the ' . $role . ' role. Review emails name the inspector.';
    }
    return null;
}

function handleUsersList(PDO $pdo): void {
    $users = db_get_all_users($pdo);
    $edit = adminEditTarget($_GET);
    $place = adminFlashPlacement(getFlash(), $edit, array_column($users, 'id'));
    $body = renderUsersPageHtml($users, db_count_submissions_by_user($pdo), generateCsrfToken(), $place['dialog'], $edit);
    adminRenderPage('Users & roles', 'users', $body, $place['top'],
        '<script src="' . hubAsset('js/table-tools.js') . '"></script><script>'
        . "WcmaTableTools.enableSearch(document.getElementById('users-search'), document.getElementById('users-table'));"
        . "WcmaTableTools.enableSort(document.getElementById('users-table'));"
        . "WcmaTableTools.enableFilter(document.getElementById('users-role-filter'), document.getElementById('users-table'), 'role');"
        . '</script>');
}

function handleUserSave(PDO $pdo, int $id): void {
    $target = db_find_user_by_id($pdo, $id);
    if ($target === null) {
        setFlash('Choose a valid user.', 'error');
        adminRedirect('admin.php?action=users');
    }
    $name = adminNormalizeName((string)($_POST['name'] ?? ''));
    $role = (string)($_POST['role'] ?? '');
    $error = adminUserSaveError($target, $name, $role, db_count_active_admins($pdo));
    if ($error !== null) {
        setFlash($error, 'error');
        adminRedirect('admin.php?action=users&edit=' . $id);
    }
    $pdo->beginTransaction();
    if ($name !== (string)$target['name']) db_set_user_name($pdo, $id, $name);
    db_set_user_role($pdo, $id, $role);
    db_set_user_media($pdo, $id, ($_POST['is_media'] ?? '') === '1');
    $pdo->commit();
    setFlash('Saved ' . $name . '. Role and media changes apply the next time they sign in.', 'success');
    adminRedirect('admin.php?action=users');
}

function handleSetActive(PDO $pdo, int $id, bool $active): void {
    if (!$active && db_count_active_admins($pdo) <= 1) {
        $target = db_find_user_by_id($pdo, $id);
        if ($target && $target['role'] === 'admin') {
            setFlash('Cannot deactivate the last remaining active admin.', 'error');
            adminRedirect('admin.php?action=users&edit=' . $id);
        }
    }
    db_set_user_active($pdo, $id, $active);
    setFlash($active ? 'User reactivated.' : 'User deactivated.', 'success');
    adminRedirect('admin.php?action=users');
}

/** Role, media and missing-name chips for one user. */
function adminUserChips(array $u): string {
    $role = (string)$u['role'];
    $out = $role === 'admin' ? adminChip('Admin', 'ink') : ($role === 'inspector' ? adminChip('Inspector', 'info') : '<span>User</span>');
    if ((int)($u['is_media'] ?? 0) === 1) $out .= adminChip('Media', 'pending');
    if ($role !== 'user' && !userHasFirstAndLastName((string)$u['name'])) $out .= adminChip('Needs first & last name', 'fail');
    return '<span class="admin-chips">' . $out . '</span>';
}

/** The Edit modal for one user: name, role, media in one form; Deactivate/Reactivate below it. Pure. */
function adminUserDialogHtml(array $u, string $csrf, ?array $flash): string {
    $id = (int)$u['id'];
    $f = 'user-' . $id;
    $hidden = adminCsrfField($csrf) . '<input type="hidden" name="id" value="' . $id . '">';
    $roles = '';
    foreach (array_keys(ROLE_LEVELS) as $r) {
        $roles .= '<label><input type="radio" name="role" value="' . h($r) . '"' . ($u['role'] === $r ? ' checked' : '') . '> ' . h(ucfirst($r)) . '</label>';
    }
    $form = '<form method="post" action="admin.php?action=user-save" class="admin-form">' . $hidden
        . adminField($f . '-name', 'Name', '<input type="text" id="' . $f . '-name" name="name" value="' . h((string)$u['name']) . '" maxlength="100" required>', true)
        . '<fieldset class="radio-row admin-form-wide"><legend>Role</legend>' . $roles . '</fieldset>'
        . '<p class="form-hint admin-form-wide">Inspectors and admins need a first and last name. Role changes apply the next time they sign in.</p>'
        . '<label class="admin-form-wide"><input type="checkbox" name="is_media" value="1"' . ((int)($u['is_media'] ?? 0) === 1 ? ' checked' : '') . '> Media staff (can use the Media section)</label>'
        . adminDialogActions('Save')
        . '</form>';
    $danger = (int)$u['active'] === 1
        ? adminDangerHtml('Deactivate this account', 'They can\'t sign in until an admin reactivates the account.',
            '<form method="post" action="admin.php?action=deactivate" data-confirm="Deactivate ' . h((string)$u['email']) . '? They won\'t be able to sign in until reactivated.">'
            . $hidden . '<button type="submit" class="btn btn-secondary">Deactivate</button></form>')
        : adminDangerHtml('Reactivate this account', 'They can sign in again straight away.',
            '<form method="post" action="admin.php?action=activate">' . $hidden . '<button type="submit" class="btn btn-secondary">Reactivate</button></form>');
    $label = (string)$u['name'] !== '' ? (string)$u['name'] : (string)$u['email'];
    return adminDialogHtml('user-dialog-' . $id, 'Edit ' . $label, $form . $danger, $flash, (string)$u['email']);
}

/** The Users & roles page body: toolbar, read-only table, then one modal per user. Pure. */
function renderUsersPageHtml(array $users, array $counts, string $csrf, ?array $dialogFlash, ?string $edit): string {
    $out = '';
    if ($users) {
        $out .= '<div class="admin-toolbar"><div class="admin-toolbar-filters">'
            . '<input type="search" id="users-search" class="table-search" placeholder="Search by name or email" aria-label="Search users">'
            . '<select id="users-role-filter" class="table-filter" aria-label="Filter by role"><option value="">All roles</option>'
            . '<option value="admin">Admin</option><option value="inspector">Inspector</option><option value="user">User</option></select>'
            . '</div></div>';
    }
    $out .= '<table class="data-table admin-table" id="users-table"><thead><tr>'
        . '<th data-sort data-sort-type="text">Name</th><th data-sort data-sort-type="text">Role</th><th>Sign-in</th>'
        . '<th data-sort data-sort-type="number">Submissions</th><th data-sort data-sort-type="text">Status</th>'
        . '<th data-sort data-sort-type="date">Joined</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
    $dialogs = '';
    foreach ($users as $u) {
        $id = (int)$u['id'];
        $name = (string)$u['name'] !== '' ? (string)$u['name'] : (string)$u['email'];
        $signIn = implode(' · ', array_filter([$u['password_hash'] ? 'Password' : '', $u['google_id'] ? 'Google' : '']));
        $out .= '<tr id="user-' . $id . '" data-role="' . h((string)$u['role']) . '">'
            . '<td data-label="Name" data-sort-value="' . h(mb_strtolower($name, 'UTF-8')) . '"><div><strong>' . h($name) . '</strong>'
            . '<span class="admin-sub">' . h((string)$u['email']) . '</span></div></td>'
            . '<td data-label="Role">' . adminUserChips($u) . '</td>'
            . '<td data-label="Sign-in">' . h($signIn !== '' ? $signIn : '—') . '</td>'
            . '<td data-label="Submissions">' . (int)($counts[$id] ?? 0) . '</td>'
            . '<td data-label="Status">' . ((int)$u['active'] === 1 ? adminChip('Active', 'ok') : adminChip('Inactive', 'fail')) . '</td>'
            . '<td data-label="Joined" data-sort-value="' . h((string)$u['created_at']) . '">' . h(date('M j, Y', strtotime((string)$u['created_at']))) . '</td>'
            . '<td class="admin-cell-actions">' . adminEditButton('user-dialog-' . $id, $name) . '</td></tr>';
        $dialogs .= adminUserDialogHtml($u, $csrf, $edit === (string)$id ? $dialogFlash : null);
    }
    $out .= '</tbody></table><p class="no-results-message" hidden>No users match your search.</p>';
    return $out . '<div class="admin-dialogs">' . $dialogs . '</div>';
}
