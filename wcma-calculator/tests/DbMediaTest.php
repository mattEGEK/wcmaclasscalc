<?php
// wcma-calculator/tests/DbMediaTest.php
use PHPUnit\Framework\TestCase;

final class DbMediaTest extends TestCase
{
    private function user(PDO $pdo, string $email, string $name = 'Jordan Lee'): int {
        return db_create_user($pdo, ['email' => $email, 'name' => $name, 'password_hash' => 'x', 'google_id' => null]);
    }

    private function consent(PDO $pdo, int $driverId, int $by, int $media, int $public = 0): int {
        return db_insert_media_consent($pdo, [
            'driver_id' => $driverId, 'consent_media' => $media, 'consent_public' => $public, 'is_minor' => 0,
            'guardian_name' => null, 'given_by_user_id' => $by, 'on_behalf' => 0, 'wording_version' => 1,
        ]);
    }

    private function profile(array $o = []): array {
        return array_merge(['blurb' => 'Fast and tidy.', 'pronunciation' => null, 'hometown' => 'Red Deer, AB',
            'racing_since' => 2015, 'social_handle' => null, 'photo_path' => null, 'public_status' => 'none'], $o);
    }

    public function testAddColumnIfMissingKeepsRowsAndIsRepeatable(): void
    {
        $pdo = make_temp_pdo();
        $pdo->exec("CREATE TABLE legacy (id INTEGER PRIMARY KEY, name TEXT)");
        $pdo->exec("INSERT INTO legacy (name) VALUES ('kept')");
        $this->assertTrue(db_add_column_if_missing($pdo, 'legacy', 'flag', 'INTEGER NOT NULL DEFAULT 0'));
        $this->assertFalse(db_add_column_if_missing($pdo, 'legacy', 'flag', 'INTEGER NOT NULL DEFAULT 0'));
        $this->assertSame(['id' => 1, 'name' => 'kept', 'flag' => 0], $pdo->query("SELECT * FROM legacy")->fetch());
        db_init($pdo);   // a second init on an existing database must not fail
        $this->assertSame(0, (int)db_find_user_by_id($pdo, $this->user($pdo, 'a@example.com'))['is_media']);
    }

    public function testAddColumnRejectsUnsafeIdentifiers(): void
    {
        $pdo = make_temp_pdo();
        $this->expectException(InvalidArgumentException::class);
        db_add_column_if_missing($pdo, 'users; DROP TABLE users', 'x', 'INTEGER');
    }

    /** A genuine race (two deploys running the same migration at once) can't be reproduced against
     *  a single SQLite connection, so this pins the guard at the source level: the ALTER is wrapped
     *  so a duplicate-column race returns false instead of throwing, and anything else still throws. */
    public function testAddColumnCatchesOnlyTheDuplicateColumnRace(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../db.php'));
        $this->assertMatchesRegularExpression(
            "/try \{\\s*\\\$pdo->exec\(\"ALTER TABLE \\\$table ADD COLUMN \\\$column \\\$definition\"\);\\s*\} catch \(PDOException \\\$e\) \{/",
            $src
        );
        $this->assertStringContainsString("stripos(\$e->getMessage(), 'duplicate column name') !== false) return false;", $src);
        $this->assertStringContainsString('throw $e;', $src);
    }

    public function testTombstoneClearsContentAndSponsorsButKeepsTheHide(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $d = (int)db_get_self_driver($pdo, $u)['id'];
        db_save_media_profile($pdo, $d, $this->profile(['photo_path' => 'uploads/media/x.jpg']));
        db_replace_sponsors($pdo, $d, [['name' => 'Acme', 'url' => null]]);
        db_set_media_hidden($pdo, $d, $u, 'Sponsor dispute');

        db_tombstone_media_profile($pdo, $d);

        $p = db_get_media_profile($pdo, $d);
        $this->assertNotNull($p);
        $this->assertSame('', $p['blurb']);
        $this->assertNull($p['pronunciation']);
        $this->assertNull($p['hometown']);
        $this->assertNull($p['racing_since']);
        $this->assertNull($p['social_handle']);
        $this->assertNull($p['photo_path']);
        $this->assertSame('none', $p['public_status']);
        $this->assertNotNull($p['hidden_at']);
        $this->assertSame('Sponsor dispute', $p['hidden_reason']);
        $this->assertSame([], db_get_sponsors($pdo, $d));
    }

