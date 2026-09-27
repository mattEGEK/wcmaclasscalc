<?php
// wcma-calculator/media-profile.php — a driver's media profile (spec 2026-09-27 §3). Only the account
// that manages the driver profile may open it; anyone else gets a 404.
require __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/view_helpers.php';
require_once __DIR__ . '/photo-requirements.php';
require_once __DIR__ . '/inspection-lib.php';
require __DIR__ . '/media-lib.php';
require __DIR__ . '/media-service.php';
require __DIR__ . '/media-profile-page.php';

date_default_timezone_set('America/Denver');
$pdo = db_connect();
db_init($pdo);
$user = require_role('user');
$uid = (int)$user['id'];
$rawDriver = $_POST['driver_id'] ?? $_GET['driver_id'] ?? '';
$driverId = $rawDriver === 'self' ? (int)(db_get_self_driver($pdo, $uid)['id'] ?? 0) : (int)$rawDriver;
$driver = mediaOwnedDriver($pdo, $uid, $driverId);
if ($driver === null) {
    http_response_code(404);
    renderPageStart('Not found', 'drivers');
    echo '<h1>Driver not found</h1><p><a href="drivers.php">Back to Drivers</a></p>';
    renderPageEnd();
    exit;
}

$errors = [];
$input = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
    $back = 'media-profile.php?driver_id=' . $driverId;
    switch ($_POST['action'] ?? '') {
        case 'save':
            $file = $_FILES['photo'] ?? null;
            $err = is_array($file) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
            if ($err !== UPLOAD_ERR_OK && $err !== UPLOAD_ERR_NO_FILE) {
                $r = ['ok' => false, 'errors' => ['The photo could not be uploaded (2 MB maximum). Try a smaller one.']];
            } else {
                $r = mediaSaveProfile($pdo, $uid, $driverId, $_POST, $err === UPLOAD_ERR_OK ? (string)$file['tmp_name'] : null, __DIR__);
            }
            if ($r['ok']) {
                $msg = match ($r['status']) {
                    'pending_review' => 'Profile saved. WCMA media staff will review it before it goes on the public page.',
                    default => 'Profile saved.',
                };
                setFlash($msg, 'success');
                header('Location: ' . $back);
                exit;
            }
            $errors = $r['errors'];
            $input = $_POST;
            break;
        case 'withdraw':
            mediaWithdraw($pdo, $uid, $driverId);
            setFlash('Consent withdrawn. The profile is no longer used anywhere.', 'success');
            header('Location: ' . $back);
            exit;
        case 'delete':
            mediaDeleteProfile($pdo, $uid, $driverId, __DIR__);
            setFlash('Media profile deleted.', 'success');
            header('Location: drivers.php');
            exit;
    }
}

$profile = db_get_media_profile($pdo, $driverId);
$consent = db_get_latest_media_consent($pdo, $driverId);
renderPageStart('Media profile', 'drivers', ['flash' => getFlash()]);
echo renderMediaProfileHtml([
    'driver' => $driver, 'isSelf' => mediaIsSelf($driver, $uid), 'profile' => $profile,
    'sponsors' => db_get_sponsors($pdo, $driverId), 'consent' => $consent,
    'status' => mediaProfileStatus($profile, $consent), 'errors' => $errors, 'csrf' => generateCsrfToken(), 'input' => $input,
]);
renderPageEnd(['scripts' => '<script src="js/photo-resize.js"></script><script src="js/media-profile.js"></script>']);
