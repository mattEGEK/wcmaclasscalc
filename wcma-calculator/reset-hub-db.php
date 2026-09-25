<?php
// wcma-calculator/reset-hub-db.php — CLI only. Deletes the database and uploaded files.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (!in_array('--confirm', $argv, true)) {
    fwrite(STDERR, "This permanently deletes the database and every uploaded file.\nRun again with --confirm to proceed.\n");
    exit(1);
}
require __DIR__ . '/hub-db-tools.php';
hubResetDatabase(DB_PATH, __DIR__ . '/uploads');
$pdo = db_connect();
db_init($pdo);
echo "Database reset. The account using " . BOOTSTRAP_ADMIN_EMAIL . " becomes admin when it registers.\n";
