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
    }
}
