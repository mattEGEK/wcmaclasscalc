<?php
// wcma-calculator/tests/HubDbToolsTest.php
require_once __DIR__ . '/../hub-db-tools.php';

use PHPUnit\Framework\TestCase;

final class HubDbToolsTest extends TestCase
{
    public function testResetRemovesDatabaseAndUploadsButKeepsUploadsHtaccess(): void
    {
        $dir = sys_get_temp_dir() . '/hub_reset_' . uniqid();
        mkdir($dir . '/uploads/12', 0777, true);
        file_put_contents($dir . '/db.sqlite', 'x');
        file_put_contents($dir . '/db.sqlite-wal', 'x');
        file_put_contents($dir . '/uploads/.htaccess', 'Deny from all');
        file_put_contents($dir . '/uploads/12/dyno.pdf', 'x');

        hubResetDatabase($dir . '/db.sqlite', $dir . '/uploads');

        $this->assertFileDoesNotExist($dir . '/db.sqlite');
        $this->assertFileDoesNotExist($dir . '/db.sqlite-wal');
        $this->assertFileDoesNotExist($dir . '/uploads/12');
        $this->assertFileExists($dir . '/uploads/.htaccess');
    }

    public function testSeedCreatesAUsableHub(): void
    {
        $pdo = make_temp_pdo();
        $summary = hubSeed($pdo, 'password123');

        $this->assertSame(3, $summary['users']);
        $admin = db_find_user_by_email($pdo, BOOTSTRAP_ADMIN_EMAIL);
        $this->assertSame('admin', $admin['role']);
        $inspector = db_find_user_by_email($pdo, 'inspector@example.com');
        $this->assertSame('inspector', $inspector['role']);
        $this->assertTrue(userHasFirstAndLastName($inspector['name']));
        $this->assertTrue(password_verify('password123', $inspector['password_hash']));

        $jordan = db_find_user_by_email($pdo, 'jordan@example.com');
        $cars = db_get_user_cars($pdo, (int)$jordan['id']);
        $this->assertCount(2, $cars);
        $this->assertNotNull(db_get_car_current_declaration($pdo, (int)$cars[0]['id']));
        $this->assertCount(2, db_get_user_drivers($pdo, (int)$jordan['id']));
        $this->assertCount(2, db_get_active_events($pdo));
        $this->assertCount(3, db_get_season_links($pdo, true));
        $this->assertCount(1, db_get_user_tech_sheets($pdo, (int)$jordan['id']));

        $fall = (int)$pdo->query("SELECT id FROM events WHERE name = 'Fall Sprint'")->fetchColumn();
        $this->assertSame(['17', '42'], array_column(db_get_event_roster_cars($pdo, $fall), 'car_number'));
        $this->assertSame(1, $summary['event_plans']);
    }

    /**
     * Every page that writes a submitted_at (car-classing.php, tech-sheets.php, ...) sets
     * date_default_timezone_set('America/Denver') first. hubSeed() must record its timestamps the
     * same way, or seeded declarations sort ahead of/behind real ones made in the same test run
     * (PHP's own default timezone is UTC, six hours off Denver during DST).
     */
    public function testSeedRecordsTimestampsInAmericaDenverNotTheServerDefaultTimezone(): void
    {
        $pdo = make_temp_pdo();
        $before = new DateTimeImmutable('now', new DateTimeZone('America/Denver'));
        hubSeed($pdo, 'password123');
        $after = new DateTimeImmutable('now', new DateTimeZone('America/Denver'));

        $jordan = db_find_user_by_email($pdo, 'jordan@example.com');
        $cars = db_get_user_cars($pdo, (int)$jordan['id']);
        $decl = db_get_car_current_declaration($pdo, (int)$cars[0]['id']);
        $submittedAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$decl['submitted_at'], new DateTimeZone('America/Denver'));

        $this->assertGreaterThanOrEqual($before->modify('-30 seconds'), $submittedAt);
        $this->assertLessThanOrEqual($after->modify('+30 seconds'), $submittedAt);
    }

    public function testSeedRefusesANonEmptyDatabase(): void
    {
        $pdo = make_temp_pdo();
        db_create_user($pdo, ['email' => 'x@example.com', 'name' => 'X Y', 'password_hash' => 'x', 'google_id' => null]);
        $this->expectException(RuntimeException::class);
        hubSeed($pdo, 'password123');
    }

    public function testResetScriptRefusesWithoutConfirm(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../reset-hub-db.php') . ' 2>&1', $out, $code);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--confirm', implode("\n", $out));
    }
}
