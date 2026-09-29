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

    public function testHomePassesTheChosenEventToTheTopList(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../index.php'));
        // ?event=<id> from an event card's "N things to do" link; anything else is ignored.
        $this->assertStringContainsString("'focusEventId' => is_string(\$_GET['event'] ?? null) && ctype_digit(\$_GET['event']) ? (int)\$_GET['event'] : null,", $src);
    }

    public function testHomeLoadsTheClubsLibForEventRegisterLinks(): void
    {
        // home-page.php calls eventRegisterUrl() (clubs-lib.php) for each event card's Register button.
        $src = file_get_contents(__DIR__ . '/../index.php');
        $this->assertStringContainsString("require_once __DIR__ . '/clubs-lib.php';", $src);
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

    public function testGlanceGatesUsesRaceOnUsesSummer(): void
    {
        // Mirrors garage-page.php's own guard: an ice-only car must never show "Declare class".
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../index.php'));
        $this->assertStringContainsString("'usesRace' => \$usesSummerCar && garageCarRaces(", $src);
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
