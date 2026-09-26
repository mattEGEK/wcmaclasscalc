<?php
// wcma-calculator/tests/ReminderCronSourceTest.php
//
// reminders.php needs config.php and the real database, and reminders-cron.sh is a shell script, so
// these are source-level and repository checks, plus one run of the CLI's option parsing (which exits
// before loading anything).
use PHPUnit\Framework\TestCase;

final class ReminderCronSourceTest extends TestCase
{
    private function src(string $file): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
    }

    public function testCliGuardOptionsAndBaseUrlCheck(): void
    {
        $src = $this->src('reminders.php');
        $guard = strpos($src, "if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }");
        $this->assertNotFalse($guard);
        $this->assertLessThan(strpos($src, 'require '), $guard);
        $this->assertStringContainsString("config_default('SITE_BASE_URL', '')", $src);
        $this->assertMatchesRegularExpression("/if \\(\\\$baseUrl === ''\\) \\{\\s*fwrite\\(STDERR, \"Set SITE_BASE_URL/", $src);
        $this->assertStringContainsString("remindersRun(\$pdo, \$today, \$baseUrl, 'emailSmtpSend')", $src);
        $this->assertStringContainsString("date_default_timezone_set('America/Denver');", $src);
    }

    public function testUnknownOptionsAreRefusedBeforeAnythingLoads(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../reminders.php') . ' --bogus 2>&1', $out, $code);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('Unknown option: --bogus', implode("\n", $out));
    }

    public function testCronWrapperRunsTheCliAndLogs(): void
    {
        $sh = $this->src('reminders-cron.sh');
        $this->assertStringStartsWith("#!/bin/sh\n", $sh);
        $this->assertStringContainsString('cd "$(dirname "$0")" || exit 1', $sh);
        $this->assertStringContainsString('reminders.php >> data/reminders.log 2>&1', $sh);
        $this->assertStringContainsString("*.sh text eol=lf", file_get_contents(__DIR__ . '/../../.gitattributes'));
        $out = shell_exec('git -C ' . escapeshellarg(__DIR__ . '/..') . ' ls-files -s reminders-cron.sh');
        if (!is_string($out) || $out === '') $this->markTestSkipped('git is not available');
        $this->assertStringStartsWith('100755', $out);
    }

    public function testCliFilesAreNotServedOverTheWeb(): void
    {
        $htaccess = $this->src('.htaccess');
        $this->assertStringContainsString('reminders\.php', $htaccess);
        $this->assertStringContainsString('reminders-cron\.sh', $htaccess);
        $this->assertStringContainsString('data/*.log', $this->src('.gitignore'));
    }
}
