<?php
require __DIR__ . '/session_bootstrap.php';
date_default_timezone_set('America/Denver');

require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/feedback-lib.php';
require __DIR__ . '/admin-feedback.php';
require __DIR__ . '/admin-tech-sheets.php';
require __DIR__ . '/tech-sheet-files.php';
require __DIR__ . '/tech-review-lib.php';
require __DIR__ . '/photo-requirements.php';
require __DIR__ . '/inspection-lib.php';
require __DIR__ . '/pretech-lib.php';
require __DIR__ . '/pretech-email.php';
require __DIR__ . '/gear-lib.php';
require __DIR__ . '/gear-email.php';
require __DIR__ . '/gear-chips.php';
require __DIR__ . '/admin-gear.php';
require __DIR__ . '/tech-sheet-render.php';
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/email-helpers.php';
require __DIR__ . '/submission-email-render.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Configuration ─────────────────────────────────────────────────────────────
define('ADMIN_PAGE_SIZE', 50);

$pdo = db_connect();
db_init($pdo);

define('TECH_EMAIL', db_get_setting($pdo, 'tech_sheet_recipient_email', config_default('TECH_SHEET_RECIPIENT_EMAIL', 'classing@wcma.ca')));
define('TECH_NAME',  db_get_setting($pdo, 'tech_sheet_recipient_name', config_default('TECH_SHEET_RECIPIENT_NAME', 'WCMA Classing')));

// ── Auth helpers ──────────────────────────────────────────────────────────────
function requireAuth(): void {
    if (current_user() === null) {
        header('Location: auth.php?action=login');
        exit;
    }
    if (!is_admin()) {
        setFlash('You are not authorized to view the admin panel.', 'error');
        header('Location: car-classing.html');
        exit;
    }
}

// ── Router ────────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? 'list';
$ip     = $_SERVER['REMOTE_ADDR'];

