<?php
// wcma-calculator/tests/PretechPageTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../pretech-lib.php';
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../pretech-page.php';

use PHPUnit\Framework\TestCase;

// The page header calls current_user()/is_admin() (session_bootstrap.php, which starts a session and
// is not loaded in unit tests). A signed-out stub is enough to render the layout.
if (!function_exists('current_user')) {
    function current_user(): ?array { return null; }
}
if (!function_exists('is_admin')) {
    function is_admin(): bool { return false; }
}

final class PretechPageTest extends TestCase
{
    private function sheet(array $o = []): array {
        return array_merge([
            'id' => 12, 'user_id' => 1, 'event_id' => 3, 'car_number' => '42', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'entrant_name' => 'Jane', 'status' => 'submitted', 'photo_status' => null, 'season' => 2026,
        ], $o);
    }

    private function snapshot(array $photos = [], array $applicable = []): array {
        $present = array_keys(array_filter($photos, fn($p) => $p['file_path'] !== ''));
        return ['photos' => $photos, 'present' => $present, 'applicable' => $applicable, 'missing' => photoSetMissingRequired('car', $present, $applicable)];
    }

    private function photoRow(string $key, array $o = []): array {
        return array_merge([
            'id' => 5, 'requirement_key' => $key, 'file_path' => 'uploads/x.jpg', 'typed_value' => null,
            'review_status' => 'pending', 'reviewer_note' => null, 'applies' => 1,
        ], $o);
    }

    private function render(array $sheet, array $mode, array $snapshot, ?array $flash = null): string {
        ob_start();
        renderPretechPage($sheet, ['name' => 'Spring Sprint', 'event_date' => '2026-05-10'], $mode, $snapshot, 'csrf-token-1', $flash);
        return (string)ob_get_clean();
    }

    public function testFormModeShowsACardForEveryRequirementAndAnEmptyProgress(): void
    {
        $html = $this->render($this->sheet(), ['mode' => 'this_sheet', 'sheet_id' => null], $this->snapshot());

        $this->assertSame(count(photoRequirements('car')), substr_count($html, 'class="pretech-card"'));
        foreach (['front_34', 'harness_date', 'ballast'] as $key) {
            $this->assertStringContainsString('data-key="' . $key . '"', $html);
        }
        $this->assertStringContainsString('data-tier="conditional"', $html);
        $this->assertStringContainsString('0 of 15 required photos', $html);
        $this->assertStringContainsString('id="pretech-submit-btn"', $html);
        $this->assertMatchesRegularExpression('/id="pretech-submit-btn"[^>]*disabled/', $html);
        $this->assertStringContainsString('name="csrf_token" value="csrf-token-1"', $html);
        $this->assertStringContainsString('data-photo-input', $html);
        $this->assertStringContainsString('window.PRETECH_STATE', $html);
        $this->assertStringContainsString('js/pretech-form.js', $html);
    }

    public function testCompleteSetEnablesSubmitAndShowsPhotosAndRetakeNotes(): void
    {
        $photos = [];
        foreach (photoRequirements('car') as $key => $def) {
            if ($def['tier'] === 'required') $photos[$key] = $this->photoRow($key, ['id' => count($photos) + 1]);
        }
        $photos['front_34']['review_status'] = 'retake';
        $photos['front_34']['reviewer_note'] = 'Car number is hidden <behind a cone>';
        $photos['harness_date']['typed_value'] = '{"date":"05/2025"}';

        $html = $this->render($this->sheet(['photo_status' => 'needs_changes']), ['mode' => 'this_sheet', 'sheet_id' => null], $this->snapshot($photos));

        $this->assertStringContainsString('15 of 15 required photos', $html);
        $this->assertDoesNotMatchRegularExpression('/id="pretech-submit-btn"[^>]*disabled/', $html);
        $this->assertStringContainsString('Retake requested', $html);
        $this->assertStringContainsString('Car number is hidden &lt;behind a cone&gt;', $html);
        $this->assertStringNotContainsString('<behind a cone>', $html);
        $this->assertStringContainsString('value="05/2025"', $html);
        $this->assertStringContainsString('inspection.php?action=photo&amp;id=1', $html);
    }

    public function testLockedSheetIsReadOnly(): void
    {
        $html = $this->render($this->sheet(['photo_status' => 'submitted']), ['mode' => 'this_sheet', 'sheet_id' => null], $this->snapshot());
        $this->assertStringContainsString('submitted for review', $html);
        $this->assertStringNotContainsString('data-photo-input', $html);
        $this->assertStringNotContainsString('id="pretech-submit-btn"', $html);
        $this->assertStringContainsString('"locked":true', $html);
    }

    public function testCarAcceptedAndHeldElsewhereModesShowOnlyABanner(): void
    {
        $accepted = $this->render($this->sheet(), ['mode' => 'car_accepted', 'sheet_id' => 9], $this->snapshot());
        $this->assertStringContainsString('already teched', $accepted);
        $this->assertStringNotContainsString('pretech-card', $accepted);

        $elsewhere = $this->render($this->sheet(), ['mode' => 'held_elsewhere', 'sheet_id' => 9], $this->snapshot());
        $this->assertStringContainsString('tech-sheets.php?action=pretech&amp;id=9', $elsewhere);
        $this->assertStringNotContainsString('pretech-card', $elsewhere);
    }

    public function testApplicableConditionalIsCheckedAndCounted(): void
    {
        $html = $this->render($this->sheet(['photo_status' => 'draft']), ['mode' => 'this_sheet', 'sheet_id' => null],
            $this->snapshot(['ballast' => $this->photoRow('ballast', ['file_path' => ''])], ['ballast']));
        $this->assertStringContainsString('0 of 16 required photos', $html);
        $this->assertMatchesRegularExpression('/data-applies-toggle[^>]*checked/', $html);
    }

    public function testCopyAvoidsBannedWording(): void
    {
        $html = $this->render($this->sheet(), ['mode' => 'this_sheet', 'sheet_id' => null], $this->snapshot());
        $text = strip_tags(preg_replace('/<script.*?<\/script>/s', '', $html));
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $text);
    }
}
