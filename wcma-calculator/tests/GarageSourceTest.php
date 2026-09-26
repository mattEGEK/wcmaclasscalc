<?php
// wcma-calculator/tests/GarageSourceTest.php
//
// Source-level guards for garage.php and account.php (they need config.php, so they cannot run
// under PHPUnit).
use PHPUnit\Framework\TestCase;

final class GarageSourceTest extends TestCase
{
    private function src(string $file): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
    }

    private function body(string $file, string $name): string {
        $src = $this->src($file);
        $start = strpos($src, 'function ' . $name . '(');
        $this->assertNotFalse($start, $name . ' must exist in ' . $file);
        $next = strpos($src, "\nfunction ", $start + 1);
        return $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
    }

    public function testGarageNeedsASignedInUserAndChecksCsrfOnEveryPost(): void
    {
        $src = $this->src('garage.php');
        $this->assertStringContainsString("require_role('user')", $src);
        $this->assertMatchesRegularExpression("/REQUEST_METHOD'\] === 'POST'\) \{\s*if \(!validateCsrfToken\(/", $src);
    }

    public function testListEmitsTheCsrfMetaTheDraftCodeScrapes(): void
    {
        $this->assertStringContainsString('<meta name="csrf-token" content="', $this->body('garage.php', 'garageShowList'));
    }

    public function testAddValidatesAndArchiveRestoreAreOwnerScoped(): void
    {
        $post = $this->body('garage.php', 'handleGaragePost');
        $this->assertStringContainsString('carsValidateDetails($_POST)', $post);
        $this->assertStringContainsString('db_archive_car($pdo, $uid, $carId)', $post);
        $this->assertStringContainsString('db_restore_car($pdo, $uid, $carId)', $post);
    }
}
