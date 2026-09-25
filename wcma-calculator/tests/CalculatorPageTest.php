<?php
// wcma-calculator/tests/CalculatorPageTest.php — source-level guards (calculator.php needs a session).
use PHPUnit\Framework\TestCase;

final class CalculatorPageTest extends TestCase
{
    private function src(string $f): string { return file_get_contents(__DIR__ . '/../' . $f); }

    public function testCalculatorUsesTheLayoutAndKeepsTheCalculatorScripts(): void
    {
        $src = $this->src('calculator.php');
        $this->assertStringContainsString("renderPageStart('Class Calculator', 'calculator'", $src);
        foreach (['js/calculator.js', 'js/form-handler.js', 'js/ui-controller.js', 'js/car-picker.js', 'id="classing-form"', 'id="competition-weight"', 'id="declared-hp"'] as $needle) {
            $this->assertStringContainsString($needle, $src, $needle);
        }
        $this->assertStringNotContainsString("fetch('session-status.php')\n        .then(function (res)", $src);   // old inline nav script gone
        $this->assertStringContainsString('db_get_user_car(', $src);
        $this->assertStringContainsString('calc-car-banner', $src);
    }

    public function testOldUrlRedirectsKeepingTheQueryString(): void
    {
        $html = $this->src('car-classing.html');
        $this->assertStringContainsString("location.replace('calculator.php' + location.search)", $html);
        $this->assertStringContainsString('url=calculator.php', $html);
        $this->assertStringNotContainsString('classing-form', $html);
    }

    public function testNoCodeStillLinksToTheOldPage(): void
    {
        foreach (array_merge(glob(__DIR__ . '/../*.php'), glob(__DIR__ . '/../js/*.js')) as $f) {
            $this->assertStringNotContainsString('car-classing.html', file_get_contents($f), basename($f));
        }
    }

    public function testRedirectWhitelist(): void
    {
        require_once __DIR__ . '/../view_helpers.php';
        $src = $this->src('auth.php');
        $start = strpos($src, 'function safeRedirectTarget');
        $end = strpos($src, "\n}\n", $start);
        eval(substr($src, $start, $end - $start + 2));
        foreach (['index.php', 'calculator.php', 'calculator.php?car=3', 'calculator.php?restore=1', 'calculator.php?draft=9', 'profile.php', 'account.php', 'admin.php'] as $ok) {
            $this->assertSame($ok, safeRedirectTarget($ok), $ok);
        }
        foreach (['https://evil.test', '//evil.test', 'calculator.php?car=x', 'car-classing.html'] as $bad) {
            $this->assertSame('index.php', safeRedirectTarget($bad), $bad);
        }
    }
}
