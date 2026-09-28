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

    public function testCarPageLoadsOnlyTheUsersOwnCar(): void
    {
        $show = $this->body('garage.php', 'garageShowCar');
        $this->assertStringContainsString('db_get_user_car($pdo, $uid, $carId)', $show);
        $this->assertMatchesRegularExpression("/=== null\) \{ setFlash\('Car not found\.', 'error'\); header\('Location: garage\.php'\); exit; \}/", $show);
        $this->assertStringContainsString('gearLinksForSheet(', $show);
    }

    public function testCarPostsAreOwnerScoped(): void
    {
        $post = $this->body('garage.php', 'handleGaragePost');
        $this->assertStringContainsString("case 'update-car':\n            if (db_get_user_car(\$pdo, \$uid, \$carId) === null) break;", $post);
        $this->assertStringContainsString('eventsTagCar($pdo, $uid,', $post);
        $this->assertStringContainsString('eventsUntagCar($pdo, $uid,', $post);
    }

    public function testDeclarationRoutesAreOwnerScoped(): void
    {
        foreach (['garageShowDeclaration', 'garageDeclarationFile', 'garageResendDeclaration', 'garageDeleteDeclaration'] as $fn) {
            $this->assertStringContainsString('db_get_user_submission($pdo, $uid,', $this->body('garage.php', $fn), $fn);
        }
        $this->assertStringContainsString('db_count_tech_sheets_for_submission(', $this->body('garage.php', 'garageDeleteDeclaration'));
    }

    public function testDeletingADeclarationRestoresTheCarsPreviousOne(): void
    {
        $del = $this->body('garage.php', 'garageDeleteDeclaration');
        $this->assertMatchesRegularExpression(
            "/db_delete_submission\(\\\$pdo, \\\$id\);\\s*db_restore_current_declaration\(\\\$pdo, \(int\)\\\$sub\['car_id'\]\);/",
            $del
        );
    }

    public function testAddCarFormShowsTheFlashOnAFreshGetButNotOnAValidationError(): void
    {
        $add = $this->body('garage.php', 'garageRenderAdd');
        $this->assertStringContainsString("'flash' => \$error === null ? getFlash() : null", $add);
    }

    public function testAccountKeepsDraftEndpointsAndRedirectsEverythingElse(): void
    {
        $src = $this->src('account.php');
        foreach (['draft-save', 'draft-list', 'draft-load', 'draft-delete'] as $action) {
            $this->assertStringContainsString("case '$action':", $src, $action);
        }
        $this->assertStringContainsString("header('Location: garage.php?declaration=' . (int)(\$_GET['id'] ?? 0));", $src);
        $this->assertStringContainsString("header('Location: garage.php');", $src);
        $this->assertStringNotContainsString('<!DOCTYPE html>', $src);
    }

    public function testTechSheetsNoLongerSendPeopleToAccountPhp(): void
    {
        $this->assertStringNotContainsString('account.php', $this->src('tech-sheets.php'));
        $this->assertStringNotContainsString('My Cars', $this->src('tech-sheets.php'));
    }

    // Phase 4b: ice events are now tech-sheeted, so the tagging lists on the Garage list page and a
    // car's own page must offer every active event (summer and ice), and the ice season comes from
    // gearSeasonNow(DISCIPLINE_ICE).
    public function testGarageLoadsAllEventsAndIceSeason(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../garage.php'));
        $this->assertStringNotContainsString('db_get_active_events($pdo, DISCIPLINE_SUMMER)', $src);
        $this->assertStringContainsString("gearSeasonNow(DISCIPLINE_ICE)", $src);
        $this->assertStringNotContainsString('garageIceRows(', $src);
    }

    public function testCarPagePassesTheIceTagToUsesSummer(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../garage.php'));
        $this->assertStringContainsString('garageCarSeasons($car, $declarations, $allSheets, $taggedSummer, $taggedIce)', $src);
    }

    public function testAddRequiresASeasonAndTagsTheEventItWasAddedFor(): void
    {
        $body = $this->body('garage.php', 'handleGaragePost');
        $this->assertStringContainsString('carsValidateDetails($_POST, true)', $body);
        $this->assertStringContainsString('garageAddEvent(', $body);
        $this->assertStringContainsString('garageAfterAdd(', $body);
    }

    public function testTagCanContinueStraightToTheIceSheet(): void
    {
        $this->assertStringContainsString("garageAfterTagUrl(\$carId, \$event, (\$_POST['then'] ?? '') === 'sheet')", $this->body('garage.php', 'handleGaragePost'));
        $this->assertStringContainsString("garageAddableEvents(\$events['untagged'], \$car, \$seasons)", $this->body('garage.php', 'garageShowCar'));
    }
}
