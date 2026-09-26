<?php
// wcma-calculator/tests/RolesTest.php
require_once __DIR__ . '/../roles.php';

use PHPUnit\Framework\TestCase;

final class RolesTest extends TestCase
{
    public function testRoleHierarchy(): void
    {
        $this->assertFalse(user_has_role(null, 'user'));
        $this->assertTrue(user_has_role(['role' => 'user'], 'user'));
        $this->assertFalse(user_has_role(['role' => 'user'], 'inspector'));
        $this->assertTrue(user_has_role(['role' => 'inspector'], 'inspector'));
        $this->assertFalse(user_has_role(['role' => 'inspector'], 'admin'));
        $this->assertTrue(user_has_role(['role' => 'admin'], 'inspector'));
        $this->assertFalse(user_has_role(['role' => 'bogus'], 'user'));
    }

    public function testInspectorsGetEventDayWorkAndAdminsKeepTheBackOffice(): void
    {
        foreach (['list', 'view', 'file', 'resend', 'export', 'tech-sheets', 'tech-sheet', 'tech-sheet-accept',
                  'tech-sheet-revoke', 'tech-sheet-sig', 'tech-sheet-photos-accept', 'tech-sheet-photos-send-back',
                  'gear', 'gear-record', 'gear-record-accept', 'gear-record-revoke', 'gear-photos-accept',
                  'gear-photos-send-back', 'gear-create-accept'] as $action) {
            $this->assertSame('inspector', adminActionMinRole($action), $action);
        }
        foreach (['update-contact', 'delete', 'bulk-delete', 'users', 'set-role', 'set-name', 'deactivate', 'activate',
                  'events', 'event-create', 'settings', 'settings-update', 'feedback', 'season-links', 'anything-new'] as $action) {
            $this->assertSame('admin', adminActionMinRole($action), $action);
        }
    }

    public function testRoleGateOutcome(): void
    {
        $this->assertSame('login', roleGateOutcome(null, 'user'));
        $this->assertSame('login', roleGateOutcome(null, 'admin'));
        $this->assertSame('forbidden', roleGateOutcome(['role' => 'inspector'], 'admin'));
        $this->assertSame('forbidden', roleGateOutcome(['role' => 'user'], 'inspector'));
        $this->assertSame('ok', roleGateOutcome(['role' => 'inspector'], 'inspector'));
        $this->assertSame('ok', roleGateOutcome(['role' => 'admin'], 'inspector'));
    }

    public function testFirstAndLastName(): void
    {
        $this->assertTrue(userHasFirstAndLastName('Ivy Inspector'));
        $this->assertTrue(userHasFirstAndLastName('  Mary  Ann Smith '));
        $this->assertFalse(userHasFirstAndLastName('Ivy'));
        $this->assertFalse(userHasFirstAndLastName('   '));
    }

    public function testInspectorSectionIsOpenToInspectorsExceptDeclarationHousekeeping(): void
    {
        foreach (['roster', 'queue', 'classing', 'declaration', 'declaration-accept', 'declaration-send-back',
                  'declaration-resend', 'gear-create-accept', 'tech-sheet-accept', 'anything-new'] as $action) {
            $this->assertSame('inspector', inspectActionMinRole($action), $action);
        }
        foreach (['declaration-delete', 'declarations-bulk-delete', 'declaration-update-contact'] as $action) {
            $this->assertSame('admin', inspectActionMinRole($action), $action);
            $this->assertContains($action, INSPECT_POST_ACTIONS, $action);
        }
        foreach (['roster', 'queue', 'classing', 'declaration', 'declaration-file', 'declarations-export',
                  'tech-sheet', 'tech-sheet-sig', 'gear', 'gear-record'] as $action) {
            $this->assertNotContains($action, INSPECT_POST_ACTIONS, $action);
        }
    }

    public function testMovedAdminActionsRedirectToTheInspectorSection(): void
    {
        $this->assertSame('inspect.php?action=tech-sheet&id=12', adminMovedActionUrl('tech-sheet', ['action' => 'tech-sheet', 'id' => '12', 'x' => 'y']));
        $this->assertSame('inspect.php?action=roster&event=3', adminMovedActionUrl('tech-sheets', ['event' => '3', 'filter' => 'needs_tech']));
        $this->assertSame('inspect.php?action=roster', adminMovedActionUrl('tech-sheets', ['event' => ['x']]));
        $this->assertSame('inspect.php?action=tech-sheet-sig&id=4&which=tech', adminMovedActionUrl('tech-sheet-sig', ['id' => '4', 'which' => 'tech']));
        $this->assertNull(adminMovedActionUrl('users', []));
        $this->assertNull(adminMovedActionUrl('tech-sheet-accept', ['id' => '4']));   // POSTs are not redirected
    }

    public function testMovedGearActionsRedirect(): void
    {
        $this->assertSame('inspect.php?action=gear&season=2026&filter=accepted', adminMovedActionUrl('gear', ['season' => '2026', 'filter' => 'accepted']));
        $this->assertSame('inspect.php?action=gear-record&id=9', adminMovedActionUrl('gear-record', ['id' => '9']));
        $this->assertNull(adminMovedActionUrl('gear-create-accept', []));
    }

    public function testMovedClassingActionsRedirect(): void
    {
        $this->assertSame('inspect.php?action=classing', adminMovedActionUrl('list', ['sort' => 'name', 'dir' => 'asc']));
        $this->assertSame('inspect.php?action=declaration&id=7', adminMovedActionUrl('view', ['id' => '7']));
        $this->assertSame('inspect.php?action=declaration-file&id=7&field=car_image', adminMovedActionUrl('file', ['id' => '7', 'field' => 'car_image']));
        $this->assertSame('inspect.php?action=declarations-export', adminMovedActionUrl('export', ['sort' => 'name']));
        $this->assertNull(adminMovedActionUrl('delete', ['id' => '7']));
    }
}
