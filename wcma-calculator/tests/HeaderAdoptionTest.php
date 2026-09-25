<?php
// wcma-calculator/tests/HeaderAdoptionTest.php — every page shares the hub header and stylesheet.
use PHPUnit\Framework\TestCase;

final class HeaderAdoptionTest extends TestCase
{
    private function pageFiles(): array {
        $files = [];
        foreach (glob(__DIR__ . '/../*.php') as $f) {
            if (preg_match('/<link rel="stylesheet" href="css\/calculator\.css">/', file_get_contents($f))) $files[] = $f;
        }
        return $files;
    }

    public function testEveryPageThatLoadsCalculatorCssAlsoLoadsHubCssAfterIt(): void
    {
        $this->assertNotEmpty($this->pageFiles());
        foreach ($this->pageFiles() as $f) {
            $src = file_get_contents($f);
            $this->assertMatchesRegularExpression('/css\/calculator\.css">\s*\n?\s*<link rel="stylesheet" href="css\/hub\.css">/', $src, basename($f));
        }
    }

    public function testNoPageStillUsesTheOldCommonNav(): void
    {
        foreach (glob(__DIR__ . '/../*.php') as $f) {
            $this->assertStringNotContainsString('renderCommonNav(', file_get_contents($f), basename($f));
        }
    }

    public function testSiteHeaderRendersHubHeaderSubnavAndTitle(): void
    {
        require_once __DIR__ . '/../view_helpers.php';
        require_once __DIR__ . '/../layout.php';
        $GLOBALS['TEST_CURRENT_USER'] = ['id' => 1, 'name' => 'Jordan Lee', 'role' => 'user'];
        ob_start();
        renderSiteHeader('My Cars', '<a href="account.php">← Back</a>', 'garage');
        $html = ob_get_clean();
        unset($GLOBALS['TEST_CURRENT_USER']);

        $this->assertStringContainsString('class="hub-header"', $html);
        $this->assertStringContainsString('<span class="hub-nav-current" aria-current="page">Garage</span>', $html);
        $this->assertStringContainsString('class="hub-subnav"', $html);
        $this->assertStringContainsString('← Back', $html);
        $this->assertStringContainsString('<h1 class="hub-page-title">My Cars</h1>', $html);
    }

    public function testEveryPageThatCallsRenderSiteHeaderAlsoCallsRenderSiteFooterBeforeEveryBody(): void
    {
        $checked = 0;
        foreach (glob(__DIR__ . '/../*.php') as $f) {
            $base = basename($f);
            if ($base === 'layout.php' || $base === 'view_helpers.php') continue;
            $src = file_get_contents($f);
            if (strpos($src, 'renderSiteHeader(') === false) continue;
            $checked++;
            $bodyCloseCount = substr_count($src, '</body>');
            $footerCallCount = substr_count($src, 'renderSiteFooter()');
            $this->assertGreaterThanOrEqual($bodyCloseCount, $footerCallCount, $base);
        }
        $this->assertGreaterThan(0, $checked);
    }
}
