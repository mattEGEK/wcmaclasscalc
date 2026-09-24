<?php
// wcma-calculator/tests/TechSheetsHandlersTest.php
//
// Source-level guard: the handlers need config.php so they cannot run under PHPUnit. This
// checks the photo edit-lock guard sits in the edit paths and nowhere else.
use PHPUnit\Framework\TestCase;

final class TechSheetsHandlersTest extends TestCase
{
    private function body(string $name): string {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../tech-sheets.php'));
        $start = strpos($src, 'function ' . $name . '(');
        $this->assertNotFalse($start, $name . ' must exist');
        $next = strpos($src, "\nfunction ", $start + 1);
        return $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
    }

    public function testEditLockGuardIsInTheEditPaths(): void
    {
        foreach (['handleView', 'handleEdit', 'handleUpdate'] as $fn) {
            $this->assertStringContainsString('pretechSheetEditable(', $this->body($fn), $fn);
        }
    }

    public function testEditLockGuardIsNotInNewSheetOrPretechSubmit(): void
    {
        foreach (['handleSubmit', 'handlePretechSubmit'] as $fn) {
            $this->assertStringNotContainsString('pretechSheetEditable(', $this->body($fn), $fn);
        }
    }
}
