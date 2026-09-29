<?php
// wcma-calculator/msr-sync.php — CLI only. The daily MotorsportReg calendar check (spec
// 2026-09-29-msr-calendar-import-design.md §2). reminders-cron.sh runs it after the reminders.
//   php msr-sync.php                     check every connected club
//   php msr-sync.php --today=2026-10-04  pretend it is that day (testing)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$today = null;
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--today=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) { fwrite(STDERR, "Unknown option: $arg\n"); exit(2); }
    $today = $m[1];
}
date_default_timezone_set('America/Denver');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/msr-lib.php';

$pdo = db_connect();
db_init($pdo);
$results = msrSyncAll($pdo, 'msrHttpGet', $today ?? date('Y-m-d'), date('Y-m-d H:i:s'));
$failed = 0;
foreach ($results as $r) {
    echo date('Y-m-d H:i:s') . ' ' . $r['code'] . ': ' . ($r['ok'] ? $r['found'] . ' race event' . ($r['found'] === 1 ? '' : 's') . ($r['skipped'] ? ', ' . $r['skipped'] . ' entries skipped' : '') : 'FAILED ' . $r['error']) . "\n";
    if (!$r['ok']) $failed++;
}
if (!$results) echo date('Y-m-d H:i:s') . " no clubs are connected to MotorsportReg\n";
exit($failed > 0 ? 1 : 0);
