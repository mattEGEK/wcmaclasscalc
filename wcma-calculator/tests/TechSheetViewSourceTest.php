<?php
// wcma-calculator/tests/TechSheetViewSourceTest.php
//
// Source-level guard: tech-sheets.php needs config.php, so it cannot run under PHPUnit.
use PHPUnit\Framework\TestCase;

final class TechSheetViewSourceTest extends TestCase
{
    private function viewBody(): string {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../tech-sheets.php'));
        $start = strpos($src, 'function handleView(');
        $this->assertNotFalse($start);
        $next = strpos($src, "\nfunction ", $start + 1);
        return substr($src, $start, $next - $start);
    }

    public function testViewUsesTheDescriptiveTitleAndWhatsNext(): void
    {
        $body = $this->viewBody();
        $this->assertStringContainsString('techSheetViewTitle($sheet, $event)', $body);
        $this->assertStringContainsString('renderTechSheetNextStepsHtml(', $body);
        $this->assertStringNotContainsString('Tech Sheet #', $body);
        $this->assertStringNotContainsString('btn-primary">Resend', $body);
    }

    public function testRenderedSheetIsWrappedForScreenSizing(): void
    {
        $this->assertStringContainsString('<div class="sheet-doc"><?= renderTechSheetHtml(', $this->viewBody());
    }

    public function testSummerFormLoadsFormProblemsAndMarksRequiredFields(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../tech-sheets.php'));
        $this->assertStringContainsString('<script src="js/form-problems.js"></script>', $src);
        $this->assertStringContainsString('<label for="entrant_name">Entrant (required)</label>', $src);
        $this->assertStringContainsString('data-radio-group="Log book turned in? (required)"', $src);
        $this->assertStringContainsString('<label for="engine_hp">Engine HP (optional)</label>', $src);
    }
}
