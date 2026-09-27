<?php
// wcma-calculator/media-photo.php — streams a driver's media photo. uploads/ is Deny-from-all, so
// this is the only way in. The owner and Media staff always see it; everyone else only while the
// public page is live. Every refusal is the same bare 404.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/media-lib.php';
require __DIR__ . '/photo-requirements.php';
require __DIR__ . '/inspection-lib.php';
require __DIR__ . '/media-service.php';

$pdo = db_connect();
db_init($pdo);
$driverId = (int)($_GET['driver_id'] ?? 0);
$driver = db_get_driver($pdo, $driverId);
$profile = $driver !== null ? db_get_media_profile($pdo, $driverId) : null;
$path = (string)($profile['photo_path'] ?? '');
$abs = __DIR__ . '/' . $path;
if ($driver === null || !str_starts_with($path, MEDIA_PHOTO_DIR . '/') || !is_file($abs)
    || !mediaPhotoAllowed(current_user(), $driver, $profile, db_get_latest_media_consent($pdo, $driverId))) {
    http_response_code(404);
    exit;
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . ($types[strtolower(pathinfo($abs, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($abs));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($abs);
