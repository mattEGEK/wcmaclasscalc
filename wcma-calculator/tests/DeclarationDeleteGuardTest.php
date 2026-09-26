<?php
// wcma-calculator/tests/DeclarationDeleteGuardTest.php
//
// Source-level guard: inspect.php needs config.php, so it cannot run under PHPUnit. Deleting a class
// declaration from Classing (admins only) refuses one that a submitted tech sheet uses, reports how many a
// bulk delete skipped, and leaves the car with its previous declaration as current (as the Garage does).
use PHPUnit\Framework\TestCase;

final class DeclarationDeleteGuardTest extends TestCase
{
    private function body(string $name): string {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../inspect.php'));
        $start = strpos($src, 'function ' . $name . '(');
        $this->assertNotFalse($start, $name . ' must exist');
        $next = strpos($src, "\nfunction ", $start + 1);
        return $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
    }

    public function testDeleteRefusesWhenReferencedByASubmittedTechSheet(): void
    {
        $body = $this->body('inspectDeleteDeclaration');
        $this->assertStringContainsString('db_count_tech_sheets_for_submission(', $body);
        $this->assertStringContainsString('This declaration is on a submitted tech sheet, so it cannot be deleted.', $body);
    }

    public function testBulkDeleteSkipsReferencedIdsAndReportsHowMany(): void
    {
        $body = $this->body('inspectBulkDeleteDeclarations');
        $this->assertStringContainsString('db_count_tech_sheets_for_submission(', $body);
        $this->assertStringContainsString('skipped', $body);
    }

    public function testDeletingRestoresThePreviousDeclaration(): void
    {
        $this->assertMatchesRegularExpression(
            "/db_delete_submission\\(\\\$pdo, \\\$id\\);\\s*db_restore_current_declaration\\(\\\$pdo, \\(int\\)\\\$sub\\['car_id'\\]\\);/",
            $this->body('inspectDeleteDeclaration')
        );
    }

    public function testBulkDeleteRestoresEachCarsPreviousDeclaration(): void
    {
        $this->assertMatchesRegularExpression(
            "/db_delete_submission\\(\\\$pdo, \\\$id\\);.*?db_restore_current_declaration\\(\\\$pdo, \\(int\\)\\\$sub\\['car_id'\\]\\);/s",
            $this->body('inspectBulkDeleteDeclarations')
        );
    }

    public function testDeletingAndEditingAreAdminOnly(): void
    {
        foreach (['declaration-delete', 'declarations-bulk-delete', 'declaration-update-contact'] as $action) {
            $this->assertSame('admin', inspectActionMinRole($action), $action);
        }
    }
}
