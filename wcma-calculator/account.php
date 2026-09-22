<?php
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('America/Denver');

$pdo = db_connect();
db_init($pdo);

const MY_CARS_SOFT_CAP = 20;

function requireLogin(): array {
    $user = current_user();
    if ($user === null) {
        header('Location: auth.php?action=login');
        exit;
    }
    return $user;
}

$user = requireLogin();
$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'view':
        handleAccountView($pdo, $user, (int)($_GET['id'] ?? 0));
        break;

    case 'resend':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: account.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleAccountResend($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

    case 'delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: account.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleAccountDelete($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

    case 'file':
        handleAccountFile($pdo, $user, (int)($_GET['id'] ?? 0), $_GET['field'] ?? '');
        break;

    case 'draft-save':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); exit; }
        handleDraftSave($pdo, $user);
        break;

    case 'draft-list':
        handleDraftList($pdo, $user);
        break;

    case 'draft-load':
        handleDraftLoad($pdo, $user, (int)($_GET['id'] ?? 0));
        break;

    case 'draft-delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); exit; }
        handleDraftDelete($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

    case 'list':
    default:
        handleAccountList($pdo, $user);
}

function handleAccountList(PDO $pdo, array $user): void {
    $drafts = db_get_user_drafts($pdo, $user['id']);
    $submissions = db_get_user_submissions($pdo, $user['id']);
    $totalCount = db_count_user_drafts($pdo, $user['id']) + db_count_user_submissions($pdo, $user['id']);

    $rows = [];
    foreach ($drafts as $d) {
        $rows[] = ['type' => 'draft', 'sort_key' => $d['updated_at'], 'data' => $d];
    }
    foreach ($submissions as $s) {
        $rows[] = ['type' => 'submission', 'sort_key' => $s['submitted_at'], 'data' => $s];
    }
    usort($rows, fn($a, $b) => strcmp($b['sort_key'], $a['sort_key']));

    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderAccountListPage($rows, $totalCount, $csrf, $flash);
}

function renderAccountListPage(array $rows, int $count, string $csrf, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Cars — WCMA Calculator</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
<meta name="csrf-token" content="<?= h($csrf) ?>">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('My Cars', renderCommonNav('account')); ?>
  <?php if ($flash): ?>
  <div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <?php if ($count > MY_CARS_SOFT_CAP): ?>
  <div class="form-messages show info">You have <?= (int)$count ?> saved cars — consider deleting some older ones.</div>
  <?php endif; ?>
  <?php if (!empty($rows)): ?>
  <div class="list-toolbar">
    <input type="search" id="my-cars-search" class="table-search" placeholder="Search my cars…" aria-label="Search my cars">
  </div>
  <?php endif; ?>
  <table class="data-table" id="my-cars-table">
    <thead>
      <tr>
        <th data-sort data-sort-type="text">Type</th>
        <th data-sort data-sort-type="date">Updated</th>
        <th data-sort data-sort-type="text">Vehicle</th>
        <th data-sort data-sort-type="text">Class</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="5" class="empty-row">No cars yet — save a draft or submit the calculator to get started.</td></tr>
    <?php else: ?>
      <?php foreach ($rows as $row): ?>
        <?php if ($row['type'] === 'draft'): ?>
        <?php $d = $row['data']; ?>
      <tr>
        <td><span class="badge-draft">Draft</span></td>
        <td data-sort-value="<?= h($d['updated_at']) ?>"><?= h(date('M j, Y H:i', strtotime($d['updated_at']))) ?></td>
        <td><?= h($d['label'] ?: 'Untitled') ?></td>
        <td>—</td>
        <td class="actions">
          <a href="car-classing.html?draft=<?= (int)$d['id'] ?>">Edit</a>
          <form method="post" action="account.php?action=draft-delete" style="display:inline"
                data-confirm="Delete this draft?">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button type="submit" class="link-button">Delete</button>
          </form>
        </td>
      </tr>
        <?php else: ?>
        <?php $s = $row['data']; ?>
      <tr>
        <td>Submitted</td>
        <td data-sort-value="<?= h($s['submitted_at']) ?>"><?= h(date('M j, Y H:i', strtotime($s['submitted_at']))) ?></td>
        <td><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></td>
        <td><strong><?= h($s['calculated_class'] ?? '—') ?></strong></td>
        <td class="actions">
          <a href="account.php?action=view&id=<?= (int)$s['id'] ?>">View</a>
          <form method="post" action="account.php?action=delete" style="display:inline"
                data-confirm="Permanently delete this submission and its files?">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="link-button">Delete</button>
          </form>
        </td>
      </tr>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  <p class="no-results-message" hidden>No cars match your search.</p>
</div>
<script src="js/table-tools.js"></script>
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
<script>
  WcmaTableTools.enableSearch(document.getElementById('my-cars-search'), document.getElementById('my-cars-table'));
  WcmaTableTools.enableSort(document.getElementById('my-cars-table'));
</script>
</body>
</html><?php
}

