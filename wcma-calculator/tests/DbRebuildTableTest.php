<?php
// wcma-calculator/tests/DbRebuildTableTest.php
use PHPUnit\Framework\TestCase;

final class DbRebuildTableTest extends TestCase
{
    private const SQL = "CREATE TABLE IF NOT EXISTS {table} (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, flavour TEXT NOT NULL DEFAULT 'plain',
        UNIQUE (name, flavour))";

    private function pdo(): PDO {
        $pdo = new PDO('sqlite:' . sys_get_temp_dir() . '/wcma_rebuild_' . uniqid() . '.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE things (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE)");
        $pdo->exec("INSERT INTO things (name) VALUES ('a'), ('b')");
        return $pdo;
    }

    public function testHasColumn(): void
    {
        $pdo = $this->pdo();
        $this->assertTrue(db_has_column($pdo, 'things', 'name'));
        $this->assertFalse(db_has_column($pdo, 'things', 'flavour'));
    }

    public function testRebuildAddsColumnsKeepsRowsAndIdsAndNewConstraint(): void
    {
        $pdo = $this->pdo();
        $this->assertTrue(db_rebuild_table($pdo, 'things', 'flavour', self::SQL));
        $rows = $pdo->query("SELECT id, name, flavour FROM things ORDER BY id")->fetchAll();
        $this->assertSame([['id' => 1, 'name' => 'a', 'flavour' => 'plain'], ['id' => 2, 'name' => 'b', 'flavour' => 'plain']], $rows);
        // New UNIQUE (name, flavour): same name, different flavour is now allowed.
        $pdo->exec("INSERT INTO things (name, flavour) VALUES ('a', 'ice')");
        $this->assertSame(3, (int)$pdo->query("SELECT COUNT(*) FROM things")->fetchColumn());
        // New rows keep counting on from the old ids.
        $this->assertSame(3, (int)$pdo->query("SELECT MAX(id) FROM things")->fetchColumn());
    }

    public function testRebuildIsIdempotentAndKeepsRows(): void
    {
        $pdo = $this->pdo();
        $this->assertTrue(db_rebuild_table($pdo, 'things', 'flavour', self::SQL));
        $this->assertFalse(db_rebuild_table($pdo, 'things', 'flavour', self::SQL));
        $this->assertSame(2, (int)$pdo->query("SELECT COUNT(*) FROM things")->fetchColumn());
        $this->assertFalse(db_has_column($pdo, 'things__rebuild', 'id'));
    }

    public function testRejectsUnsafeIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        db_rebuild_table($this->pdo(), 'things; DROP', 'flavour', self::SQL);
    }

    public function testRebuildNeverSilentlyDropsAColumn(): void
    {
        $pdo = $this->pdo();
        // The live table has an extra column ("legacy_note") that the new schema doesn't carry.
        $pdo->exec("ALTER TABLE things ADD COLUMN legacy_note TEXT");
        $pdo->exec("UPDATE things SET legacy_note = 'keep me'");

        try {
            db_rebuild_table($pdo, 'things', 'flavour', self::SQL);
            $this->fail('Expected a RuntimeException for the dropped column');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('legacy_note', $e->getMessage());
        }

        // Rolled back: the live table (schema and rows) is untouched.
        $this->assertTrue(db_has_column($pdo, 'things', 'legacy_note'));
        $this->assertFalse(db_has_column($pdo, 'things', 'flavour'));
        $rows = $pdo->query("SELECT id, name, legacy_note FROM things ORDER BY id")->fetchAll();
        $this->assertSame([['id' => 1, 'name' => 'a', 'legacy_note' => 'keep me'], ['id' => 2, 'name' => 'b', 'legacy_note' => 'keep me']], $rows);
        $this->assertFalse($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='things__rebuild'")->fetchColumn());
    }
}
