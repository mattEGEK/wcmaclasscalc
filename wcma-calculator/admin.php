<?php
// wcma-calculator/admin.php — the Admin back office router (spec §5; admin desktop UX spec 2026-09-29). Each tab's
// handlers and page live in admin-<tab>.php. Admins only. Inspector work (classing, tech sheets, gear) lives in
// inspect.php; old admin.php links to it are redirected by adminMovedActionUrl() (roles.php).
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
require __DIR__ . '/admin-ui.php';
require __DIR__ . '/admin-users.php';
require __DIR__ . '/admin-events.php';
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
    case 'user-save':
        adminRequirePost('admin.php?action=users');
        handleUserSave($pdo, $postId);
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
