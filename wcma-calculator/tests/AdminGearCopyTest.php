<?php
// wcma-calculator/tests/AdminGearCopyTest.php
//
// Source-level guards for admin-gear.php (its pages need config.php, so they cannot run under
// PHPUnit): the terminology rule and the routing/authorisation shape.
use PHPUnit\Framework\TestCase;

final class AdminGearCopyTest extends TestCase
{
    private function src(string $file): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
    }

    public function testGearAdminHasNoBannedWording(): void
    {
        $source = $this->src('admin-gear.php');
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed)\b/i', $source);
        $this->assertDoesNotMatchRegularExpression('/\bsafe\b/i', $source);
    }

    public function testGearRoutesLiveInTheInspectorSectionAndPostRoutesAreGated(): void
    {
        $inspect = $this->src('inspect.php');
        $admin = $this->src('admin.php');
        foreach (['gear', 'gear-record', 'gear-record-accept', 'gear-record-revoke', 'gear-photos-accept', 'gear-photos-send-back'] as $route) {
            $this->assertStringContainsString("case '$route':", $inspect, $route);
            $this->assertStringNotContainsString("case '$route':", $admin, $route);
        }
        foreach (['gear-record-accept', 'gear-record-revoke', 'gear-photos-accept', 'gear-photos-send-back'] as $route) {
            $this->assertContains($route, INSPECT_POST_ACTIONS, $route);
        }
    }

    public function testIceGearRecordsBackLinkGoesToTheRosterNotTheGearList(): void
    {
        $src = $this->src('admin-gear.php');
        $this->assertStringContainsString("\$isIce ? 'inspect.php' : 'inspect.php?action=gear&amp;season=' . (int)\$gear['season']", $src);
    }

    public function testNoHardCodedAdminNavStringsRemain(): void
    {
        foreach (['admin.php', 'admin-feedback.php', 'admin-tech-sheets.php', 'admin-gear.php'] as $file) {
            $src = $this->src($file);
            $this->assertStringNotContainsString('<a href="admin.php?action=users">Manage Users</a>', $src, $file);
            $this->assertStringNotContainsString('FEEDBACK_ADMIN_NAV', $src, $file);
            $this->assertStringNotContainsString('ADMIN_TECH_NAV', $src, $file);
        }
    }
}
