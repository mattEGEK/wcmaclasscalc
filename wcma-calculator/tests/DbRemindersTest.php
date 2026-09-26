<?php
// wcma-calculator/tests/DbRemindersTest.php
use PHPUnit\Framework\TestCase;

final class DbRemindersTest extends TestCase
{
    private function user(PDO $pdo, string $email, string $name = 'Jordan Lee'): int {
        return db_create_user($pdo, ['email' => $email, 'name' => $name, 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testNewAccountsHaveRemindersOffAndHaveNotBeenAsked(): void
    {
        $pdo = make_temp_pdo();
        $row = db_find_user_by_id($pdo, $this->user($pdo, 'j@example.com'));
        $this->assertSame(0, (int)$row['reminder_emails']);
        $this->assertNull($row['reminder_prompted_at']);
    }

    public function testSettingRemindersKeepsTheFirstChoiceTime(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        db_set_user_reminders($pdo, $u, true);
        $row = db_find_user_by_id($pdo, $u);
        $this->assertSame(1, (int)$row['reminder_emails']);
        $this->assertNotNull($row['reminder_prompted_at']);

        $pdo->exec("UPDATE users SET reminder_prompted_at = '2026-01-01 00:00:00' WHERE id = $u");
        db_set_user_reminders($pdo, $u, false);
        $row = db_find_user_by_id($pdo, $u);
        $this->assertSame(0, (int)$row['reminder_emails']);
        $this->assertSame('2026-01-01 00:00:00', $row['reminder_prompted_at']);
    }

    public function testReminderUsersAreOptedInAndActive(): void
    {
        $pdo = make_temp_pdo();
        $on = $this->user($pdo, 'on@example.com');
        $off = $this->user($pdo, 'off@example.com');
        $gone = $this->user($pdo, 'gone@example.com');
        db_set_user_reminders($pdo, $on, true);
        db_set_user_reminders($pdo, $off, false);
        db_set_user_reminders($pdo, $gone, true);
        db_set_user_active($pdo, $gone, false);
        $this->assertSame([$on], array_map(fn(array $r): int => (int)$r['id'], db_get_reminder_users($pdo)));
    }

    public function testReminderLogIsOncePerUserEventAndWindow(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $e = db_create_event($pdo, 'Fall Sprint', '2026-10-11', null);
        $this->assertFalse(db_reminder_logged($pdo, $u, $e, 7));
        $this->assertTrue(db_log_reminder($pdo, $u, $e, 7));
        $this->assertTrue(db_reminder_logged($pdo, $u, $e, 7));
        $this->assertFalse(db_log_reminder($pdo, $u, $e, 7));
        $this->assertTrue(db_log_reminder($pdo, $u, $e, 2));
        $this->assertFalse(db_reminder_logged($pdo, $u, $e, 14));
    }
}
