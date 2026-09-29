<?php
// wcma-calculator/admin.php — the Admin back office (spec §5): Users & roles, Events, Season links,
// Settings and Feedback. Admins only. Inspector work (classing, tech sheets, gear) lives in inspect.php;
// old admin.php links to it are redirected by adminMovedActionUrl() (roles.php).
require __DIR__ . '/session_bootstrap.php';
date_default_timezone_set('America/Denver');

require_once __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require_once __DIR__ . '/view_helpers.php';
require __DIR__ . '/feedback-lib.php';
require __DIR__ . '/admin-feedback.php';
require __DIR__ . '/season-links-lib.php';
require_once __DIR__ . '/clubs-lib.php';
require __DIR__ . '/admin-clubs.php';
require __DIR__ . '/admin-season-links.php';
require_once __DIR__ . '/ice-rules.php';

$pdo = db_connect();
db_init($pdo);

$action = $_GET['action'] ?? 'users';
$movedTo = adminMovedActionUrl(is_string($action) ? $action : '', $_GET);
if ($movedTo !== null) { header('Location: ' . $movedTo); exit; }
if ($action === 'login' || $action === 'logout') { header('Location: auth.php?action=' . $action); exit; }
require_role('admin');

/** POST-only and CSRF-checked; otherwise back to $back. */
function adminRequirePost(string $back): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit; }
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
}

$postId = is_scalar($_POST['id'] ?? null) ? (int)$_POST['id'] : 0;

switch ($action) {
    case 'set-role':
        adminRequirePost('admin.php?action=users');
        handleSetRole($pdo, $postId, (string)($_POST['role'] ?? ''));
        break;

    case 'set-name':
        adminRequirePost('admin.php?action=users');
        handleSetName($pdo, $postId, (string)($_POST['name'] ?? ''));
        break;

    case 'set-media':
        adminRequirePost('admin.php?action=users');
        handleSetMedia($pdo, $postId, ($_POST['is_media'] ?? '') === '1');
        break;

    case 'deactivate':
        adminRequirePost('admin.php?action=users');
        handleSetActive($pdo, $postId, false);
        break;

    case 'activate':
        adminRequirePost('admin.php?action=users');
        handleSetActive($pdo, $postId, true);
        break;

    case 'events':
        handleEventsList($pdo);
        break;

    case 'event-club':
        adminRequirePost('admin.php?action=events');
        handleEventClub($pdo, $postId);
        break;

    case 'clubs':
        handleClubsList($pdo);
        break;

    case 'club-save':
        adminRequirePost('admin.php?action=clubs');
        handleClubSave($pdo);
        break;

    case 'event-create':
        adminRequirePost('admin.php?action=events');
        handleEventCreate($pdo);
        break;

    case 'event-update':
        adminRequirePost('admin.php?action=events');
        handleEventUpdate($pdo, $postId);
        break;

    case 'event-deactivate':
        adminRequirePost('admin.php?action=events');
        handleEventSetActive($pdo, $postId, false);
        break;

    case 'event-activate':
        adminRequirePost('admin.php?action=events');
        handleEventSetActive($pdo, $postId, true);
        break;

    case 'settings':
        handleSettings($pdo);
        break;

    case 'settings-update':
        adminRequirePost('admin.php?action=settings');
        handleSettingsUpdate($pdo);
        break;

    case 'feedback':
        handleFeedbackList($pdo);
        break;

    case 'feedback-view':
        handleFeedbackView($pdo, is_scalar($_GET['id'] ?? null) ? (int)$_GET['id'] : 0);
        break;

    case 'feedback-status':
        adminRequirePost('admin.php?action=feedback');
        handleFeedbackStatus($pdo, $postId);
        break;

    case 'feedback-retry':
        adminRequirePost('admin.php?action=feedback');
        handleFeedbackRetry($pdo, $postId);
        break;

    case 'season-links':
        handleSeasonLinksList($pdo);
        break;

    case 'season-link-save':
        adminRequirePost('admin.php?action=season-links');
        handleSeasonLinkSave($pdo, $postId);
        break;

    case 'season-link-delete':
        adminRequirePost('admin.php?action=season-links');
        handleSeasonLinkDelete($pdo, $postId);
        break;

    default:   // 'users'
        handleUsersList($pdo);
}

