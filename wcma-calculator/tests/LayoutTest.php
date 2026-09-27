<?php
// wcma-calculator/tests/LayoutTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../layout.php';

use PHPUnit\Framework\TestCase;

final class LayoutTest extends TestCase
{
    private function keys(?array $user): array {
        return array_column(hubNavItems($user), 'key');
    }

    public function testNavItemsByAudience(): void
    {
        $this->assertSame(['home', 'calculator', 'signin'], $this->keys(null));
        $this->assertSame(['home', 'garage', 'drivers', 'calculator'], $this->keys(['id' => 1, 'name' => 'Jordan Lee', 'role' => 'user', 'is_media' => 0]));
        $this->assertSame(['home', 'garage', 'drivers', 'calculator', 'inspect'], $this->keys(['id' => 1, 'name' => 'Ivy Inspector', 'role' => 'inspector', 'is_media' => 0]));
        $this->assertSame(['home', 'garage', 'drivers', 'calculator', 'inspect', 'media', 'admin'], $this->keys(['id' => 1, 'name' => 'Site Admin', 'role' => 'admin', 'is_media' => 0]));

        $admin = array_column(hubNavItems(['id' => 1, 'name' => 'Site Admin', 'role' => 'admin', 'is_media' => 0]), 'href', 'label');
        $this->assertSame('inspect.php', $admin['Inspector']);
        $this->assertSame('media.php', $admin['Media']);
        $this->assertSame('admin.php', $admin['Admin']);
        $this->assertSame('calculator.php', $admin['Class Calculator']);
    }

    public function testStaffSectionsSitAfterADivider(): void
    {
        $html = hubNavHtml(['id' => 1, 'name' => 'Site Admin', 'role' => 'admin', 'is_media' => 0], 'inspect');
        $this->assertSame(3, substr_count($html, '<li class="hub-nav-staff">'));
        $this->assertStringContainsString('<li class="hub-nav-staff"><span class="hub-nav-current" aria-current="page">Inspector</span></li>', $html);
        $this->assertStringContainsString('<li class="hub-nav-staff"><a href="media.php">Media</a></li>', $html);
        $this->assertStringContainsString('<li class="hub-nav-staff"><a href="admin.php">Admin</a></li>', $html);
        $this->assertStringContainsString('<li><a href="garage.php">Garage</a></li>', $html);
    }

