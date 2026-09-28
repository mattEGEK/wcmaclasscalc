<?php
// wcma-calculator/tests/StatusClassHelperTest.php
use PHPUnit\Framework\TestCase;

final class StatusClassHelperTest extends TestCase
{
    public function testStatusClassIsASharedViewHelper(): void
    {
        require_once __DIR__ . '/../view_helpers.php';
        $this->assertSame('hub-status--ok', homeStatusClass('accepted'));
        $this->assertSame('hub-status--warn', homeStatusClass('none'));
    }

    public function testTechSheetPageDoesNotPullInTheHomeView(): void
    {
        $src = file_get_contents(__DIR__ . '/../tech-sheets.php');
        $this->assertStringNotContainsString("home-page.php", $src);
        $this->assertStringNotContainsString('function homeStatusClass(', file_get_contents(__DIR__ . '/../home-page.php'));
    }
}
