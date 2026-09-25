<?php
// wcma-calculator/tests/SeasonLinksTest.php
require_once __DIR__ . '/../season-links-lib.php';

use PHPUnit\Framework\TestCase;

final class SeasonLinksTest extends TestCase
{
    public function testValidation(): void
    {
        $ok = seasonLinkValidate('  2026 Annual Waiver ', ' https://www.motorsportreg.com/events/x-575797 ', '2');
        $this->assertTrue($ok['ok']);
        $this->assertSame('2026 Annual Waiver', $ok['label']);
        $this->assertSame('https://www.motorsportreg.com/events/x-575797', $ok['url']);
        $this->assertSame(2, $ok['sort_order']);

        $this->assertFalse(seasonLinkValidate('', 'https://x.test', '0')['ok']);
        $this->assertFalse(seasonLinkValidate('Label', 'javascript:alert(1)', '0')['ok']);
        $this->assertFalse(seasonLinkValidate('Label', 'not a url', '0')['ok']);
        $this->assertFalse(seasonLinkValidate(str_repeat('x', 121), 'https://x.test', '0')['ok']);
        $this->assertSame(0, seasonLinkValidate('Label', 'https://x.test', 'abc')['sort_order']);
    }

    public function testCrudAndOrdering(): void
    {
        $pdo = make_temp_pdo();
        $b = db_create_season_link($pdo, 'Licences', 'https://x.test/b', 2);
        $a = db_create_season_link($pdo, 'Waiver', 'https://x.test/a', 1);
        $this->assertSame(['Waiver', 'Licences'], array_column(db_get_season_links($pdo), 'label'));

        db_update_season_link($pdo, $b, 'Race Licences', 'https://x.test/b2', 0, false);
        $this->assertSame(['Waiver'], array_column(db_get_season_links($pdo, true), 'label'));
        $this->assertSame(['Race Licences', 'Waiver'], array_column(db_get_season_links($pdo), 'label'));

        db_delete_season_link($pdo, $a);
        $this->assertCount(1, db_get_season_links($pdo));
    }
}