function buildAccountMailer(): PHPMailer {
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

function handleAccountView(PDO $pdo, array $user, int $id): void {
    $sub = db_get_user_submission($pdo, $user['id'], $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: account.php');
        exit;
    }
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderAccountViewPage($sub, $csrf, $flash);
}

function renderAccountViewPage(array $s, string $csrf, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Submission #<?= (int)$s['id'] ?> — My Cars</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Submission #' . $s['id'], '<a href="account.php">← Back to My Cars</a>' . renderCommonNav('account')); ?>
  <div class="detail-layout">
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>" style="grid-column:1/-1"><?= h($flash['message']) ?></div><?php endif; ?>
  <div class="detail-card">
    <h2>Vehicle &amp; Class</h2>
    <table class="detail-table">
      <tr><td>Vehicle</td><td><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></td></tr>
      <tr><td>Weight</td><td><?= h((string)$s['competition_weight']) ?> lbs</td></tr>
      <tr><td>Declared HP</td><td><?= h((string)$s['declared_hp']) ?></td></tr>
      <tr><td>Calculated Class</td><td><strong><?= h($s['calculated_class'] ?? '—') ?></strong></td></tr>
      <tr><td>Submitted</td><td><?= h(date('F j, Y \a\t g:i A', strtotime($s['submitted_at']))) ?></td></tr>
    </table>
  </div>
  <div class="detail-card">
    <h2>Actions</h2>
    <form method="post" action="account.php?action=resend">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <button type="submit" class="btn btn-primary">Resend Confirmation to My Email</button>
    </form>
    <h2 style="margin-top:1.5rem">Uploaded Files</h2>
    <?php
    $files = [
        'car_image'  => ['label' => 'Car Image',  'path' => $s['car_image_path']],
        'dyno_chart' => ['label' => 'Dyno Chart', 'path' => $s['dyno_chart_path']],
        'dyno_table' => ['label' => 'Dyno Table', 'path' => $s['dyno_table_path']],
    ];
    $any = false;
    foreach ($files as $field => $f):
        if (!$f['path']) continue;
        $any = true;
        $ext = strtolower(pathinfo($f['path'], PATHINFO_EXTENSION));
        $is_image = in_array($ext, ['jpg', 'jpeg', 'png']);
        $url = h('account.php?action=file&id=' . (int)$s['id'] . '&field=' . $field);
    ?>
    <p style="font-weight:bold;margin:.8rem 0 .2rem"><?= h($f['label']) ?></p>
    <?php if ($is_image): ?>
      <img src="<?= $url ?>" class="file-thumb" alt="<?= h($f['label']) ?>">
    <?php else: ?>
      <a href="<?= $url ?>" target="_blank"><?= h(basename($f['path'])) ?></a>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!$any): ?><p style="color:#888">No files uploaded.</p><?php endif; ?>
  </div>
  </div>
</div>
<script src="js/form-feedback.js"></script>
</body>
</html><?php
}

