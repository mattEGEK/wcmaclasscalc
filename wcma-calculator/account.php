<?php
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/gear-lib.php';
require __DIR__ . '/gear-chips.php';
require __DIR__ . '/cars-lib.php';

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

    case 'archive-car':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: account.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        $ok = db_archive_car($pdo, (int)$user['id'], (int)($_POST['id'] ?? 0));
        setFlash($ok ? 'Car archived. Its history is kept.' : 'Car not found.', $ok ? 'success' : 'error');
        header('Location: account.php');
        exit;

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
    $cars = db_get_user_cars($pdo, (int)$user['id']);
    $totalCount = count($cars) + db_count_user_drafts($pdo, $user['id']);
    $techSheets = db_get_user_tech_sheets($pdo, $user['id']);
    $activeEvents = db_get_active_events($pdo);

    $eventNames = [];
    foreach (db_get_all_events($pdo) as $e) {
        $eventNames[(int)$e['id']] = $e['name'];
    }

    $carGroups = buildCarGroups($cars, db_get_user_current_declarations($pdo, (int)$user['id']), $techSheets, $activeEvents, $eventNames);
    $carStatuses = [];
    foreach ($techSheets as $ts) {
        $carStatuses[(int)$ts['id']] = techCarStatusForSheet($ts, $techSheets);
    }

    $ownerGear = db_get_user_gear_records($pdo, (int)$user['id']);
    $driversBySheet = db_get_drivers_for_sheets($pdo, array_map(fn(array $ts): int => (int)$ts['id'], $techSheets));
    $gearLinks = [];
    foreach ($techSheets as $ts) {
        $gearLinks[(int)$ts['id']] = gearLinksForSheet($ts, $driversBySheet[(int)$ts['id']] ?? [], $ownerGear);
    }

    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderAccountListPage($drafts, $carGroups, $totalCount, $csrf, $flash, $carStatuses, $gearLinks);
}