    public function testUsersGetMediaFlags(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $row = db_find_user_by_id($pdo, $u);
        $this->assertSame(0, (int)$row['is_media']);
        $this->assertSame(0, (int)$row['media_prompt_dismissed']);
        db_set_user_media($pdo, $u, true);
        db_dismiss_media_prompt($pdo, $u);
        $row = db_find_user_by_id($pdo, $u);
        $this->assertSame(1, (int)$row['is_media']);
        $this->assertSame(1, (int)$row['media_prompt_dismissed']);
    }

    public function testProfileSaveUpdateStatusHideAndDelete(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $d = (int)db_get_self_driver($pdo, $u)['id'];
        $this->assertNull(db_get_media_profile($pdo, $d));

        db_save_media_profile($pdo, $d, $this->profile());
        db_save_media_profile($pdo, $d, $this->profile(['blurb' => 'Second', 'public_status' => 'pending_review']));
        $p = db_get_media_profile($pdo, $d);
        $this->assertSame('Second', $p['blurb']);
        $this->assertSame('pending_review', $p['public_status']);
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM driver_media_profiles")->fetchColumn());

        db_set_media_public_status($pdo, $d, 'sent_back', $u, 'Brighter photo please');
        $p = db_get_media_profile($pdo, $d);
        $this->assertSame('sent_back', $p['public_status']);
        $this->assertSame($u, (int)$p['public_reviewed_by']);
        $this->assertSame('Brighter photo please', $p['public_note']);

        db_set_media_hidden($pdo, $d, $u, 'Sponsor dispute');
        $this->assertNotNull(db_get_media_profile($pdo, $d)['hidden_at']);
        db_set_media_hidden($pdo, $d, null, null);
        $p = db_get_media_profile($pdo, $d);
        $this->assertNull($p['hidden_at']);
        $this->assertSame('sent_back', $p['public_status']);

        db_replace_sponsors($pdo, $d, [['name' => 'Acme', 'url' => null]]);
        $this->consent($pdo, $d, $u, 1);
        db_delete_media_profile($pdo, $d);
        $this->assertNull(db_get_media_profile($pdo, $d));
        $this->assertSame([], db_get_sponsors($pdo, $d));
        $this->assertNotNull(db_get_latest_media_consent($pdo, $d));   // consent history is kept
    }

    public function testSponsorsAreReplacedAsASetInOrder(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $d = (int)db_get_self_driver($pdo, $u)['id'];
        db_replace_sponsors($pdo, $d, [['name' => 'Acme', 'url' => 'https://acme.test'], ['name' => 'Bob', 'url' => null]]);
        db_replace_sponsors($pdo, $d, [['name' => 'Zed', 'url' => null], ['name' => 'Acme', 'url' => 'https://acme.test']]);
        $this->assertSame(['Zed', 'Acme'], array_column(db_get_sponsors($pdo, $d), 'name'));
    }

    public function testNewestConsentWinsAndConsentedIdsFollowIt(): void
    {
        $pdo = make_temp_pdo();
        $a = $this->user($pdo, 'a@example.com', 'Ann Ames');
        $b = $this->user($pdo, 'b@example.com', 'Bo Bell');
        $da = (int)db_get_self_driver($pdo, $a)['id'];
        $db = (int)db_get_self_driver($pdo, $b)['id'];
        $this->consent($pdo, $da, $a, 1, 1);
        $this->consent($pdo, $db, $b, 1);
        $this->consent($pdo, $db, $b, 0);   // Bo withdrew
        $this->assertSame(1, (int)db_get_latest_media_consent($pdo, $da)['consent_public']);
        $this->assertSame(0, (int)db_get_latest_media_consent($pdo, $db)['consent_media']);
        $this->assertSame([$da], db_get_consented_driver_ids($pdo));
    }