function handleAccountResend(PDO $pdo, array $user, int $id): void {
    $sub = db_get_user_submission($pdo, $user['id'], $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: account.php');
        exit;
    }

    $attachments = [];
    foreach (['dyno_chart_path', 'dyno_table_path', 'car_image_path'] as $col) {
        if ($sub[$col]) {
            $path = __DIR__ . '/' . $sub[$col];
            if (file_exists($path)) $attachments[] = ['path' => $path, 'name' => basename($path)];
        }
    }

    $sent = false;
    try {
        $mail = buildAccountMailer();
        $mail->addAddress($sub['email'], $sub['name']);
        $mail->Subject = 'Your WCMA Classing Calculator Submission';
        $mail->isHTML(true);
        $mail->Body = '<p>Class: <strong>' . htmlspecialchars($sub['calculated_class'] ?? '') . '</strong></p><p>Vehicle: ' . htmlspecialchars(trim($sub['year'] . ' ' . $sub['make'] . ' ' . $sub['model'])) . '</p>';
        $mail->AltBody = 'Class: ' . ($sub['calculated_class'] ?? '') . "\nVehicle: " . trim($sub['year'] . ' ' . $sub['make'] . ' ' . $sub['model']);
        foreach ($attachments as $att) $mail->addAttachment($att['path'], $att['name']);
        $mail->send();
        $sent = true;
    } catch (Exception $e) {
        error_log('Account resend error: ' . $e->getMessage());
    }

    db_update_email_sent($pdo, $id, $sent ? 1 : 0);
    setFlash($sent ? 'Confirmation re-sent to your email.' : 'Failed to send email. Please try again later.', $sent ? 'success' : 'error');
    header('Location: account.php?action=view&id=' . $id);
    exit;
}

function handleAccountDelete(PDO $pdo, array $user, int $id): void {
    $sub = db_get_user_submission($pdo, $user['id'], $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: account.php');
        exit;
    }

    $upload_dir = __DIR__ . '/uploads/' . $id;
    if (is_dir($upload_dir)) {
        foreach (glob($upload_dir . '/*') as $file) unlink($file);
        rmdir($upload_dir);
    }

    db_delete_submission($pdo, $id);
    setFlash('Submission deleted.', 'success');
    header('Location: account.php');
    exit;
}

function handleAccountFile(PDO $pdo, array $user, int $id, string $field): void {
    $field_map = ['dyno_chart' => 'dyno_chart_path', 'dyno_table' => 'dyno_table_path', 'car_image' => 'car_image_path'];
    if (!isset($field_map[$field])) { http_response_code(404); exit; }

    $sub = db_get_user_submission($pdo, $user['id'], $id);
    $db_field = $field_map[$field];
    if (!$sub || !$sub[$db_field]) { http_response_code(404); exit; }

    $path = __DIR__ . '/' . $sub[$db_field];
    if (!file_exists($path)) { http_response_code(404); exit; }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'txt' => 'text/plain'];

    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function handleDraftSave(PDO $pdo, array $user): void {
    $formDataJson = $_POST['form_data'] ?? '';
    $label = trim($_POST['label'] ?? '');

    $decoded = json_decode($formDataJson, true);
    if (!is_array($decoded) || $label === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid draft data.']);
        exit;
    }

    $id = db_upsert_draft($pdo, $user['id'], $label, $formDataJson);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

function handleDraftList(PDO $pdo, array $user): void {
    $drafts = db_get_user_drafts($pdo, $user['id']);
    $out = array_map(function (array $d): array {
        return [
            'id' => (int)$d['id'],
            'label' => $d['label'],
            'updated_at' => $d['updated_at'],
        ];
    }, $drafts);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'drafts' => $out]);
    exit;
}

function handleDraftLoad(PDO $pdo, array $user, int $id): void {
    $draft = db_get_user_draft($pdo, $user['id'], $id);

    header('Content-Type: application/json');
    if (!$draft) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Draft not found.']);
        exit;
    }

    echo json_encode(['success' => true, 'form_data' => json_decode($draft['form_data'], true)]);
    exit;
}

function handleDraftDelete(PDO $pdo, array $user, int $id): void {
    db_delete_draft($pdo, $id, $user['id']);

    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}