switch ($action) {
    case 'login':
        header('Location: auth.php?action=login');
        exit;

    case 'logout':
        header('Location: auth.php?action=logout');
        exit;

    case 'list':
        requireAuth();
        handleList($pdo);
        break;

    case 'view':
        requireAuth();
        handleView($pdo, (int)($_GET['id'] ?? 0));
        break;

    case 'file':
        requireAuth();
        handleFile($pdo, (int)($_GET['id'] ?? 0), $_GET['field'] ?? '');
        break;

    case 'resend':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleResend($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'update-contact':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleUpdateContact($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'delete':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleDelete($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'bulk-delete':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleBulkDelete($pdo, array_map('intval', $_POST['ids'] ?? []));
        break;

    case 'export':
        requireAuth();
        handleExport($pdo, $_GET['sort'] ?? 'submitted_at', $_GET['dir'] ?? 'desc');
        break;

    case 'users':
        requireAuth();
        handleUsersList($pdo);
        break;

    case 'promote':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=users'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSetRole($pdo, (int)($_POST['id'] ?? 0), 'admin');
        break;

    case 'demote':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=users'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSetRole($pdo, (int)($_POST['id'] ?? 0), 'user');
        break;

    case 'deactivate':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=users'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSetActive($pdo, (int)($_POST['id'] ?? 0), false);
        break;

    case 'activate':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=users'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSetActive($pdo, (int)($_POST['id'] ?? 0), true);
        break;

    case 'events':
        requireAuth();
        handleEventsList($pdo);
        break;

    case 'event-create':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=events'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleEventCreate($pdo);
        break;

    case 'event-update':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=events'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleEventUpdate($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'event-deactivate':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=events'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleEventSetActive($pdo, (int)($_POST['id'] ?? 0), false);
        break;

    case 'event-activate':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=events'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleEventSetActive($pdo, (int)($_POST['id'] ?? 0), true);
        break;

    case 'tech-sheets':
        requireAuth();
        handleTechSheetsList($pdo);
        break;

    case 'tech-sheet':
        requireAuth();
        handleTechSheetView($pdo, (int)($_GET['id'] ?? 0));
        break;

    case 'tech-sheet-accept':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=tech-sheets'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleTechSheetAccept($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'tech-sheet-revoke':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=tech-sheets'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleTechSheetRevoke($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'tech-sheet-sig':
        requireAuth();
        handleTechSheetSig($pdo, (int)($_GET['id'] ?? 0), (string)($_GET['which'] ?? ''));
        break;

    case 'tech-sheet-photos-accept':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=tech-sheets'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleTechSheetPhotosAccept($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'tech-sheet-photos-send-back':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=tech-sheets'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleTechSheetPhotosSendBack($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'gear':
        requireAuth();
        handleGearAdminList($pdo);
        break;

    case 'gear-record':
        requireAuth();
        handleGearAdminView($pdo, is_scalar($_GET['id'] ?? null) ? (int)$_GET['id'] : 0);
        break;

    case 'gear-record-accept':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=gear'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleGearAdminAcceptInPerson($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'gear-record-revoke':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=gear'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleGearAdminRevoke($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'gear-photos-accept':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=gear'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleGearAdminPhotosAccept($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'gear-photos-send-back':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=gear'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleGearAdminPhotosSendBack($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'settings':
        requireAuth();
        handleSettings($pdo);
        break;

    case 'settings-update':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=settings'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSettingsUpdate($pdo);
        break;

    case 'feedback':
        requireAuth();
        handleFeedbackList($pdo);
        break;

    case 'feedback-view':
        requireAuth();
        handleFeedbackView($pdo, (int)($_GET['id'] ?? 0));
        break;

    case 'feedback-status':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=feedback'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleFeedbackStatus($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'feedback-retry':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=feedback'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleFeedbackRetry($pdo, (int)($_POST['id'] ?? 0));
        break;

    default:
        requireAuth();
        handleList($pdo);
}

function handleList(PDO $pdo): void {
    $sort = $_GET['sort'] ?? 'submitted_at';
    $dir  = $_GET['dir']  ?? 'desc';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $total = db_count_submissions($pdo);
    $totalPages = max(1, (int)ceil($total / ADMIN_PAGE_SIZE));
    $page = min($page, $totalPages);
    $submissions = db_get_submissions($pdo, $sort, $dir, ADMIN_PAGE_SIZE, ($page - 1) * ADMIN_PAGE_SIZE);
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderListPage($submissions, $sort, $dir, $csrf, $flash, $page, $totalPages, $total);
}

function renderListPage(array $submissions, string $sort, string $dir, string $csrf, ?array $flash, int $page, int $totalPages, int $total): void {
    $flip = $dir === 'asc' ? 'desc' : 'asc';

    function sortLink(string $col, string $label, string $currentSort, string $currentDir, string $flip): string {
        $arrow = ($currentSort === $col) ? ($currentDir === 'asc' ? ' ▲' : ' ▼') : '';
        $nextDir = ($currentSort === $col) ? $flip : 'asc';
        $url = h('admin.php?sort=' . $col . '&dir=' . $nextDir);
        return "<a href=\"{$url}\" style=\"color:inherit;text-decoration:none;\">" . h($label) . $arrow . "</a>";
    }
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Submissions — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('WCMA Submissions', renderAdminNav('submissions') . renderCommonNav('admin')); ?>
  <?php if ($flash): ?>
  <div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <p class="list-summary"><?= (int)$total ?> submission<?= $total === 1 ? '' : 's' ?> total<?= $totalPages > 1 ? ' — page ' . $page . ' of ' . $totalPages : '' ?></p>
  <?php if (!empty($submissions)): ?>
  <div class="list-toolbar">
    <input type="search" id="submissions-search" class="table-search" placeholder="Search submissions…" aria-label="Search submissions">
    <select id="submissions-class-filter" class="table-filter" aria-label="Filter by class">
      <option value="">All classes</option>
      <?php foreach (['GTU','GT1','GT2','GT3','GT4','IT1','IT2'] as $cls): ?>
      <option value="<?= h($cls) ?>"><?= h($cls) ?></option>
      <?php endforeach; ?>
    </select>
    <select id="submissions-status-filter" class="table-filter" aria-label="Filter by email status">
      <option value="">All statuses</option>
      <option value="sent">Email sent</option>
      <option value="failed">Email failed</option>
    </select>
    <form method="post" action="admin.php?action=bulk-delete" id="bulk-delete-form" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <button type="submit" id="bulk-delete-btn" class="btn btn-danger" disabled data-confirm-template="Permanently delete {n} selected submission(s) and their files?">Delete Selected</button>
    </form>
    <a href="admin.php?action=export&sort=<?= h($sort) ?>&dir=<?= h($dir) ?>" class="btn btn-secondary">Export CSV</a>
  </div>
  <?php endif; ?>
  <table class="data-table" id="submissions-table">
    <thead>
      <tr>
        <th><input type="checkbox" id="submissions-select-all" aria-label="Select all submissions"></th>
        <th><?= sortLink('submitted_at', 'Submitted', $sort, $dir, $flip) ?></th>
        <th><?= sortLink('name', 'Name', $sort, $dir, $flip) ?></th>
        <th>Vehicle</th>
        <th><?= sortLink('competition_weight', 'Weight', $sort, $dir, $flip) ?></th>
        <th><?= sortLink('declared_hp', 'HP', $sort, $dir, $flip) ?></th>
        <th><?= sortLink('calculated_class', 'Class', $sort, $dir, $flip) ?></th>
        <th>Email</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($submissions)): ?>
      <tr><td colspan="9" class="empty">No submissions yet.</td></tr>
    <?php else: foreach ($submissions as $s): ?>
      <tr data-class="<?= h($s['calculated_class'] ?? '') ?>" data-status="<?= $s['email_sent'] ? 'sent' : 'failed' ?>">
        <td><input type="checkbox" class="submission-select" form="bulk-delete-form" name="ids[]" value="<?= (int)$s['id'] ?>" aria-label="Select submission from <?= h($s['name']) ?>"></td>
        <td><?= h(date('M j, Y H:i', strtotime($s['submitted_at']))) ?></td>
        <td><?= h($s['name']) ?></td>
        <td><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></td>
        <td><?= h((string)$s['competition_weight']) ?></td>
        <td><?= h((string)$s['declared_hp']) ?></td>
        <td><strong><?= h($s['calculated_class'] ?? '—') ?></strong></td>
        <td class="<?= $s['email_sent'] ? 'badge-ok' : 'badge-fail' ?>" title="<?= $s['email_sent'] ? 'Email sent' : 'Email failed to send' ?>">
          <span aria-hidden="true"><?= $s['email_sent'] ? '✓' : '⚠' ?></span>
          <span class="sr-only"><?= $s['email_sent'] ? 'Sent' : 'Failed' ?></span>
        </td>
        <td class="actions">
          <a href="admin.php?action=view&id=<?= (int)$s['id'] ?>">View</a>
          <form method="post" action="admin.php?action=delete" style="display:inline"
                data-confirm="Permanently delete this submission and its files?">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="link-button">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <p class="no-results-message" hidden>No submissions match your search.</p>
  <?php if ($totalPages > 1): ?>
  <nav class="pagination" aria-label="Submissions pages">
    <?php if ($page > 1): ?><a href="<?= h('admin.php?sort=' . $sort . '&dir=' . $dir . '&page=' . ($page - 1)) ?>">← Prev</a><?php endif; ?>
    <span>Page <?= (int)$page ?> of <?= (int)$totalPages ?></span>
    <?php if ($page < $totalPages): ?><a href="<?= h('admin.php?sort=' . $sort . '&dir=' . $dir . '&page=' . ($page + 1)) ?>">Next →</a><?php endif; ?>
  </nav>
  <?php endif; ?>
</div>
<script src="js/table-tools.js"></script>
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
<script>
  WcmaTableTools.enableSearch(document.getElementById('submissions-search'), document.getElementById('submissions-table'));
  WcmaTableTools.enableFilter(document.getElementById('submissions-class-filter'), document.getElementById('submissions-table'), 'class');
  WcmaTableTools.enableFilter(document.getElementById('submissions-status-filter'), document.getElementById('submissions-table'), 'status');
  WcmaTableTools.enableBulkSelect(document.getElementById('submissions-select-all'), document.getElementById('submissions-table'), document.getElementById('bulk-delete-btn'));
</script>
</body>
</html><?php
}
function handleView(PDO $pdo, int $id): void {
    $sub = db_get_submission($pdo, $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: admin.php');
        exit;
    }
    $linkedUser = $sub['user_id'] ? db_find_user_by_id($pdo, (int)$sub['user_id']) : null;
    $csrf  = generateCsrfToken();
    $flash = getFlash();
    renderDetailPage($sub, $linkedUser, $csrf, $flash);
}

function classForRatio(float $ratio): ?array {
    // Class ranges, mirrored from js/calculator.js determineClass() — used only
    // to annotate the admin breakdown, not to recompute stored results. Kept as
    // a local static (not a file-scope const) because a top-level const isn't
    // hoisted like a function declaration — it only becomes defined once
    // execution reaches this line, which is after the router's switch above,
    // and the switch is exactly what calls into this function.
    static $ranges = [
        ['GTU', -INF, 6.00],
        ['GT1', 6.00, 8.00],
        ['GT2', 8.00, 10.00],
        ['GT3', 10.00, 12.00],
        ['GT4', 12.00, 14.00],
        ['IT1', 14.00, 18.00],
        ['IT2', 18.00, INF],
    ];
    if ($ratio <= 0) return null;
    foreach ($ranges as $range) {
        [$name, $min, $max] = $range;
        if ($ratio >= $min && $ratio < $max) return $range;
    }
    return null;
}

function formatClassRange(array $range): string {
    [$name, $min, $max] = $range;
    $minStr = $min === -INF ? '< ' . number_format($max, 2) : number_format($min, 2);
    $maxStr = $max === INF ? '+' : ' – ' . number_format($max - 0.01, 2);
    return $min === -INF ? "{$name} ({$minStr})" : "{$name} ({$minStr}{$maxStr})";
}

function renderDetailPage(array $s, ?array $linkedUser, string $csrf, ?array $flash): void {
    $brake_list = [];
    $brake_raw = json_decode($s['brake_suspension'] ?? '[]', true);
    if (is_array($brake_raw)) $brake_list = $brake_raw;

    $weight = (float)$s['competition_weight'];
    $hp     = (float)$s['declared_hp'];
    $baseRatio = (float)$s['base_ratio'];
    $baseClassRange = classForRatio($baseRatio);

    function modRow(string $label, ?string $display, float $value): string {
        if (!$display && $value == 0) return '';
        $sign = $value >= 0 ? '+' : '';
        $disp = $display ? h($display) : '—';
        return "<tr><td>{$label}</td><td style='text-align:right;font-family:monospace'>{$sign}" . number_format($value, 2) . "</td><td style='color:#666;font-size:.85rem'>{$disp}</td></tr>";
    }
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Submission #<?= (int)$s['id'] ?> — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Submission #' . $s['id'] . ' — ' . $s['name'], '<a href="admin.php">← Back to list</a>' . renderCommonNav('admin')); ?>
  <div class="detail-layout">
  <?php if ($flash): ?>
  <div class="form-messages show <?= h($flash['type']) ?>" style="grid-column:1/-1"><?= h($flash['message']) ?></div>
  <?php endif; ?>

  <!-- LEFT: Calculation + details -->
  <div>
    <div class="detail-card">
      <h2>Calculation Breakdown</h2>
      <table class="calc-table">
        <tr><td>Base Ratio</td>
            <td style="text-align:right"><?= number_format($baseRatio, 2) ?></td>
            <td style="color:#666;font-size:.85rem">
              <?= number_format($weight, 0) ?> lbs ÷ <?= number_format($hp, 0) ?> hp
              <?php if ($baseClassRange): ?> → <?= h(formatClassRange($baseClassRange)) ?><?php endif; ?>
            </td></tr>
        <tr><td>Weight Factor</td>
            <td style="text-align:right"><?= ($s['weight_factor'] >= 0 ? '+' : '') . number_format((float)$s['weight_factor'], 2) ?></td>
            <td style="color:#666;font-size:.85rem">at <?= number_format($weight, 0) ?> lbs</td></tr>
        <?= modRow('Chassis', $s['chassis_display'], (float)$s['chassis_value']) ?>
        <?= modRow('Body Mods', $s['body_mods_display'], (float)$s['body_mods_value']) ?>
        <?= modRow('Transmission', $s['transmission_display'], (float)$s['transmission_value']) ?>
        <?= modRow('Drivetrain', $s['drivetrain_display'], (float)$s['drivetrain_value']) ?>
        <?= modRow('Tires', $s['tires_display'], (float)$s['tires_value']) ?>
        <?php if ((float)$s['brake_suspension_value'] != 0): ?>
        <tr><td>Brake &amp; Susp.</td>
            <td style="text-align:right;font-family:monospace"><?= ($s['brake_suspension_value'] >= 0 ? '+' : '') . number_format((float)$s['brake_suspension_value'], 2) ?></td>
            <td style="color:#666;font-size:.85rem"><?= h(implode(', ', $brake_list)) ?></td></tr>
        <?php endif; ?>
        <tr class="total">
          <td>Modified Ratio</td>
          <td style="text-align:right"><?= number_format((float)$s['modified_ratio'], 2) ?></td>
          <td class="class-badge"><?= h($s['calculated_class'] ?? '—') ?></td>
        </tr>
      </table>
    </div>

    <div class="detail-card">
      <h2>Contact &amp; Vehicle <button type="button" class="link-button no-print" id="edit-contact-toggle">Edit</button></h2>
      <table class="detail-table" id="contact-view">
        <tr><td>Name</td><td><?= h($s['name']) ?></td></tr>
        <tr><td>Email</td><td><?= h($s['email']) ?></td></tr>
        <?php if ($linkedUser): ?>
        <tr><td>Account</td><td><a href="admin.php?action=users#user-<?= (int)$linkedUser['id'] ?>"><?= h($linkedUser['name']) ?> (<?= h($linkedUser['email']) ?>)</a></td></tr>
        <?php endif; ?>
        <tr><td>Vehicle</td><td><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></td></tr>
        <?php if ($s['comments']): ?><tr><td>Comments</td><td><?= nl2br(h($s['comments'])) ?></td></tr><?php endif; ?>
        <tr><td>Weight</td><td><?= h((string)$s['competition_weight']) ?> lbs</td></tr>
        <tr><td>Declared HP</td><td><?= h((string)$s['declared_hp']) ?></td></tr>
        <?php if ($s['dyno_hp']): ?><tr><td>Dyno HP</td><td><?= h((string)$s['dyno_hp']) ?></td></tr><?php endif; ?>
        <tr><td>Submitted</td><td><?= h(date('F j, Y \a\t g:i A', strtotime($s['submitted_at']))) ?></td></tr>
        <tr><td>Email Sent</td><td><?= $s['email_sent'] ? '✓ Yes' : '⚠ Failed' ?></td></tr>
      </table>
      <form method="post" action="admin.php?action=update-contact" id="contact-edit" class="edit-form" hidden>
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <label for="edit-name">Name</label>
        <input type="text" id="edit-name" name="name" value="<?= h($s['name']) ?>" required>
        <label for="edit-email">Email</label>
        <input type="email" id="edit-email" name="email" value="<?= h($s['email']) ?>" required>
        <label for="edit-year">Year</label>
        <input type="text" id="edit-year" name="year" value="<?= h($s['year']) ?>">
        <label for="edit-make">Make</label>
        <input type="text" id="edit-make" name="make" value="<?= h($s['make']) ?>">
        <label for="edit-model">Model</label>
        <input type="text" id="edit-model" name="model" value="<?= h($s['model']) ?>">
        <label for="edit-comments">Comments</label>
        <textarea id="edit-comments" name="comments" rows="3"><?= h($s['comments'] ?? '') ?></textarea>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Save</button>
          <button type="button" class="btn btn-secondary" id="edit-contact-cancel">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <!-- RIGHT: Files + actions -->
  <div>
    <div class="detail-card" style="margin-bottom:1.5rem">
      <h2>Actions</h2>
      <div class="actions">
        <form method="post" action="admin.php?action=resend" style="display:inline"
              data-confirm="Re-send the tech sheet email to <?= h($s['name']) ?> (<?= h($s['email']) ?>) and the admin address?">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <button type="submit" class="btn btn-primary">Re-email Tech Sheet</button>
        </form>
        <button type="button" class="btn btn-secondary no-print" onclick="window.print()">Print</button>
      </div>
      <?php if ($s['email_send_count'] > 0): ?>
      <p class="email-history">Last emailed <?= h(date('M j, Y \a\t g:i A', strtotime($s['last_emailed_at']))) ?> · sent <?= (int)$s['email_send_count'] ?> time<?= $s['email_send_count'] === 1 ? '' : 's' ?></p>
      <?php else: ?>
      <p class="email-history">Never emailed.</p>
      <?php endif; ?>
    </div>

    <div class="detail-card">
      <h2>Uploaded Files</h2>
      <?php
      $files = [
          'car_image'   => ['label' => 'Car Image',   'field' => 'car_image',   'path' => $s['car_image_path']],
          'dyno_chart'  => ['label' => 'Dyno Chart',  'field' => 'dyno_chart',  'path' => $s['dyno_chart_path']],
          'dyno_table'  => ['label' => 'Dyno Table',  'field' => 'dyno_table',  'path' => $s['dyno_table_path']],
      ];
      $any = false;
      foreach ($files as $f):
          if (!$f['path']) continue;
          $any = true;
          $ext = strtolower(pathinfo($f['path'], PATHINFO_EXTENSION));
          $is_image = in_array($ext, ['jpg', 'jpeg', 'png']);
          $url = h('admin.php?action=file&id=' . (int)$s['id'] . '&field=' . $f['field']);
      ?>
      <p style="font-weight:bold;margin:.8rem 0 .2rem"><?= h($f['label']) ?></p>
      <?php if ($is_image): ?>
        <img src="<?= $url ?>" class="file-thumb" data-lightbox alt="<?= h($f['label']) ?>">
      <?php else: ?>
        <a href="<?= $url ?>" target="_blank" class="file-link">Open <?= h(basename($f['path'])) ?></a>
      <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$any): ?><p style="color:#888;font-size:.9rem">No files uploaded.</p><?php endif; ?>
    </div>
  </div>
  </div>
</div>
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
<script src="js/lightbox.js"></script>
<script>
(function () {
  var toggle = document.getElementById('edit-contact-toggle');
  var cancel = document.getElementById('edit-contact-cancel');
  var view = document.getElementById('contact-view');
  var edit = document.getElementById('contact-edit');
  if (!toggle) return;
  toggle.addEventListener('click', function () { view.hidden = true; edit.hidden = false; });
  cancel.addEventListener('click', function () { view.hidden = false; edit.hidden = true; });
})();
</script>
</body>
</html><?php
}

function handleUsersList(PDO $pdo): void {
    $users = db_get_all_users($pdo);
    $submissionCounts = db_count_submissions_by_user($pdo);
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderUsersPage($users, $submissionCounts, $csrf, $flash);
}

function handleSetRole(PDO $pdo, int $id, string $role): void {
    if ($role === 'user' && db_count_admins($pdo) <= 1) {
        $target = db_find_user_by_id($pdo, $id);
        if ($target && $target['role'] === 'admin') {
            setFlash('Cannot demote the last remaining admin.', 'error');
            header('Location: admin.php?action=users');
            exit;
        }
    }

    db_set_user_role($pdo, $id, $role);
    setFlash('User role updated.', 'success');
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
<title>Manage Users — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
<style>
  .btn-role { background: none; border: 1px solid var(--secondary-color); color: var(--secondary-color); border-radius: var(--border-radius); padding: .3rem .7rem; cursor: pointer; font-size: .8rem; font-family: inherit; }
  .btn-role:hover { background: #f0f7ff; }
</style>
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Manage Users', renderAdminNav('users') . renderCommonNav('admin')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <?php if (!empty($users)): ?>
  <div class="list-toolbar">
    <input type="search" id="users-search" class="table-search" placeholder="Search users…" aria-label="Search users">
    <select id="users-role-filter" class="table-filter" aria-label="Filter by role">
      <option value="">All roles</option>
      <option value="admin">Admin</option>
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
        <td><?= h($u['name']) ?></td>
        <td class="<?= $u['role'] === 'admin' ? 'badge-admin' : '' ?>"><?= h($u['role']) ?></td>
        <td><?= h(trim(($u['password_hash'] ? 'Password ' : '') . ($u['google_id'] ? 'Google' : ''))) ?></td>
        <td><?= (int)($submissionCounts[(int)$u['id']] ?? 0) ?></td>
        <td class="<?= $u['active'] ? 'badge-ok' : 'badge-fail' ?>"><?= $u['active'] ? 'Active' : 'Inactive' ?></td>
        <td data-sort-value="<?= h($u['created_at']) ?>"><?= h(date('M j, Y', strtotime($u['created_at']))) ?></td>
        <td>
          <?php if ($u['role'] === 'admin'): ?>
          <form method="post" action="admin.php?action=demote" style="display:inline" data-confirm="Remove admin access for <?= h($u['email']) ?>?">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn-role">Demote</button>
          </form>
          <?php else: ?>
          <form method="post" action="admin.php?action=promote" style="display:inline" data-confirm="Grant admin access to <?= h($u['email']) ?>?">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn-role">Promote</button>
          </form>
          <?php endif; ?>
          <?php if ($u['active']): ?>
          <form method="post" action="admin.php?action=deactivate" style="display:inline" data-confirm="Deactivate <?= h($u['email']) ?>? They won't be able to sign in until reactivated.">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn-role">Deactivate</button>
          </form>
          <?php else: ?>
          <form method="post" action="admin.php?action=activate" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn-role">Reactivate</button>
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
</body>
</html><?php
}

function handleFile(PDO $pdo, int $id, string $field): void {
    $field_map = [
        'dyno_chart' => 'dyno_chart_path',
        'dyno_table' => 'dyno_table_path',
        'car_image'  => 'car_image_path',
    ];

    if (!isset($field_map[$field])) { http_response_code(404); exit; }

    $sub = db_get_submission($pdo, $id);
    $db_field = $field_map[$field];

    if (!$sub || !$sub[$db_field]) { http_response_code(404); exit; }

    $path = __DIR__ . '/' . $sub[$db_field];
    if (!file_exists($path)) { http_response_code(404); exit; }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt'  => 'text/plain',
    ];

    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
function handleUpdateContact(PDO $pdo, int $id): void {
    $sub = db_get_submission($pdo, $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: admin.php');
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $year = trim($_POST['year'] ?? '');
    $make = trim($_POST['make'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $comments = trim($_POST['comments'] ?? '');

    if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setFlash('Name and a valid email are required.', 'error');
        header('Location: admin.php?action=view&id=' . $id);
        exit;
    }

    db_update_submission_contact($pdo, $id, [
        'name' => $name, 'email' => $email, 'year' => $year, 'make' => $make, 'model' => $model,
        'comments' => $comments !== '' ? $comments : null,
    ]);
    setFlash('Contact details updated.', 'success');
    header('Location: admin.php?action=view&id=' . $id);
    exit;
}

function handleResend(PDO $pdo, int $id): void {
    $sub = db_get_submission($pdo, $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: admin.php');
        exit;
    }

    $body_text  = renderSubmissionEmailText($sub, true);
    $subject    = 'WCMA Classing Calculator Submission — ' . $sub['name'] . ' — ' . date('M j, Y', strtotime($sub['submitted_at']));

    // Collect file attachments that still exist on disk
    $attachments = [];
    foreach (['dyno_chart_path' => 'dyno_chart', 'dyno_table_path' => 'dyno_table', 'car_image_path' => 'car_image'] as $col => $label) {
        if ($sub[$col]) {
            $path = __DIR__ . '/' . $sub[$col];
            if (file_exists($path)) {
                $attachments[] = ['path' => $path, 'name' => basename($path)];
            }
        }
    }

    $sent = false;
    try {
        // Email to admin
        $mail = buildMailer();
        $mail->addAddress(TECH_EMAIL, TECH_NAME);
        $mail->addReplyTo($sub['email'], $sub['name']);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = renderSubmissionEmailHtml($sub, emailLogoSrc($mail), true);
        $mail->AltBody = $body_text;
        foreach ($attachments as $att) { $mail->addAttachment($att['path'], $att['name']); }
        $mail->send();

        // Confirmation to submitter
        $mail2 = buildMailer();
        $mail2->addAddress($sub['email'], $sub['name']);
        $mail2->Subject = 'Your WCMA Classing Calculator Submission';
        $mail2->isHTML(true);
        $mail2->Body    = renderSubmissionEmailHtml($sub, emailLogoSrc($mail2), true);
        $mail2->AltBody = $body_text;
        foreach ($attachments as $att) { $mail2->addAttachment($att['path'], $att['name']); }
        $mail2->send();

        $sent = true;
    } catch (Exception $e) {
        error_log('Admin resend PHPMailer error: ' . $e->getMessage());
    }

    db_update_email_sent($pdo, $id, $sent ? 1 : 0);
    setFlash($sent ? 'Email re-sent successfully.' : 'Failed to re-send email. Check server logs.', $sent ? 'success' : 'error');
    header('Location: admin.php?action=view&id=' . $id);
    exit;
}
function handleDelete(PDO $pdo, int $id): void {
    $sub = db_get_submission($pdo, $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: admin.php');
        exit;
    }

    // Delete uploaded files
    $upload_dir = __DIR__ . '/uploads/' . $id;
    if (is_dir($upload_dir)) {
        foreach (glob($upload_dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($upload_dir);
    }

    db_delete_submission($pdo, $id);
    setFlash('Submission deleted.', 'success');
    header('Location: admin.php');
    exit;
}

function handleBulkDelete(PDO $pdo, array $ids): void {
    $ids = array_filter($ids, fn($id) => $id > 0);
    if (empty($ids)) {
        setFlash('No submissions selected.', 'error');
        header('Location: admin.php');
        exit;
    }

    foreach ($ids as $id) {
        $upload_dir = __DIR__ . '/uploads/' . $id;
        if (is_dir($upload_dir)) {
            foreach (glob($upload_dir . '/*') as $file) {
                unlink($file);
            }
            rmdir($upload_dir);
        }
    }

    $deleted = db_delete_submissions($pdo, $ids);
    setFlash("Deleted {$deleted} submission(s).", 'success');
    header('Location: admin.php');
    exit;
}

function csvSafe($value): string {
    $value = (string)$value;
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
        return "'" . $value;
    }
    return $value;
}

function handleExport(PDO $pdo, string $sort, string $dir): void {
    $submissions = db_get_submissions($pdo, $sort, $dir);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="wcma-submissions-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'ID', 'Submitted', 'Name', 'Email', 'Year', 'Make', 'Model',
        'Weight', 'Declared HP', 'Dyno HP', 'Base Ratio', 'Weight Factor',
        'Modification Factor', 'Modified Ratio', 'Class', 'Email Sent',
    ]);
    foreach ($submissions as $s) {
        fputcsv($out, [
            csvSafe($s['id']), csvSafe($s['submitted_at']), csvSafe($s['name']), csvSafe($s['email']), csvSafe($s['year']), csvSafe($s['make']), csvSafe($s['model']),
            csvSafe($s['competition_weight']), csvSafe($s['declared_hp']), csvSafe($s['dyno_hp']), csvSafe($s['base_ratio']), csvSafe($s['weight_factor']),
            csvSafe($s['modification_factor']), csvSafe($s['modified_ratio']), csvSafe($s['calculated_class']), csvSafe($s['email_sent'] ? 'Yes' : 'No'),
        ]);
    }
    fclose($out);
    exit;
}

function buildMailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = (SMTP_PORT === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(FROM_EMAIL, FROM_NAME);
    return $mail;
}

function handleEventsList(PDO $pdo): void {
    $events = db_get_all_events($pdo);
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderEventsPage($events, $csrf, $flash);
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

    db_create_event($pdo, $name, $date, $location !== '' ? $location : null);
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

    db_update_event($pdo, $id, $name, $date, $location !== '' ? $location : null);
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

function renderEventsPage(array $events, string $csrf, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Events — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Events', renderAdminNav('events') . renderCommonNav('admin')); ?>
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
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Add Event</button>
      </div>
    </form>
  </div>

  <table class="data-table" id="events-table">
    <thead><tr><th>Date</th><th>Name</th><th>Location</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($events)): ?>
      <tr><td colspan="5" class="empty-row">No events yet.</td></tr>
    <?php else: foreach ($events as $e): ?>
      <tr>
        <td><?= h(date('M j, Y', strtotime($e['event_date']))) ?></td>
        <td><?= h($e['name']) ?></td>
        <td><?= h($e['location'] ?? '—') ?></td>
        <td class="<?= $e['active'] ? 'badge-ok' : 'badge-fail' ?>"><?= $e['active'] ? 'Active' : 'Inactive' ?></td>
        <td class="actions">
          <?php if ($e['active']): ?>
          <form method="post" action="admin.php?action=event-deactivate" style="display:inline" data-confirm="Deactivate <?= h($e['name']) ?>? Competitors won't be able to pick it for new tech sheets.">
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
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Settings', renderAdminNav('settings') . renderCommonNav('admin')); ?>
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
</body>
</html><?php
}