function handleUsersList(PDO $pdo): void {
    $users = db_get_all_users($pdo);
    $submissionCounts = db_count_submissions_by_user($pdo);
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderUsersPage($users, $submissionCounts, $csrf, $flash);
}

function handleSetRole(PDO $pdo, int $id, string $role): void {
    $target = db_find_user_by_id($pdo, $id);
    if ($target === null || !isset(ROLE_LEVELS[$role])) {
        setFlash('Choose a valid user and role.', 'error');
    } elseif ($target['role'] === 'admin' && $role !== 'admin' && db_count_admins($pdo) <= 1) {
        setFlash('Cannot change the role of the last remaining admin.', 'error');
    } elseif ($role !== 'user' && !userHasFirstAndLastName((string)$target['name'])) {
        setFlash('Add a first and last name for this account before giving it the ' . $role . ' role. Review emails name the inspector.', 'error');
    } else {
        db_set_user_role($pdo, $id, $role);
        setFlash('Role updated. They will see the change the next time they sign in.', 'success');
    }
    header('Location: admin.php?action=users');
    exit;
}

function handleSetName(PDO $pdo, int $id, string $name): void {
    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    if (db_find_user_by_id($pdo, $id) === null || $name === '' || mb_strlen($name, 'UTF-8') > 100) {
        setFlash('Enter a name of 100 characters or fewer.', 'error');
    } else {
        db_set_user_name($pdo, $id, $name);
        setFlash('Name updated.', 'success');
    }
    header('Location: admin.php?action=users');
    exit;
}

function handleSetMedia(PDO $pdo, int $id, bool $on): void {
    if (db_find_user_by_id($pdo, $id) === null) {
        setFlash('Choose a valid user.', 'error');
    } else {
        db_set_user_media($pdo, $id, $on);
        setFlash('Media access ' . ($on ? 'given' : 'removed') . '. They will see the change the next time they sign in.', 'success');
    }
    header('Location: admin.php?action=users');
    exit;
}

function handleSetActive(PDO $pdo, int $id, bool $active): void {
    if (!$active && db_count_active_admins($pdo) <= 1) {
        $target = db_find_user_by_id($pdo, $id);
        if ($target && $target['role'] === 'admin') {
            setFlash('Cannot deactivate the last remaining active admin.', 'error');
            header('Location: admin.php?action=users');
            exit;
        }
    }

    db_set_user_active($pdo, $id, $active);
    setFlash($active ? 'User reactivated.' : 'User deactivated.', 'success');
    header('Location: admin.php?action=users');
    exit;
}

