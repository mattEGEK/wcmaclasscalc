<?php
// wcma-calculator/reminders.php — CLI only. The daily reminder run (spec §7). The IONOS cron job runs
// it through reminders-cron.sh, because IONOS cron commands can only be a path.
//
//   php reminders.php                      send today's reminders
//   php reminders.php --today=2026-10-04   pretend it is that day (testing)
//   php reminders.php --mail-log=FILE      write the emails to FILE as JSON lines instead of sending
//   php reminders.php --base-url=URL       link base for the emails (default: SITE_BASE_URL in config.php)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$opts = ['today' => null, 'mail-log' => null, 'base-url' => null];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--(today|mail-log|base-url)=(.+)$/', $arg, $m)) {
        fwrite(STDERR, "Unknown option: $arg\n");
        exit(2);
    }
    $opts[$m[1]] = $m[2];
}
if ($opts['mail-log'] !== null) define('WCMA_MAIL_LOG', $opts['mail-log']);

date_default_timezone_set('America/Denver');
require_once __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';
require __DIR__ . '/email-helpers.php';
require __DIR__ . '/reminder-run.php';

$today = $opts['today'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
    fwrite(STDERR, "--today must be YYYY-MM-DD\n");
    exit(2);
}
$baseUrl = rtrim(trim((string)($opts['base-url'] ?? config_default('SITE_BASE_URL', ''))), '/');
if ($baseUrl === '') {
    fwrite(STDERR, "Set SITE_BASE_URL in config.php (the public URL of this folder) or pass --base-url=URL. Reminder emails need full links.\n");
    exit(1);
}

$pdo = db_connect();
db_init($pdo);
$s = remindersRun($pdo, $today, $baseUrl, 'emailSmtpSend');
echo date('Y-m-d H:i:s') . " reminders for {$today}: {$s['users']} opted in, {$s['sent']} sent, {$s['skipped']} already sent, {$s['failed']} failed\n";
exit($s['failed'] > 0 ? 1 : 0);
