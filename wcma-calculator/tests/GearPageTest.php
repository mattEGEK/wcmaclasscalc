<?php
// wcma-calculator/tests/GearPageTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../pretech-lib.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../pretech-page.php';
require_once __DIR__ . '/../gear-page.php';

use PHPUnit\Framework\TestCase;

// The page header calls current_user()/is_admin() (session_bootstrap.php starts a session and is
// not loaded in unit tests). A signed-out stub is enough to render the layout.
if (!function_exists('current_user')) {
    function current_user(): ?array { return null; }
}
if (!function_exists('is_admin')) {
    function is_admin(): bool { return false; }
}

final class GearPageTest extends TestCase
{
    private function gear(array $o = []): array {
        return array_merge([
            'id' => 4, 'owner_user_id' => 1, 'driver_name' => 'Jane <Racer>', 'driver_name_norm' => 'jane <racer>',
            'licence_no' => 'WCMA-1', 'season' => 2026, 'status' => 'open', 'photo_status' => null, 'accepted_via' => null,
        ], $o);
    }

    private function snapshot(array $photos = [], array $applicable = []): array {
        $present = array_keys(array_filter($photos, fn($p) => $p['file_path'] !== ''));
        return ['photos' => $photos, 'present' => $present, 'applicable' => $applicable, 'missing' => photoSetMissingRequired('gear', $present, $applicable)];
    }

    private function photoRow(string $key, array $o = []): array {
        return array_merge([
            'id' => 5, 'requirement_key' => $key, 'file_path' => 'uploads/x.jpg', 'typed_value' => null,
            'review_status' => 'pending', 'reviewer_note' => null, 'applies' => 1,
        ], $o);
    }

    private function renderList(array $records, int $season = 2026, ?array $flash = null): string {
        ob_start();
        renderGearListPage($records, $season, 'csrf-token-1', $flash);
        return (string)ob_get_clean();
    }

    private function renderPretech(array $gear, array $snapshot, ?array $flash = null): string {
        ob_start();
        renderGearPretechPage($gear, $snapshot, 'csrf-token-1', $flash);
        return (string)ob_get_clean();
    }

    public function testListShowsAddFormRecordsAndEscapesNames(): void
    {
        $html = $this->renderList([$this->gear()]);
        $this->assertStringContainsString('id="gear-add-form"', $html);
        $this->assertStringContainsString('name="driver_name"', $html);
        $this->assertStringContainsString('name="licence_no"', $html);
        $this->assertStringContainsString('name="csrf_token" value="csrf-token-1"', $html);
        $this->assertStringContainsString('id="gear-table"', $html);
        $this->assertStringContainsString('Jane &lt;Racer&gt;', $html);
        $this->assertStringNotContainsString('<Racer>', $html);
        $this->assertStringContainsString('WCMA-1', $html);
        $this->assertStringContainsString('Needs gear check at the track', $html);
        $this->assertStringContainsString('gear.php?action=pretech&amp;id=4', $html);
    }

    public function testListShowsStatusChipsPerRecord(): void
    {
        $html = $this->renderList([
            $this->gear(['id' => 1, 'driver_name' => 'A', 'status' => 'accepted', 'accepted_via' => 'photos', 'photo_status' => 'accepted']),
            $this->gear(['id' => 2, 'driver_name' => 'B', 'status' => 'accepted', 'accepted_via' => 'in_person']),
            $this->gear(['id' => 3, 'driver_name' => 'C', 'photo_status' => 'submitted']),
        ]);
        $this->assertStringContainsString('Gear pre-teched 2026', $html);
        $this->assertStringContainsString('Gear teched 2026', $html);
        $this->assertStringContainsString('Photos pending review', $html);
    }

    public function testEmptyListSaysSo(): void
    {
        $this->assertStringContainsString('No drivers yet', $this->renderList([]));
    }