function renderUsersPage(array $users, array $submissionCounts, string $csrf, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Users &amp; roles — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Archivo+Narrow:wght@600;700&display=swap">
<link rel="stylesheet" href="<?= hubAsset('css/calculator.css') ?>">
<link rel="stylesheet" href="<?= hubAsset('css/hub.css') ?>">
<style>
  .btn-role { background: none; border: 1px solid var(--secondary-color); color: var(--secondary-color); border-radius: var(--border-radius); padding: .3rem .7rem; cursor: pointer; font-size: .8rem; font-family: inherit; }
  .btn-role:hover { background: #f0f7ff; }
</style>
</head>
<body class="hub">
<div class="container">
  <?php renderSiteHeader('Users & roles', adminSubnavHtml('users'), 'admin'); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <?php if (!empty($users)): ?>
  <div class="list-toolbar">
    <input type="search" id="users-search" class="table-search" placeholder="Search users…" aria-label="Search users">
    <select id="users-role-filter" class="table-filter" aria-label="Filter by role">
      <option value="">All roles</option>
      <option value="admin">Admin</option>
      <option value="inspector">Inspector</option>
      <option value="user">User</option>
    </select>
  </div>
  <?php endif; ?>
  <table class="data-table" id="users-table">
    <thead><tr>
      <th data-sort data-sort-type="text">Email</th>
      <th data-sort data-sort-type="text">Name</th>
      <th data-sort data-sort-type="text">Role</th>
      <th>Login Method</th>
      <th data-sort data-sort-type="number">Submissions</th>
      <th>Status</th>
      <th data-sort data-sort-type="date">Created</th>
      <th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr id="user-<?= (int)$u['id'] ?>" data-role="<?= h($u['role']) ?>">
        <td><?= h($u['email']) ?></td>
        <td><?= h($u['name']) ?><?php if ($u['role'] !== 'user' && !userHasFirstAndLastName((string)$u['name'])): ?> <span class="badge-fail">Needs first &amp; last name</span><?php endif; ?></td>
        <td class="<?= $u['role'] === 'admin' ? 'badge-admin' : '' ?>"><?= h($u['role']) ?></td>
        <td><?= h(trim(($u['password_hash'] ? 'Password ' : '') . ($u['google_id'] ? 'Google' : ''))) ?></td>
        <td><?= (int)($submissionCounts[(int)$u['id']] ?? 0) ?></td>
        <td class="<?= $u['active'] ? 'badge-ok' : 'badge-fail' ?>"><?= $u['active'] ? 'Active' : 'Inactive' ?></td>
        <td data-sort-value="<?= h($u['created_at']) ?>"><?= h(date('M j, Y', strtotime($u['created_at']))) ?></td>
        <td>
          <form method="post" action="admin.php?action=set-role" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <label class="visually-hidden" for="role-<?= (int)$u['id'] ?>">Role for <?= h($u['email']) ?></label>
            <select id="role-<?= (int)$u['id'] ?>" name="role">
              <?php foreach (array_keys(ROLE_LEVELS) as $r): ?>
              <option value="<?= h($r) ?>"<?= $u['role'] === $r ? ' selected' : '' ?>><?= h(ucfirst($r)) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-role">Save role</button>
          </form>
          <form method="post" action="admin.php?action=set-name" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <label class="visually-hidden" for="name-<?= (int)$u['id'] ?>">Name for <?= h($u['email']) ?></label>
            <input type="text" id="name-<?= (int)$u['id'] ?>" name="name" value="<?= h($u['name']) ?>" maxlength="100" required>
            <button type="submit" class="btn-role">Save name</button>
          </form>
          <form method="post" action="admin.php?action=set-media" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <label><input type="checkbox" name="is_media" value="1"<?= (int)($u['is_media'] ?? 0) === 1 ? ' checked' : '' ?>> Media staff</label>
            <button type="submit" class="btn-role">Save media</button>
          </form>
          <?php if ($u['active']): ?>
          <form method="post" action="admin.php?action=deactivate" style="display:inline" data-confirm="Deactivate <?= h($u['email']) ?>? They won't be able to sign in until reactivated.">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn-role btn-role--secondary">Deactivate</button>
          </form>
          <?php else: ?>
          <form method="post" action="admin.php?action=activate" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn-role btn-role--secondary">Reactivate</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="no-results-message" hidden>No users match your search.</p>
</div>
<script src="js/table-tools.js"></script>
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
<script>
  WcmaTableTools.enableSearch(document.getElementById('users-search'), document.getElementById('users-table'));
  WcmaTableTools.enableSort(document.getElementById('users-table'));
  WcmaTableTools.enableFilter(document.getElementById('users-role-filter'), document.getElementById('users-table'), 'role');
</script>
<?php renderSiteFooter(); ?>
</body>
</html><?php
}

/** Sets an existing event's host club from the clubs list (clubs spec 2026-09-29 §1). */
function handleEventClub(PDO $pdo, int $id): void {
    $event = db_get_event($pdo, $id);
    if ($event === null) { setFlash('Event not found.', 'error'); header('Location: admin.php?action=events'); exit; }
    $fields = iceEventFields(['discipline' => $event['discipline'] ?? 'summer', 'host_club' => (string)($_POST['host_club'] ?? '')],
        array_column(db_get_clubs($pdo, true), 'code'));
    if (!$fields['ok']) { setFlash((string)$fields['error'], 'error'); header('Location: admin.php?action=events'); exit; }
    db_update_event($pdo, $id, (string)$event['name'], (string)$event['event_date'], $event['location'] ?? null, $fields['discipline'], $fields['club']);
    setFlash('Host club saved.', 'success');
    header('Location: admin.php?action=events');
    exit;
}

function handleEventsList(PDO $pdo): void {
    renderEventsPage(db_get_all_events($pdo), db_count_event_plans($pdo), generateCsrfToken(), getFlash(), db_get_clubs($pdo, true));
}

function handleEventCreate(PDO $pdo): void {
    $name = trim($_POST['name'] ?? '');
    $date = trim($_POST['event_date'] ?? '');
    $location = trim($_POST['location'] ?? '');

    if ($name === '' || $date === '') {
        setFlash('Event name and date are required.', 'error');
        header('Location: admin.php?action=events');
        exit;
    }

    $fields = iceEventFields($_POST, array_column(db_get_clubs($pdo, true), 'code'));
    if (!$fields['ok']) {
        setFlash((string)$fields['error'], 'error');
        header('Location: admin.php?action=events');
        exit;
    }

    db_create_event($pdo, $name, $date, $location !== '' ? $location : null, $fields['discipline'], $fields['club']);
    setFlash('Event created.', 'success');
    header('Location: admin.php?action=events');
    exit;
}

function handleEventUpdate(PDO $pdo, int $id): void {
    $name = trim($_POST['name'] ?? '');
    $date = trim($_POST['event_date'] ?? '');
    $location = trim($_POST['location'] ?? '');

    if ($name === '' || $date === '') {
        setFlash('Event name and date are required.', 'error');
        header('Location: admin.php?action=events');
        exit;
    }

    $disciplineInput = $_POST;
    if (!array_key_exists('discipline', $disciplineInput)) {
        $current = db_get_event($pdo, $id);
        if ($current !== null) {
            $disciplineInput['discipline'] = $current['discipline'] ?? 'summer';
            if (!array_key_exists('host_club', $disciplineInput)) {
                $disciplineInput['host_club'] = $current['host_club'] ?? '';
            }
        }
    }

    $fields = iceEventFields($disciplineInput, array_column(db_get_clubs($pdo, true), 'code'));
    if (!$fields['ok']) {
        setFlash((string)$fields['error'], 'error');
        header('Location: admin.php?action=events');
        exit;
    }

    db_update_event($pdo, $id, $name, $date, $location !== '' ? $location : null, $fields['discipline'], $fields['club']);
    setFlash('Event updated.', 'success');
    header('Location: admin.php?action=events');
    exit;
}

function handleEventSetActive(PDO $pdo, int $id, bool $active): void {
    db_set_event_active($pdo, $id, $active);
    setFlash($active ? 'Event reactivated.' : 'Event deactivated.', 'success');
    header('Location: admin.php?action=events');
    exit;
}

function renderEventsPage(array $events, array $going, string $csrf, ?array $flash, array $clubs = []): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Events — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Archivo+Narrow:wght@600;700&display=swap">
<link rel="stylesheet" href="<?= hubAsset('css/calculator.css') ?>">
<link rel="stylesheet" href="<?= hubAsset('css/hub.css') ?>">
</head>
<body class="hub">
<div class="container">
  <?php renderSiteHeader('Events', adminSubnavHtml('events'), 'admin'); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <div class="detail-card" style="margin-bottom:1.5rem">
    <h2>Add Event</h2>
    <form method="post" action="admin.php?action=event-create" class="edit-form">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <label for="new-event-name">Name</label>
      <input type="text" id="new-event-name" name="name" required>
      <label for="new-event-date">Date</label>
      <input type="date" id="new-event-date" name="event_date" required>
      <label for="new-event-location">Location</label>
      <input type="text" id="new-event-location" name="location">
      <fieldset class="radio-row">
        <legend>Discipline</legend>
        <label><input type="radio" name="discipline" value="summer" checked> Summer</label>
        <label><input type="radio" name="discipline" value="ice"> Ice</label>
      </fieldset>
      <label for="new-event-club">Host club</label>
      <select id="new-event-club" name="host_club">
        <option value="">—</option>
        <?php foreach ($clubs as $c): ?>
        <option value="<?= h((string)$c['code']) ?>"><?= h($c['code'] . ' — ' . $c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="form-hint">Ice events need NASCC or WSCC. Add other clubs on the <a href="admin.php?action=clubs">Clubs</a> tab.</p>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Add Event</button>
      </div>
    </form>
  </div>

  <table class="data-table" id="events-table">
    <thead><tr><th>Date</th><th>Name</th><th>Location</th><th>Going</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($events)): ?>
      <tr><td colspan="6" class="empty-row">No events yet.</td></tr>
    <?php else: foreach ($events as $e): ?>
      <tr>
        <td><?= h(date('M j, Y', strtotime($e['event_date']))) ?></td>
        <td><?= h($e['name']) ?><?php if (($e['discipline'] ?? 'summer') === 'ice'): ?> <span class="badge-pending">Ice · <?= h((string)$e['host_club']) ?></span><?php endif; ?></td>
        <td><?= h($e['location'] ?? '—') ?></td>
        <?php $n = (int)($going[(int)$e['id']] ?? 0); ?>
        <td><?= $n ?> <?= $n === 1 ? 'car' : 'cars' ?></td>
        <td class="<?= $e['active'] ? 'badge-ok' : 'badge-fail' ?>"><?= $e['active'] ? 'Active' : 'Inactive' ?></td>
        <td class="actions">
          <?php $isIceEvent = ($e['discipline'] ?? 'summer') === 'ice'; ?>
          <form method="post" action="admin.php?action=event-club" class="event-club-form">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
            <select name="host_club" aria-label="Host club for <?= h($e['name']) ?>">
              <?php if (!$isIceEvent): ?><option value="">No host club</option><?php endif; ?>
              <?php foreach ($clubs as $c): if ($isIceEvent && !in_array((string)$c['code'], iceClubCodes(), true)) continue; ?>
              <option value="<?= h((string)$c['code']) ?>"<?= (string)($e['host_club'] ?? '') === (string)$c['code'] ? ' selected' : '' ?>><?= h($c['code'] . ' — ' . $c['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="link-button">Save club</button>
          </form>
          <?php if ($e['active']): ?>
          <form method="post" action="admin.php?action=event-deactivate" style="display:inline" data-confirm="Deactivate <?= h($e['name']) ?>? Competitors won't be able to tag it or pick it for new tech sheets.">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
            <button type="submit" class="link-button">Deactivate</button>
          </form>
          <?php else: ?>
          <form method="post" action="admin.php?action=event-activate" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
            <button type="submit" class="link-button">Reactivate</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
<?php renderSiteFooter(); ?>
</body>
</html><?php
}

function handleSettings(PDO $pdo): void {
    $feedbackRecipient = feedbackRecipient($pdo);
    $values = [
        'classing_recipient_email'   => db_get_setting($pdo, 'classing_recipient_email', config_default('CLASSING_RECIPIENT_EMAIL', 'classing@wcma.ca')),
        'classing_recipient_name'    => db_get_setting($pdo, 'classing_recipient_name', config_default('CLASSING_RECIPIENT_NAME', 'WCMA Classing')),
        'tech_sheet_recipient_email' => db_get_setting($pdo, 'tech_sheet_recipient_email', config_default('TECH_SHEET_RECIPIENT_EMAIL', 'classing@wcma.ca')),
        'tech_sheet_recipient_name'  => db_get_setting($pdo, 'tech_sheet_recipient_name', config_default('TECH_SHEET_RECIPIENT_NAME', 'WCMA Classing')),
        'feedback_recipient_email'   => $feedbackRecipient['email'],
        'feedback_recipient_name'    => $feedbackRecipient['name'],
    ];
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderSettingsPage($values, $csrf, $flash);
}

function handleSettingsUpdate(PDO $pdo): void {
    $fields = [
        'classing_recipient_email'   => ['name' => 'classing_recipient_name',    'label' => 'Class calculator'],
        'tech_sheet_recipient_email' => ['name' => 'tech_sheet_recipient_name',  'label' => 'Tech sheet'],
        'feedback_recipient_email'   => ['name' => 'feedback_recipient_name',    'label' => 'Feedback'],
    ];

    $clean = [];
    foreach ($fields as $emailKey => $info) {
        $email = trim($_POST[$emailKey] ?? '');
        $name  = trim($_POST[$info['name']] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash($info['label'] . ' recipient requires a valid email address.', 'error');
            header('Location: admin.php?action=settings');
            exit;
        }
        if ($name === '') {
            setFlash($info['label'] . ' recipient requires a name.', 'error');
            header('Location: admin.php?action=settings');
            exit;
        }
        $clean[$emailKey] = $email;
        $clean[$info['name']] = $name;
    }

    foreach ($clean as $key => $value) {
        db_set_setting($pdo, $key, $value);
    }

    setFlash('Notification settings updated.', 'success');
    header('Location: admin.php?action=settings');
    exit;
}

function renderSettingsPage(array $values, string $csrf, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Settings — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Archivo+Narrow:wght@600;700&display=swap">
<link rel="stylesheet" href="<?= hubAsset('css/calculator.css') ?>">
<link rel="stylesheet" href="<?= hubAsset('css/hub.css') ?>">
</head>
<body class="hub">
<div class="container">
  <?php renderSiteHeader('Settings', adminSubnavHtml('settings'), 'admin'); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <div class="detail-card" style="margin-bottom:1.5rem">
    <h2>Notification Recipients</h2>
    <p style="color:#666;font-size:.9rem;margin-top:-.5rem">Where class-calculator submissions, tech sheet submissions and user feedback are emailed. Submitters of class calculations and tech sheets always also get their own confirmation copy.</p>
    <form method="post" action="admin.php?action=settings-update" class="edit-form">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

      <label for="classing-email">Class Calculator — Email</label>
      <input type="email" id="classing-email" name="classing_recipient_email" value="<?= h($values['classing_recipient_email']) ?>" required>
      <label for="classing-name">Class Calculator — Name</label>
      <input type="text" id="classing-name" name="classing_recipient_name" value="<?= h($values['classing_recipient_name']) ?>" required>

      <label for="tech-sheet-email">Tech Sheets — Email</label>
      <input type="email" id="tech-sheet-email" name="tech_sheet_recipient_email" value="<?= h($values['tech_sheet_recipient_email']) ?>" required>
      <label for="tech-sheet-name">Tech Sheets — Name</label>
      <input type="text" id="tech-sheet-name" name="tech_sheet_recipient_name" value="<?= h($values['tech_sheet_recipient_name']) ?>" required>
      <label for="feedback-email">Feedback — Email</label>
      <input type="email" id="feedback-email" name="feedback_recipient_email" value="<?= h($values['feedback_recipient_email']) ?>" required>
      <label for="feedback-name">Feedback — Name</label>
      <input type="text" id="feedback-name" name="feedback_recipient_name" value="<?= h($values['feedback_recipient_name']) ?>" required>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>
<script src="js/form-feedback.js"></script>
<?php renderSiteFooter(); ?>
</body>
</html><?php
}
