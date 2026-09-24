<?php
// wcma-calculator/inspection.php — JSON/image endpoints for inspection photos.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/view_helpers.php';
require_once __DIR__ . '/photo-requirements.php';
require __DIR__ . '/inspection-lib.php';

$pdo = db_connect();
db_init($pdo);

function inspectionJson(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

/** The subject row if it exists and the user may access it, else null (never reveals which). */
function inspectionLoadSubject(PDO $pdo, array $user, string $type, int $id, bool $forWrite): ?array {
    if (!isset(INSPECTION_SUBJECT_SCOPE[$type])) return null;
    // Explicit dispatch: a type added to INSPECTION_SUBJECT_SCOPE without a branch here is a 404.
    $sheet = match ($type) {
        'tech_sheet' => db_get_tech_sheet($pdo, $id),
        default => null,
    };
    if (!$sheet || !inspectionCanAccess($user, $sheet, $forWrite)) return null;
    return $sheet;
}

$user = current_user();
if ($user === null) {
    inspectionJson(401, ['ok' => false, 'error' => 'Please sign in.']);
}

$action = $_GET['action'] ?? '';

if ($action === 'upload') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') inspectionJson(405, ['ok' => false, 'error' => 'POST required.']);
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) inspectionJson(403, ['ok' => false, 'error' => 'Invalid CSRF token.']);

    $type = (string)($_POST['subject_type'] ?? '');
    $subjectId = (int)($_POST['subject_id'] ?? 0);
    if (inspectionLoadSubject($pdo, $user, $type, $subjectId, true) === null) {
        inspectionJson(404, ['ok' => false, 'error' => 'Not found.']);
    }

    $file = $_FILES['photo'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        inspectionJson(400, ['ok' => false, 'error' => 'No photo received.']);
    }

    $typed = isset($_POST['typed']) && is_array($_POST['typed']) ? $_POST['typed'] : [];
    $result = inspectionSavePhoto($pdo, __DIR__, $type, $subjectId, (string)($_POST['requirement_key'] ?? ''), $file['tmp_name'], $typed);
    if (!$result['ok']) inspectionJson(400, ['ok' => false, 'error' => $result['error']]);

    inspectionJson(200, ['ok' => true, 'photo' => inspectionPublicPhoto($result['photo'])]);
}

if ($action === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') inspectionJson(405, ['ok' => false, 'error' => 'POST required.']);
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) inspectionJson(403, ['ok' => false, 'error' => 'Invalid CSRF token.']);

    $photo = db_get_inspection_photo($pdo, (int)($_POST['id'] ?? 0));
    if ($photo === null || inspectionLoadSubject($pdo, $user, $photo['subject_type'], (int)$photo['subject_id'], true) === null) {
        inspectionJson(404, ['ok' => false, 'error' => 'Not found.']);
    }
    inspectionDeletePhoto($pdo, __DIR__, (int)$photo['id']);
    inspectionJson(200, ['ok' => true]);
}

if ($action === 'applies') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') inspectionJson(405, ['ok' => false, 'error' => 'POST required.']);
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) inspectionJson(403, ['ok' => false, 'error' => 'Invalid CSRF token.']);

    $type = (string)($_POST['subject_type'] ?? '');
    $subjectId = (int)($_POST['subject_id'] ?? 0);
    if (inspectionLoadSubject($pdo, $user, $type, $subjectId, true) === null) {
        inspectionJson(404, ['ok' => false, 'error' => 'Not found.']);
    }

    $applies = ($_POST['applies'] ?? '') === '1';
    $result = inspectionSetApplies($pdo, __DIR__, $type, $subjectId, (string)($_POST['requirement_key'] ?? ''), $applies);
    if (!$result['ok']) inspectionJson(400, ['ok' => false, 'error' => $result['error']]);
    inspectionJson(200, ['ok' => true, 'applies' => $applies]);
}

if ($action === 'typed') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') inspectionJson(405, ['ok' => false, 'error' => 'POST required.']);
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) inspectionJson(403, ['ok' => false, 'error' => 'Invalid CSRF token.']);

    $photo = db_get_inspection_photo($pdo, (int)($_POST['id'] ?? 0));
    if ($photo === null || inspectionLoadSubject($pdo, $user, $photo['subject_type'], (int)$photo['subject_id'], true) === null) {
        inspectionJson(404, ['ok' => false, 'error' => 'Not found.']);
    }

    $typed = isset($_POST['typed']) && is_array($_POST['typed']) ? $_POST['typed'] : [];
    $result = inspectionUpdateTyped($pdo, (int)$photo['id'], $typed);
    if (!$result['ok']) inspectionJson(400, ['ok' => false, 'error' => $result['error']]);
    inspectionJson(200, ['ok' => true, 'photo' => $result['photo']]);
}

if ($action === 'photo') {
    $photo = db_get_inspection_photo($pdo, (int)($_GET['id'] ?? 0));
    if ($photo === null || $photo['file_path'] === ''
        || inspectionLoadSubject($pdo, $user, $photo['subject_type'], (int)$photo['subject_id'], false) === null) {
        http_response_code(404);
        exit;
    }
    $abs = __DIR__ . '/' . $photo['file_path'];
    if (!is_file($abs)) { http_response_code(404); exit; }

    $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($abs));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($abs);
    exit;
}

inspectionJson(400, ['ok' => false, 'error' => 'Unknown action.']);
