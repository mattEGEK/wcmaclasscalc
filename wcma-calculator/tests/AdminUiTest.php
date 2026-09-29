<?php
// wcma-calculator/tests/AdminUiTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../admin-ui.php';

use PHPUnit\Framework\TestCase;

final class AdminUiTest extends TestCase
{
    public function testEditTargetAcceptsIdsCodesAndNewOnly(): void
    {
        $this->assertSame('12', adminEditTarget(['edit' => '12']));
        $this->assertSame('NASCC', adminEditTarget(['edit' => 'NASCC']));
        $this->assertSame('new', adminEditTarget(['edit' => 'new']));
        $this->assertNull(adminEditTarget(['edit' => '<x>']));
        $this->assertNull(adminEditTarget(['edit' => '']));
        $this->assertNull(adminEditTarget(['edit' => ['a']]));
        $this->assertNull(adminEditTarget([]));
    }

    public function testAnErrorFromAModalGoesInsideIt(): void
    {
        $err = ['type' => 'error', 'message' => 'Enter a name.'];
        $ok = ['type' => 'success', 'message' => 'Saved.'];
        $this->assertSame(['top' => null, 'dialog' => $err], adminFlashPlacement($err, '3', ['3', '4']));
        $this->assertSame(['top' => $ok, 'dialog' => null], adminFlashPlacement($ok, '3', ['3']));
        $this->assertSame(['top' => $err, 'dialog' => null], adminFlashPlacement($err, null, ['3']));
        $this->assertSame(['top' => null, 'dialog' => null], adminFlashPlacement(null, '3', ['3']));
    }

    public function testAnErrorForAModalThatIsNotOnThePageStillShowsAtTheTop(): void
    {
        $err = ['type' => 'error', 'message' => 'Enter a name.'];
        $this->assertSame(['top' => $err, 'dialog' => null], adminFlashPlacement($err, '99', ['3', '4']));
    }

    public function testDialogOpensOnLoadOnlyWhenItCarriesAMessage(): void
    {
        $plain = adminDialogHtml('user-dialog-3', 'Edit <Jo>', '<form></form>', null, 'jo@example.com');
        $this->assertStringContainsString('<dialog class="admin-dialog" id="user-dialog-3" aria-labelledby="user-dialog-3-title">', $plain);
        $this->assertStringContainsString('<h2 id="user-dialog-3-title">Edit &lt;Jo&gt;</h2>', $plain);
        $this->assertStringContainsString('jo@example.com', $plain);
        $this->assertStringNotContainsString('data-open-on-load', $plain);

        $withError = adminDialogHtml('user-dialog-3', 'Edit Jo', '<form></form>', ['type' => 'error', 'message' => 'Add a <last> name.']);
        $this->assertStringContainsString('data-open-on-load', $withError);
        $this->assertStringContainsString('role="alert">Add a &lt;last&gt; name.</div>', $withError);
        $this->assertLessThan(strpos($withError, '<form>'), strpos($withError, 'role="alert"'));
    }

    public function testEditAndAddButtonsPointAtTheirDialog(): void
    {
        $edit = adminEditButton('user-dialog-3', 'Jordan Lee');
        $this->assertStringContainsString('type="button"', $edit);
        $this->assertStringContainsString('data-dialog-open="user-dialog-3"', $edit);
        $this->assertStringContainsString('aria-label="Edit Jordan Lee"', $edit);
        $this->assertStringContainsString('btn btn-secondary', $edit);
        $add = adminAddButton('event-dialog-new', 'Add event');
        $this->assertStringContainsString('data-dialog-open="event-dialog-new"', $add);
        $this->assertStringContainsString('btn btn-primary', $add);
    }

    public function testDialogActionsAreCancelThenSave(): void
    {
        $html = adminDialogActions('Save');
        $cancel = strpos($html, 'data-dialog-close>Cancel</button>');
        $save = strpos($html, '<button type="submit" class="btn btn-primary">Save</button>');
        $this->assertNotFalse($cancel);
        $this->assertNotFalse($save);
        $this->assertLessThan($save, $cancel);
        $this->assertStringContainsString('<button type="button" class="btn btn-secondary" data-dialog-close>', $html);
    }

    public function testChipIsASpanAndFieldLabelsItsControl(): void
    {
        $this->assertSame('<span class="admin-chip admin-chip--ok">Active</span>', adminChip('Active', 'ok'));
        $field = adminField('x-name', 'Name', '<input id="x-name">', true);
        $this->assertSame('<div class="admin-field admin-form-wide"><label for="x-name">Name</label><input id="x-name"></div>', $field);
    }
}