    public function testBundleQueueAndSearch(): void
    {
        $pdo = make_temp_pdo();
        $a = $this->user($pdo, 'a@example.com', 'Ann Ames');
        $b = $this->user($pdo, 'b@example.com', 'Bo Bell');
        $da = (int)db_get_self_driver($pdo, $a)['id'];
        $db = (int)db_get_self_driver($pdo, $b)['id'];
        db_save_media_profile($pdo, $da, $this->profile(['public_status' => 'pending_review']));
        db_save_media_profile($pdo, $db, $this->profile(['public_status' => 'pending_review']));
        db_set_media_hidden($pdo, $db, $a, 'x');
        db_replace_sponsors($pdo, $da, [['name' => 'Acme', 'url' => null]]);

        $bundle = db_get_media_bundle($pdo, [$da, 999]);
        $this->assertSame('Fast and tidy.', $bundle[$da]['profile']['blurb']);
        $this->assertSame('Acme', $bundle[$da]['sponsors'][0]['name']);
        $this->assertNull($bundle[999]['profile']);

        $queue = db_get_media_review_queue($pdo);
        $this->assertSame([$da], array_map(fn(array $r): int => (int)$r['driver_id'], $queue));
        $this->assertSame('Ann Ames', $queue[0]['driver_name']);

        $this->assertSame([$db], array_map(fn(array $r): int => (int)$r['driver_id'], db_search_media_profiles($pdo, 'bell')));
    }

    public function testDriverLatestSheetCoversDriverOneAndAdditionalDrivers(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $e = db_create_event($pdo, 'Fall Sprint', date('Y') . '-10-11', null);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        $sheet = test_make_sheet($pdo, $u, $sub, $e, '42', 'Jordan Lee');
        db_add_tech_sheet_driver($pdo, $sheet, 2, 'Sam Patel', '{}');
        $self = (int)db_get_self_driver($pdo, $u)['id'];
        $sam = (int)db_find_driver($pdo, $u, 'Sam Patel')['id'];
        $this->assertSame($sheet, (int)db_get_driver_latest_sheet($pdo, $self, (int)date('Y'))['id']);
        $this->assertSame($sheet, (int)db_get_driver_latest_sheet($pdo, $sam, (int)date('Y'))['id']);
        $this->assertNull(db_get_driver_latest_sheet($pdo, $sam, (int)date('Y') - 1));
    }

    public function testDriverLatestSheetOfEitherDisciplineIsNewestUnlessSeasonIsGiven(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $summerEvent = db_create_event($pdo, 'Summer Sprint', '2026-07-11', null);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        $summerSheet = test_make_sheet($pdo, $u, $sub, $summerEvent, '42', 'Test Driver');

        $iceEvent = db_create_event($pdo, 'Ice Classic', '2027-01-11', null, 'ice', 'NASCC');
        $car = test_make_car($pdo, $u, '42');
        $iceSheet = test_make_ice_sheet($pdo, $u, $car, $iceEvent, 'LS');

        $did = (int)db_find_driver($pdo, $u, 'Test Driver')['id'];
        $this->assertSame($iceSheet, (int)db_get_driver_latest_sheet($pdo, $did)['id']);
        $this->assertSame($summerSheet, (int)db_get_driver_latest_sheet($pdo, $did, 2026)['id']);
    }

    public function testDriverCurrentSheetOnlyComesFromTheCurrentSummerOrIceSeason(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'k@example.com');
        $summerEvent = db_create_event($pdo, 'Summer Sprint', '2026-07-11', null);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        $summerSheet = test_make_sheet($pdo, $u, $sub, $summerEvent, '42', 'Test Driver');
        $iceEvent = db_create_event($pdo, 'Ice Classic', '2027-01-11', null, 'ice', 'NASCC');
        $iceSheet = test_make_ice_sheet($pdo, $u, test_make_car($pdo, $u, '42'), $iceEvent, 'LS');
        $did = (int)db_find_driver($pdo, $u, 'Test Driver')['id'];

        $this->assertSame($summerSheet, (int)db_get_driver_current_sheet($pdo, $did, 2026, 2026)['id']);
        $this->assertSame($iceSheet, (int)db_get_driver_current_sheet($pdo, $did, 2026, 2027)['id']);
        $this->assertSame($iceSheet, (int)db_get_driver_current_sheet($pdo, $did, 2027, 2027)['id']);
        $this->assertNull(db_get_driver_current_sheet($pdo, $did, 2028, 2028));   // no stale car from an old season
    }
}
