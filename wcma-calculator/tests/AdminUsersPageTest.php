<?php
// wcma-calculator/tests/AdminUsersPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../admin-ui.php';
require_once __DIR__ . '/../admin-users.php';

use PHPUnit\Framework\TestCase;

final class AdminUsersPageTest extends TestCase
{
    private function users(): array {
        return [
            ['id' => 1, 'email' => 'boss@example.com', 'name' => 'Site Admin', 'role' => 'admin', 'password_hash' => 'x', 'google_id' => 'g',
             'active' => 1, 'is_media' => 0, 'created_at' => '2026-09-01 10:00:00'],
            ['id' => 3, 'email' => 'ivy@example.com', 'name' => 'Ivy', 'role' => 'inspector', 'password_hash' => 'x', 'google_id' => null,
             'active' => 0, 'is_media' => 1, 'created_at' => '2026-09-02 10:00:00'],
        ];
    }

    public function testSaveRules(): void
    {
        $admin = ['role' => 'admin'];
        $user = ['role' => 'user'];
        $this->assertSame('Cannot change the role of the last remaining admin.', adminUserSaveError($admin, 'Site Admin', 'inspector', 1));
        $this->assertNull(adminUserSaveError($admin, 'Site Admin', 'inspector', 2));
        $this->assertSame('Enter a name of 100 characters or fewer.', adminUserSaveError($user, '', 'user', 1));
        $this->assertSame('Enter a name of 100 characters or fewer.', adminUserSaveError($user, str_repeat('a', 101), 'user', 1));
        $this->assertSame('Choose a role.', adminUserSaveError($user, 'Jo Lee', 'boss', 1));
        $this->assertStringStartsWith('Add a first and last name before giving this account the inspector role.', adminUserSaveError($user, 'Ivy', 'inspector', 1));
        $this->assertNull(adminUserSaveError($user, 'Ivy', 'user', 1));
        $this->assertNull(adminUserSaveError($user, 'Ivy Inspector', 'inspector', 1));
        $this->assertSame('Ivy Inspector', adminNormalizeName("  Ivy \t Inspector "));
    }

    public function testRowsAreReadOnlyWithOneEditButtonAndModalsSitAfterTheTable(): void
    {
        $html = renderUsersPageHtml($this->users(), [1 => 4], 'tok', null, null);
        $this->assertSame(2, substr_count($html, 'data-dialog-open="user-dialog-'));
        $this->assertSame(2, substr_count($html, '<dialog '));
        $this->assertLessThan(strpos($html, '<dialog '), strpos($html, '</table>'));
        $this->assertSame(2, substr_count($html, 'action="admin.php?action=user-save"'));
        $this->assertStringNotContainsString('action=set-', $html);
        $this->assertStringContainsString('<tr id="user-3" data-role="inspector">', $html);
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
        $this->assertStringContainsString('Password · Google', $html);
    }

    public function testChipsAreSpansNotCellClasses(): void
    {
        $html = renderUsersPageHtml($this->users(), [], 'tok', null, null);
        $this->assertDoesNotMatchRegularExpression('/<td[^>]*class="badge-/', $html);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--ink">Admin</span>', $html);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--pending">Media</span>', $html);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--fail">Needs first &amp; last name</span>', $html);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--fail">Inactive</span>', $html);
    }

    public function testModalSavesNameRoleAndMediaTogetherAndKeepsDeactivateApart(): void
    {
        $html = adminUserDialogHtml($this->users()[0], 'tok', null);
        $save = substr($html, strpos($html, 'action="admin.php?action=user-save"'));
        $save = substr($save, 0, strpos($save, '</form>'));
        $this->assertStringContainsString('name="name" value="Site Admin"', $save);
        $this->assertStringContainsString('name="role" value="admin" checked', $save);
        $this->assertStringContainsString('name="is_media" value="1">', $save);   // not ticked
        $this->assertStringNotContainsString('Deactivate', $save);
        $this->assertStringContainsString('<section class="admin-dialog-danger"><h3>Deactivate this account</h3>', $html);
        $this->assertStringContainsString('action="admin.php?action=deactivate" data-confirm="Deactivate boss@example.com?', $html);
        $inactive = adminUserDialogHtml($this->users()[1], 'tok', null);
        $this->assertStringContainsString('action="admin.php?action=activate"', $inactive);
        $this->assertStringContainsString('name="is_media" value="1" checked', $inactive);
    }

    public function testAnErrorReopensThatUsersModalWithTheMessageInside(): void
    {
        $html = renderUsersPageHtml($this->users(), [], 'tok', ['type' => 'error', 'message' => 'Choose a role.'], '3');
        $this->assertSame(1, substr_count($html, 'data-open-on-load'));
        $this->assertMatchesRegularExpression('/id="user-dialog-3"[^>]*data-open-on-load>.*Choose a role\./s', $html);
    }
}
