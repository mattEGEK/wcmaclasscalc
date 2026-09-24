<?php
// wcma-calculator/tests/AdminTechCopyTest.php
use PHPUnit\Framework\TestCase;

final class AdminTechCopyTest extends TestCase
{
    public function testAdminTechPageOnlyUsesTheVerbatimDisclaimer(): void {
        $src = file_get_contents(__DIR__ . '/../admin-tech-sheets.php');
        $this->assertStringNotContainsString('is not a certification', $src);
        $this->assertDoesNotMatchRegularExpression('/\bsafe\b/i', $src);
        $this->assertStringContainsString('TECH_ACCEPTANCE_DISCLAIMER', $src);
    }

    public function testPhotoReviewCardHasNoBannedWording(): void
    {
        $source = file_get_contents(__DIR__ . '/../admin-tech-sheets.php');
        $start = strpos($source, 'function renderPretechReviewCard');
        $this->assertNotFalse($start, 'renderPretechReviewCard must exist');
        $card = substr($source, $start);
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed)\b/i', $card);
        $this->assertDoesNotMatchRegularExpression('/\bsafe\b/i', $card);
    }
}
