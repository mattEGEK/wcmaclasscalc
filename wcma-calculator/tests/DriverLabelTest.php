<?php
// wcma-calculator/tests/DriverLabelTest.php
//
// Source-level guard for the sheet form (tech-sheets.php needs config.php, so it cannot run under
// PHPUnit): the first driver field is labelled as a person and team names are pointed at Entrant.
use PHPUnit\Framework\TestCase;

final class DriverLabelTest extends TestCase
{
    private function src(string $file): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
    }

    public function testFormLabelsDriverOneAsAPerson(): void
    {
        $src = $this->src('tech-sheets.php');
        $this->assertStringContainsString('<label for="driver_name">Driver name (Driver 1)</label>', $src);
        $this->assertStringNotContainsString('Driver/Team Name', $src);
        $this->assertStringContainsString('name="driver_name" required list="gear-names"', $src);
    }

    public function testFormHintSendsTeamNamesToEntrant(): void
    {
        $src = $this->src('tech-sheets.php');
        $this->assertStringContainsString('<label for="entrant_name">Entrant</label>', $src);
        $this->assertStringContainsString('Driver 1 is the person driving. If you race as a team, put the team name in Entrant.', $src);
    }

    public function testNoBannedWordingInTheNewCopy(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed)\b/i', $this->src('tech-sheets.php'));
    }
}
