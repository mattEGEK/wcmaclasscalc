<?php
// wcma-calculator/tests/AdminTechCopyTest.php
use PHPUnit\Framework\TestCase;

final class AdminTechCopyTest extends TestCase
{
    public function testAdminTechPageHasNoCertificationOrSafetyWording(): void {
        $src = file_get_contents(__DIR__ . '/../admin-tech-sheets.php');
        $this->assertStringNotContainsString('is not a certification', $src);
        $this->assertStringNotContainsString('TECH_ACCEPTANCE_DISCLAIMER', $src);
        $this->assertDoesNotMatchRegularExpression('/\bsafe\b/i', $src);
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

    public function testPhotoReviewCardUsesOnlyCurrentPhotos(): void
    {
        $source = file_get_contents(__DIR__ . '/../admin-tech-sheets.php');
        $start = strpos($source, 'function renderPretechReviewCard');
        $this->assertNotFalse($start, 'renderPretechReviewCard must exist');
        $card = substr($source, $start, 400);
        $this->assertStringContainsString('pretechCurrentPhotos(', $card);
    }
}
