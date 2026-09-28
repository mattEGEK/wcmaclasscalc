<?php
// wcma-calculator/tests/DriversSourceTest.php
//
// Source-level guards for drivers.php and gear.php (they need config.php or the session).
use PHPUnit\Framework\TestCase;

final class DriversSourceTest extends TestCase
{
    private function src(string $file): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
    }

    public function testDriversNeedsASignedInUserChecksCsrfAndScopesToTheUser(): void
    {
        $src = $this->src('drivers.php');
        $this->assertStringContainsString("require_role('user')", $src);
        $this->assertMatchesRegularExpression("/REQUEST_METHOD'\] === 'POST'\) \{\s*if \(!validateCsrfToken\(/", $src);
        $this->assertStringContainsString('driversAdd($pdo, $uid,', $src);
        $this->assertStringContainsString('driversSetLicence($pdo, $uid,', $src);
        $this->assertStringContainsString("header('Location: drivers.php');", $src);
    }

    public function testOldGearListRoutesRedirectToDriversAndRenewIsGone(): void
    {
        $gear = $this->src('gear.php');
        $this->assertMatchesRegularExpression("/case 'list':\s*case 'add':\s*case 'renew':\s*header\('Location: drivers\.php'\);\s*exit;/", $gear);
        $this->assertStringNotContainsString('handleGearList', $gear);
        $this->assertStringNotContainsString('renderGearListPage', $this->src('gear-page.php'));
        $this->assertStringNotContainsString('function gearRenew', $this->src('gear-lib.php'));
        $this->assertStringContainsString('<a href="drivers.php">← Back to Drivers</a>', $this->src('gear-page.php'));
    }

    public function testDriversLoadsIceGearAndPassesItOn(): void
    {
        $src = $this->src('drivers.php');
        $this->assertStringContainsString('DISCIPLINE_ICE', $src);
        $this->assertStringContainsString('gearIceSummary(', $src);
        $this->assertStringContainsString(', $ice)', $src);
    }

    public function testNavAndLinksPointAtDrivers(): void
    {
        $this->assertStringContainsString("'href' => 'drivers.php'", $this->src('layout.php'));
        $this->assertStringContainsString('href="drivers.php">Manage drivers', $this->src('home-page.php'));
        $this->assertStringContainsString('<a href="drivers.php">Go to drivers</a>', $this->src('profile.php'));
    }

    public function testIceActivityCountsIceTagsLikeHome(): void
    {
        $src = $this->src('drivers.php');
        $this->assertStringContainsString("require_once __DIR__ . '/garage-lib.php';", $src);
        $this->assertStringContainsString('userHasIceActivity($userSheets, $hasIceGear, db_get_user_event_plans($pdo, $uid), db_get_active_events($pdo))', $src);
    }
}
