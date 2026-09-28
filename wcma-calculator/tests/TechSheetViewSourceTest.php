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
}
