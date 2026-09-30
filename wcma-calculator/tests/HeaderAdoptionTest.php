<?php
// wcma-calculator/tests/HeaderAdoptionTest.php — every page shares the hub header and stylesheet.
use PHPUnit\Framework\TestCase;

final class HeaderAdoptionTest extends TestCase
{
    private function pageFiles(): array {
        $files = [];
        foreach (glob(__DIR__ . '/../*.php') as $f) {
            if (preg_match('/<link rel="stylesheet" href="<\?= hubAsset\(\'css\/calculator\.css\'\) \?>">/', file_get_contents($f))) $files[] = $f;
        }
        return $files;
    }

    public function testEveryPageThatLoadsCalculatorCssAlsoLoadsHubCssAfterIt(): void
    {
        $this->assertNotEmpty($this->pageFiles());
        foreach ($this->pageFiles() as $f) {
            $src = file_get_contents($f);
            $this->assertMatchesRegularExpression('/hubAsset\(\'css\/calculator\.css\'\) \?>">\s*\n?\s*<link rel="stylesheet" href="<\?= hubAsset\(\'css\/hub\.css\'\) \?>">/', $src, basename($f));
        }
    }

    public function testNoPageLinksAnUnversionedStylesheet(): void
    {
        foreach (glob(__DIR__ . '/../*.php') as $f) {
            $this->assertDoesNotMatchRegularExpression('/href="css\/[\w-]+\.css"/', file_get_contents($f), basename($f));
        }
    }

    public function testHubAssetAppendsTheFileModificationTime(): void
    {
        require_once __DIR__ . '/../view_helpers.php';
        $this->assertSame('css/hub.css?v=' . filemtime(__DIR__ . '/../css/hub.css'), hubAsset('css/hub.css'));
        $this->assertSame('css/missing.css', hubAsset('css/missing.css'));
    }

    public function testNoPageStillUsesTheOldCommonNav(): void
    {
        foreach (glob(__DIR__ . '/../*.php') as $f) {
            $this->assertStringNotContainsString('renderCommonNav(', file_get_contents($f), basename($f));
        }
    }

    public function testNoPageUsesTheOldBoxedShell(): void
    {
        foreach (glob(__DIR__ . '/../*.php') as $f) {
            $src = file_get_contents($f);
            $this->assertStringNotContainsString('renderSiteHeader(', $src, basename($f));
            $this->assertStringNotContainsString('<div class="container">', $src, basename($f));
        }
    }
}
