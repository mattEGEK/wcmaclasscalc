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

    private function renderPretech(array $gear, array $snapshot, ?array $flash = null): string {
        ob_start();
        renderGearPretechPage($gear, $snapshot, 'csrf-token-1', $flash);
        return (string)ob_get_clean();
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

    public function testConditionalToggleIsWordedForDrivers(): void
    {
        $html = $this->renderPretech($this->gear(), $this->snapshot());
        $this->assertStringContainsString('This applies to this driver', $html);
        $this->assertStringNotContainsString('my car', $html);
        $this->assertStringContainsString('This applies to my car', pretechRenderCard('underwear_label', photoRequirements('gear')['underwear_label'], null, false, false));
    }

    public function testCopyAvoidsBannedWording(): void
    {
        $html = $this->renderPretech($this->gear(), $this->snapshot());
        $text = strip_tags(preg_replace('/<script.*?<\/script>/s', '', $html));
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $text);
    }

    public function testIceGearRecordShowsIceRequirementsAndLabel(): void
    {
        $html = $this->renderPretech(
            $this->gear(['id' => 3, 'discipline' => 'ice', 'season' => 2027, 'driver_name' => 'Sam', 'status' => 'open', 'photo_status' => null]),
            $this->snapshot()
        );
        $this->assertStringContainsString('data-key="ice_helmet_label"', $html);
        $this->assertStringNotContainsString('data-key="helmet_label"', $html);
        $this->assertStringContainsString('Sam — Ice 2027', $html);
    }

    public function testIceGearRecordLinksBackToTheGarageNotDrivers(): void
    {
        $html = $this->renderPretech($this->gear(['discipline' => 'ice', 'season' => 2027]), $this->snapshot());
        $this->assertStringContainsString('href="garage.php"', $html);
        $this->assertStringContainsString('Back to Garage', $html);
        $this->assertStringNotContainsString('Back to Drivers', $html);
    }

    public function testSummerGearRecordStillLinksBackToDrivers(): void
    {
        $html = $this->renderPretech($this->gear(), $this->snapshot());
        $this->assertStringContainsString('href="drivers.php"', $html);
        $this->assertStringContainsString('Back to Drivers', $html);
    }
}