    public function testSectionTabsMarkTheCurrentTab(): void
    {
        $html = inspectSubnavHtml('queue');
        $this->assertStringContainsString('<nav class="hub-tabs" aria-label="Inspector">', $html);
        $this->assertStringContainsString('<span class="hub-tab-current" aria-current="page">Review queue</span>', $html);
        foreach (['href="inspect.php">Event roster<', 'href="inspect.php?action=classing">Classing<', 'href="inspect.php?action=gear">Gear<'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsString('hub-tab-current', inspectSubnavHtml('nope'));

        $admin = adminSubnavHtml('events');
        $this->assertStringContainsString('<nav class="hub-tabs" aria-label="Admin">', $admin);
        $this->assertStringContainsString('<span class="hub-tab-current" aria-current="page">Events</span>', $admin);
        foreach (['Users &amp; roles', 'Season links', 'Settings', 'Feedback'] as $label) {
            $this->assertStringContainsString('>' . $label . '</a>', $admin);
        }
    }

    public function testForbiddenPageRendersInsideTheLayout(): void
    {
        $GLOBALS['TEST_CURRENT_USER'] = ['id' => 3, 'name' => 'Jordan Lee', 'role' => 'user'];
        ob_start();
        hubRenderForbidden();
        $html = ob_get_clean();
        unset($GLOBALS['TEST_CURRENT_USER']);

        $this->assertStringContainsString('class="hub-header"', $html);
        $this->assertStringContainsString('You don&#039;t have access to this page', $html);
        $this->assertStringContainsString('This page is for WCMA inspectors and admins', $html);
        $this->assertStringContainsString('<a class="hub-btn" href="index.php">Go to Home</a>', $html);

        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../session_bootstrap.php'));
        $this->assertMatchesRegularExpression("/case 'forbidden':\\s*http_response_code\\(403\\);\\s*require_once __DIR__ \\. '\\/view_helpers\\.php';[^\\n]*\\n\\s*hubRenderForbidden\\(\\);\\s*exit;/", $src);
    }

    public function testForbiddenPageSendsInspectorsToTheirOwnSection(): void
    {
        $GLOBALS['TEST_CURRENT_USER'] = ['id' => 4, 'name' => 'Ivy Inspector', 'role' => 'inspector'];
        ob_start();
        hubRenderForbidden();
        $html = ob_get_clean();
        unset($GLOBALS['TEST_CURRENT_USER']);

        $this->assertStringContainsString('You don&#039;t have access to this page', $html);
        $this->assertStringContainsString('This page is for WCMA admins. Your inspector tools are in the Inspector section.', $html);
        $this->assertStringContainsString('<a class="hub-btn" href="inspect.php">Go to the Inspector section</a>', $html);
        $this->assertStringNotContainsString('This page is for WCMA inspectors and admins', $html);
    }

    public function testCurrentSectionIsInertText(): void
    {
        $html = hubNavHtml(['id' => 1, 'name' => 'Jordan Lee', 'role' => 'user', 'is_media' => 0], 'home');
        $this->assertStringContainsString('<span class="hub-nav-current" aria-current="page">Home</span>', $html);
        $this->assertStringNotContainsString('href="index.php"', $html);
        $this->assertStringContainsString('href="garage.php"', $html);
        $this->assertStringContainsString('href="calculator.php"', $html);
        $this->assertStringContainsString('>Menu<', $html);                     // phone toggle is a labelled button
    }

    public function testAccountAndFooterAreEscapedAndComplete(): void
    {
        $html = hubAccountHtml(['id' => 1, 'name' => 'A <b>', 'role' => 'user', 'is_media' => 0]);
        $this->assertStringContainsString('A &lt;b&gt;', $html);
        $this->assertStringContainsString('href="profile.php"', $html);
        $this->assertStringContainsString('action=logout', $html);
        $this->assertSame('', hubAccountHtml(null));

        $footer = hubFooterHtml();
        foreach (['data-feedback-open', 'racing-regulations', 'https://www.wcma.ca'] as $needle) {
            $this->assertStringContainsString($needle, $footer);
        }
    }

    public function testPageShellLoadsBothStylesheetsInOrder(): void
    {
        ob_start();
        renderPageStart('Home', 'home', ['flash' => ['message' => 'Saved <ok>', 'type' => 'success']]);
        renderPageEnd();
        $html = ob_get_clean();

        $this->assertStringContainsString('<title>Home — WCMA Hub</title>', $html);
        $this->assertLessThan(strpos($html, 'css/hub.css'), strpos($html, 'css/calculator.css'));
        $this->assertStringContainsString('Saved &lt;ok&gt;', $html);
        $this->assertStringContainsString('js/nav.js', $html);
        $this->assertStringContainsString('class="hub-stripe"', $html);
        $this->assertStringContainsString('fonts.googleapis.com', $html);
    }

    public function testMediaNavShowsForMediaStaffAndAdminsOnly(): void
    {
        $keys = fn(?array $u): array => array_column(hubNavItems($u), 'key');
        $this->assertNotContains('media', $keys(['id' => 1, 'name' => 'A', 'role' => 'inspector', 'is_media' => 0]));
        $this->assertContains('media', $keys(['id' => 1, 'name' => 'A', 'role' => 'user', 'is_media' => 1]));
        $this->assertContains('media', $keys(['id' => 1, 'name' => 'A', 'role' => 'admin', 'is_media' => 0]));
        $admin = $keys(['id' => 1, 'name' => 'A', 'role' => 'admin', 'is_media' => 0]);
        $this->assertLessThan(array_search('admin', $admin, true), array_search('media', $admin, true));
    }

    public function testMediaTabs(): void
    {
        $html = mediaSubnavHtml('kit');
        $this->assertStringContainsString('<a href="media.php">Announcer</a>', $html);
        $this->assertStringContainsString('<span class="hub-tab-current" aria-current="page">Media kit</span>', $html);
        $this->assertStringContainsString('<a href="media.php?action=review">Public review</a>', $html);
    }
}