    public function testPreviousSeasonRecordsOfferRenewOnlyWhenNoCurrentRecordExists(): void
    {
        $old = $this->gear(['id' => 9, 'season' => 2025, 'driver_name' => 'Old Timer', 'driver_name_norm' => 'old timer']);
        $current = $this->gear(['id' => 10, 'season' => 2026, 'driver_name' => 'Jane Racer', 'driver_name_norm' => 'jane racer']);
        $oldSame = $this->gear(['id' => 11, 'season' => 2025, 'driver_name' => 'Jane Racer', 'driver_name_norm' => 'jane racer']);

        $html = $this->renderList([$current, $old, $oldSame], 2026);
        $this->assertStringContainsString('Renew for 2026', $html);
        $this->assertSame(1, substr_count($html, 'action="gear.php?action=renew"'));   // Old Timer only: Jane already has a 2026 record
        $this->assertStringContainsString('name="id" value="9"', $html);
        $this->assertStringNotContainsString('name="id" value="11"', $html);
    }

    public function testPretechFormShowsACardForEveryGearRequirement(): void
    {
        $html = $this->renderPretech($this->gear(), $this->snapshot());

        $this->assertSame(count(photoRequirements('gear')), substr_count($html, 'class="pretech-card"'));
        foreach (['helmet_label', 'suit_label', 'fhr_label', 'gear_flatlay', 'helmet_back', 'underwear_label'] as $key) {
            $this->assertStringContainsString('data-key="' . $key . '"', $html);
        }
        $this->assertStringContainsString('data-tier="recommended"', $html);
        $this->assertStringContainsString('data-tier="conditional"', $html);
        $this->assertStringContainsString('0 of 4 required photos', $html);
        $this->assertMatchesRegularExpression('/id="pretech-submit-btn"[^>]*disabled/', $html);
        $this->assertStringContainsString('action="gear.php?action=pretech-submit"', $html);
        $this->assertStringContainsString('name="csrf_token" value="csrf-token-1"', $html);
        $this->assertStringContainsString('"subjectType":"gear_record"', $html);
        $this->assertStringContainsString('"subjectId":4', $html);
        $this->assertStringContainsString('js/pretech-form.js', $html);
        $this->assertStringContainsString('Jane &lt;Racer&gt;', $html);
    }

    public function testCompleteSetEnablesSubmitAndShowsRetakeNotes(): void
    {
        $photos = [];
        foreach (['helmet_label', 'suit_label', 'fhr_label', 'gear_flatlay'] as $i => $key) {
            $photos[$key] = $this->photoRow($key, ['id' => $i + 1]);
        }
        $photos['helmet_label']['review_status'] = 'retake';
        $photos['helmet_label']['reviewer_note'] = 'Date hidden <by glare>';

        $html = $this->renderPretech($this->gear(['photo_status' => 'needs_changes']), $this->snapshot($photos));

        $this->assertStringContainsString('4 of 4 required photos', $html);
        $this->assertDoesNotMatchRegularExpression('/id="pretech-submit-btn"[^>]*disabled/', $html);
        $this->assertStringContainsString('Retake requested', $html);
        $this->assertStringContainsString('Date hidden &lt;by glare&gt;', $html);
        $this->assertStringContainsString('inspection.php?action=photo&amp;id=1', $html);
    }

    public function testLockedRecordIsReadOnly(): void
    {
        $html = $this->renderPretech($this->gear(['photo_status' => 'submitted']), $this->snapshot());
        $this->assertStringContainsString('submitted for review', $html);
        $this->assertStringNotContainsString('data-photo-input', $html);
        $this->assertStringNotContainsString('id="pretech-submit-btn"', $html);
        $this->assertStringContainsString('"locked":true', $html);
    }

    public function testAcceptedRecordShowsOnlyABanner(): void
    {
        $html = $this->renderPretech($this->gear(['status' => 'accepted', 'accepted_via' => 'in_person']), $this->snapshot());
        $this->assertStringContainsString('already teched', $html);
        $this->assertStringNotContainsString('pretech-card', $html);
    }

    public function testApplicableConditionalIsCheckedAndCounted(): void
    {
        $html = $this->renderPretech($this->gear(['photo_status' => 'draft']),
            $this->snapshot(['underwear_label' => $this->photoRow('underwear_label', ['file_path' => ''])], ['underwear_label']));
        $this->assertStringContainsString('0 of 5 required photos', $html);
        $this->assertMatchesRegularExpression('/data-applies-toggle[^>]*checked/', $html);
    }

    public function testCopyAvoidsBannedWording(): void
    {
        foreach ([$this->renderList([$this->gear()]), $this->renderPretech($this->gear(), $this->snapshot())] as $html) {
            $text = strip_tags(preg_replace('/<script.*?<\/script>/s', '', $html));
            $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $text);
        }
    }
}
