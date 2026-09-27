<?php
// wcma-calculator/tests/RemindersUiSourceTest.php — index.php, garage.php and profile.php need a request, so these are source-level checks.
use PHPUnit\Framework\TestCase;

final class RemindersUiSourceTest extends TestCase
{
    private function src(string $file): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
    }

    public function testTagHandlersRecordTheChoiceOnlyAfterASuccessfulTag(): void
    {
        foreach (['index.php', 'garage.php'] as $file) {
            $src = $this->src($file);
            $this->assertStringContainsString("\$extra = \$r['ok'] ? remindersRecordTagChoice(\$pdo, \$uid, \$_POST) : '';", $src, $file);
            $this->assertStringContainsString("'Added to your events. ' . EVENTS_NOT_REGISTERING . \$extra", $src, $file);
            $this->assertStringContainsString("/reminders-lib.php'", $src, $file);
        }
        // garage.php still looks the user up inline for this one value.
        $this->assertStringContainsString("'offerReminders' => remindersShouldOffer(db_find_user_by_id(\$pdo, \$uid))", $this->src('garage.php'));
        // index.php reuses the $userRow it also needs for the Home media prompt (spec 2026-09-27 §3).
        $this->assertStringContainsString("'offerReminders' => remindersShouldOffer(\$userRow)", $this->src('index.php'));
    }

    public function testProfileTurnsRemindersOnAndOff(): void
    {
        $src = $this->src('profile.php');
        $this->assertStringContainsString("if (\$action === 'reminders') {\n        \$on = !empty(\$_POST['reminder_emails']);\n        db_set_user_reminders(\$pdo, (int)\$user['id'], \$on);", $src);
        $this->assertStringContainsString('name="reminder_emails" value="1"', $src);
        $this->assertStringContainsString('h(COPY_REMINDER_OPT_IN)', $src);
    }
}
