<?php
// wcma-calculator/seed-hub-db.php — CLI only. Fills an EMPTY database with test data. Not for production.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$password = 'password123';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--password=')) $password = substr($arg, strlen('--password='));
}
require __DIR__ . '/hub-db-tools.php';
$pdo = db_connect();
db_init($pdo);
try {
    $summary = hubSeed($pdo, $password);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
foreach ($summary as $label => $count) echo str_pad($label, 14) . $count . "\n";
echo "Accounts: " . BOOTSTRAP_ADMIN_EMAIL . " (admin), inspector@example.com, jordan@example.com. Password: {$password}\n";
