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
}