function renderAccountListPage(array $drafts, array $carGroups, int $count, string $csrf, ?array $flash, array $carStatuses, array $gearLinks = []): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Cars — WCMA Calculator</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Archivo+Narrow:wght@600;700&display=swap">
<link rel="stylesheet" href="css/calculator.css">
<link rel="stylesheet" href="css/hub.css">
<meta name="csrf-token" content="<?= h($csrf) ?>">
</head>
<body class="hub">
<div class="container">
  <?php renderSiteHeader('My Cars', '', 'garage'); ?>
  <?php if ($flash): ?>
  <div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <?php if ($count > MY_CARS_SOFT_CAP): ?>
  <div class="form-messages show info">You have <?= (int)$count ?> saved cars — consider deleting some older ones.</div>
  <?php endif; ?>

  <h2>My Cars</h2>
  <?php if (empty($carGroups)): ?>
  <p class="empty-row">No cars yet. <a href="car-classing.html">Declare your class</a> to add your first car.</p>
  <?php else: ?>
    <?php foreach ($carGroups as $group): $car = $group['car']; $d = $group['declaration']; ?>
    <div class="car-card">
      <div class="car-card-header">
        <span class="car-card-vehicle"><?= h(carDisplayName($car)) ?></span>
        <span class="car-card-class"><?= h($d['calculated_class'] ?? '—') ?></span>
      </div>
      <?php if ($d): ?>
      <p class="car-card-meta">Class declared <?= h(date('M j, Y', strtotime($d['submitted_at']))) ?> ·
        <span class="<?= h(declarationReviewBadgeClass($d['review_status'])) ?>"><?= h(declarationReviewLabel($d['review_status'])) ?></span></p>
      <?php else: ?>
      <p class="car-card-meta">No class declared yet.</p>
      <?php endif; ?>
      <div class="car-card-actions">
        <?php if ($d): ?><a href="account.php?action=view&id=<?= (int)$d['id'] ?>">View declaration</a><?php endif; ?>
        <a href="car-classing.html?car=<?= (int)$car['id'] ?>"><?= $d ? 'Re-declare class' : 'Declare class' ?></a>
        <form method="post" action="account.php?action=archive-car" style="display:inline"
              data-confirm="Archive <?= h(carDisplayName($car)) ?>? It will be hidden, and its history is kept.">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int)$car['id'] ?>">
          <button type="submit" class="link-button">Archive car</button>
        </form>
      </div>
      <?php if (!empty($group['lines'])): ?>
      <ul class="car-card-tech-list">
        <?php foreach ($group['lines'] as $line): $sheet = $line['sheet']; ?>
        <?php $eventLabel = h($line['event_name']) . ($line['event_date'] ? ' (' . h(date('M j', strtotime($line['event_date']))) . ')' : ''); ?>
        <li class="car-card-tech-line">
          <?php if ($sheet === null): ?>
            Tech sheet for <strong><?= $eventLabel ?></strong>: <span class="badge-pending">not submitted</span>
            <?php if ($d): ?> — <a href="tech-sheets.php?action=new&car_id=<?= (int)$car['id'] ?>">Submit now</a><?php else: ?> — declare a class first<?php endif; ?>
          <?php else: ?>
            Tech sheet (<?= h(ucfirst($sheet['sheet_type'])) ?>) for <strong><?= $eventLabel ?></strong>:
            <span class="badge-pending">submitted</span>
            <?php $cs = $carStatuses[(int)$sheet['id']] ?? ['state' => 'none', 'via' => null, 'sheet_id' => null]; ?>
            <span class="<?= h(techCarStatusBadgeClass($cs['state'])) ?>"><?= h(techCarStatusLabel($cs, (int)($sheet['season'] ?? date('Y')))) ?></span> —
            <a href="tech-sheets.php?action=view&id=<?= (int)$sheet['id'] ?>">View</a>
            <?= renderGearChips($gearLinks[(int)$sheet['id']] ?? [], 'owner', ['sheet_season' => (int)($sheet['season'] ?? 0)]) ?>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <p class="car-card-no-events">No upcoming events open for tech sheet submission yet.</p>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <h2 style="margin-top:2rem">Drafts</h2>
  <?php if (!empty($drafts)): ?>
  <div class="list-toolbar">
    <input type="search" id="my-drafts-search" class="table-search" placeholder="Search my drafts…" aria-label="Search my drafts">
  </div>
  <?php endif; ?>
  <table class="data-table" id="my-drafts-table">
    <thead>
      <tr>
        <th data-sort data-sort-type="date">Updated</th>
        <th data-sort data-sort-type="text">Vehicle</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($drafts)): ?>
      <tr><td colspan="3" class="empty-row">No drafts yet — start the calculator and save your progress to come back to it later.</td></tr>
    <?php else: ?>
      <?php foreach ($drafts as $d): ?>
      <tr>
        <td data-sort-value="<?= h($d['updated_at']) ?>"><?= h(date('M j, Y H:i', strtotime($d['updated_at']))) ?></td>
        <td><?= h($d['label'] ?: 'Untitled') ?></td>
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
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  <p class="no-results-message" hidden>No drafts match your search.</p>
</div>
<script src="js/table-tools.js"></script>
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
<script>
  WcmaTableTools.enableSearch(document.getElementById('my-drafts-search'), document.getElementById('my-drafts-table'));
  WcmaTableTools.enableSort(document.getElementById('my-drafts-table'));
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
        setFlash('Class declaration not found.', 'error');
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
<title>Class Declaration — <?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Archivo+Narrow:wght@600;700&display=swap">
<link rel="stylesheet" href="css/calculator.css">
<link rel="stylesheet" href="css/hub.css">
</head>
<body class="hub">
<div class="container">
  <?php renderSiteHeader('Class Declaration — ' . trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model']), '<a href="account.php">← Back to My Cars</a>', 'garage'); ?>
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
    <form method="post" action="account.php?action=delete" style="margin-top:1rem"
          data-confirm="Permanently delete this class declaration and its files?">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <button type="submit" class="btn btn-secondary">Delete this declaration</button>
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
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
</body>
</html><?php
}

function handleAccountResend(PDO $pdo, array $user, int $id): void {
    $sub = db_get_user_submission($pdo, $user['id'], $id);
    if (!$sub) {
        setFlash('Class declaration not found.', 'error');
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
        $mail->Subject = 'Your WCMA Class Declaration';
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
        setFlash('Class declaration not found.', 'error');
        header('Location: account.php');
        exit;
    }

    if (db_count_tech_sheets_for_submission($pdo, $id) > 0) {
        setFlash('This declaration is on a submitted tech sheet, so it cannot be deleted.', 'error');
        header('Location: account.php?action=view&id=' . $id);
        exit;
    }

    $upload_dir = __DIR__ . '/uploads/' . $id;
    if (is_dir($upload_dir)) {
        foreach (glob($upload_dir . '/*') as $file) unlink($file);
        rmdir($upload_dir);
    }

    db_delete_submission($pdo, $id);
    setFlash('Class declaration deleted.', 'success');
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
