<?php
// wcma-calculator/account.php — the old "My Cars" URL. The Garage replaced it (spec §1). This file
// only redirects, and keeps the calculator's draft JSON endpoints: js/ui-controller.js calls
// account.php?action=draft-*, and fetches account.php (→ garage.php) for its csrf-token meta tag.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/view_helpers.php';

$pdo = db_connect();
db_init($pdo);

$user = current_user();
if ($user === null) {
    header('Location: auth.php?action=login');
    exit;
}

switch ($_GET['action'] ?? 'list') {
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

    case 'view':
        header('Location: garage.php?declaration=' . (int)($_GET['id'] ?? 0));
        exit;

    default:
        header('Location: garage.php');
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
