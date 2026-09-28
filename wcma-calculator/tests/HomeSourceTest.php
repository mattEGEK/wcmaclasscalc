<?php
// wcma-calculator/tests/HomeSourceTest.php
//
// Source-level guard: index.php needs config.php, so it cannot run under PHPUnit.
use PHPUnit\Framework\TestCase;

final class HomeSourceTest extends TestCase
{
    public function testAtTrackPassesDisciplineAndClub(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../index.php'));
        $this->assertStringContainsString("(string)(\$_POST['discipline'] ?? 'summer')", $src);
        $this->assertStringContainsString("(string)(\$_POST['club'] ?? '')", $src);
    }

    public function testHomeGlanceCardsCarryIceSummaries(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../index.php'));
        $this->assertStringContainsString('garageIceSummary(', $src);
        $this->assertStringContainsString('gearIceSummary(', $src);
        $this->assertStringContainsString('driverShowsSummerGear($hasIceActivity, $userUsesSummer', $src);
    }

    public function testGlancePassesTheIceTagToUsesSummer(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../index.php'));
        $this->assertStringContainsString('garageCarUsesSummer($decl !== null ? [$decl] : [], $carSheets, $taggedSummer, $taggedIce, $stored)', $src);
    }

    public function testIceActivityUsesTheSharedHelper(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../index.php'));
        $this->assertStringContainsString("userHasIceActivity(\$in['sheets'], (bool)\$in['iceGear'], \$in['plans'], \$in['events'], \$in['cars'])", $src);
    }

    public function testIceGearFallsBackToTheDbWhenNotPreloaded(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../index.php'));
        $this->assertStringContainsString("array_key_exists(\$iceKey, \$in['iceGear'])", $src);
        $this->assertStringContainsString('db_get_gear_record_for_driver($pdo, $did, $iceSeason, DISCIPLINE_ICE)', $src);
    }

    public function testLandingIsToldWhetherTheNextEventIsIce(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../index.php'));
        $this->assertStringContainsString("renderLandingHtml(db_get_season_links(\$pdo, true), landingNextIsIce(db_get_active_events(\$pdo), date('Y-m-d')))", $src);
    }
}
