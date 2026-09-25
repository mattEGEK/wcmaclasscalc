<?php
// wcma-calculator/tests/AdminDeleteGuardTest.php
//
// Source-level guard: admin.php needs config.php so it cannot run under PHPUnit. This checks
// handleDelete/handleBulkDelete refuse to delete a declaration that a submitted tech sheet
// references, and that the bulk path reports how many were skipped.
use PHPUnit\Framework\TestCase;

final class AdminDeleteGuardTest extends TestCase
{
    private function body(string $name): string {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../admin.php'));
        $start = strpos($src, 'function ' . $name . '(');
        $this->assertNotFalse($start, $name . ' must exist');
        $next = strpos($src, "\nfunction ", $start + 1);
        return $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
    }

    public function testHandleDeleteRefusesWhenReferencedByASubmittedTechSheet(): void
    {
        $body = $this->body('handleDelete');
        $this->assertStringContainsString('db_count_tech_sheets_for_submission(', $body);
        $this->assertStringContainsString('This declaration is on a submitted tech sheet, so it cannot be deleted.', $body);
    }

    public function testHandleBulkDeleteSkipsReferencedIdsAndReportsHowMany(): void
    {
        $body = $this->body('handleBulkDelete');
        $this->assertStringContainsString('db_count_tech_sheets_for_submission(', $body);
        $this->assertStringContainsString('skipped', $body);
    }
}
