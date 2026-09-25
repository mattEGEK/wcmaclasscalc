<?php
// wcma-calculator/tests/EmailCopyTest.php
require_once __DIR__ . '/../email-copy.php';
require_once __DIR__ . '/../view_helpers.php';   // h()

use PHPUnit\Framework\TestCase;

final class EmailCopyTest extends TestCase
{
    public function testBindingCopy(): void
    {
        $this->assertSame('Class declaration received. An inspector will review and respond.', COPY_DECLARATION_RECEIVED);
        $this->assertSame('Tech sheet received. An inspector will review and respond.', COPY_TECH_SHEET_RECEIVED);
        $this->assertSame('The scrutineer has reviewed & accepted your tech sheet.', COPY_TECH_SHEET_ACCEPTED);
        $this->assertSame('The scrutineer has reviewed & accepted your gear.', COPY_GEAR_ACCEPTED);
    }

    public function testReviewedByLine(): void
    {
        $this->assertSame('Reviewed by: Ivy Inspector', reviewedByLine(['name' => '  Ivy   Inspector ']));
        $this->assertSame('', reviewedByLine(null));
        $this->assertSame('', reviewedByLine(['name' => ' ']));
    }

    public function testReceivedHeadlineLeadsTheSheet(): void
    {
        $html = techSheetReceivedEmailHtml('<div>SHEET</div>');
        $this->assertStringContainsString(h(COPY_TECH_SHEET_RECEIVED), $html);
        $this->assertLessThan(strpos($html, 'SHEET'), strpos($html, 'Tech sheet received'));
    }

    public function testDisclaimerIsGoneEverywhere(): void
    {
        foreach (glob(__DIR__ . '/../*.php') as $file) {
            $this->assertStringNotContainsString('TECH_ACCEPTANCE_DISCLAIMER', file_get_contents($file), basename($file));
            $this->assertStringNotContainsString('is not a certification', file_get_contents($file), basename($file));
        }
    }
}
