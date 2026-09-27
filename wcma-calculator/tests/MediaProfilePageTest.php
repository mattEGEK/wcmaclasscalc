<?php
// wcma-calculator/tests/MediaProfilePageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../media-lib.php';
require_once __DIR__ . '/../media-profile-page.php';

use PHPUnit\Framework\TestCase;

final class MediaProfilePageTest extends TestCase
{
    private function vm(array $o = []): array {
        return array_merge([
            'driver' => ['id' => 5, 'name' => 'Jordan <Lee>', 'owner_user_id' => 1, 'user_id' => 1], 'isSelf' => true,
            'profile' => null, 'sponsors' => [], 'consent' => null, 'status' => mediaProfileStatus(null, null),
            'errors' => [], 'csrf' => 'tok', 'input' => null,
        ], $o);
    }

    public function testEmptyFormHasEveryFieldAndBothConsentBoxes(): void
    {
        $html = renderMediaProfileHtml($this->vm());
        $this->assertStringContainsString('Jordan &lt;Lee&gt;', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
        $this->assertStringContainsString('name="driver_id" value="5"', $html);
        $this->assertStringContainsString('name="photo" accept="image/jpeg,image/png,image/webp"', $html);
        $this->assertStringContainsString('maxlength="500"', $html);
        $this->assertSame(6, substr_count($html, 'name="sponsor_name[]"'));
        $this->assertStringContainsString(h(MEDIA_CONSENT_MEDIA_TEXT), $html);
        $this->assertStringContainsString(h(MEDIA_CONSENT_PUBLIC_TEXT), $html);
        $this->assertStringContainsString('Reviewed by WCMA media staff before it appears.', $html);
        $this->assertStringNotContainsString('on_behalf_confirm', $html);
        $this->assertStringNotContainsString('value="withdraw"', $html);   // nothing to withdraw yet
        $this->assertStringNotContainsString('approv', strtolower($html));
    }

    public function testCoDriverFormAsksForConfirmation(): void
    {
        $html = renderMediaProfileHtml($this->vm(['isSelf' => false, 'driver' => ['id' => 6, 'name' => 'Sam Patel', 'owner_user_id' => 1, 'user_id' => null]]));
        $this->assertStringContainsString('name="on_behalf_confirm" value="1"', $html);
        $this->assertStringContainsString(h(sprintf(MEDIA_ON_BEHALF_TEXT, 'Sam Patel')), $html);
    }

    public function testSavedProfileIsPrefilledEscapedAndOffersWithdrawAndPublicLink(): void
    {
        $profile = ['driver_id' => 5, 'blurb' => '<script>x</script>', 'pronunciation' => 'Lee', 'hometown' => 'Red Deer',
            'racing_since' => 2015, 'social_handle' => 'jl', 'photo_path' => 'uploads/media/driver-5-a.jpg',
            'public_status' => 'accepted', 'hidden_at' => null, 'public_note' => null];
        $consent = ['consent_media' => 1, 'consent_public' => 1, 'is_minor' => 0, 'guardian_name' => null];
        $html = renderMediaProfileHtml($this->vm(['profile' => $profile, 'consent' => $consent,
            'sponsors' => [['name' => 'Acme', 'url' => 'https://acme.test']], 'status' => mediaProfileStatus($profile, $consent)]));
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;</textarea>', $html);
        $this->assertStringContainsString('src="media-photo.php?driver_id=5"', $html);
        $this->assertStringContainsString('value="Acme"', $html);
        $this->assertMatchesRegularExpression('/name="consent_media" value="1"[^>]*checked/', $html);
        $this->assertStringContainsString('value="withdraw"', $html);
        $this->assertStringContainsString('href="driver.php?id=5"', $html);
        $this->assertStringContainsString('Public page live', $html);
    }

    public function testSentBackAndHiddenShowTheReason(): void
    {
        $consent = ['consent_media' => 1, 'consent_public' => 1, 'is_minor' => 0, 'guardian_name' => null];
        $sent = ['driver_id' => 5, 'blurb' => 'x', 'photo_path' => null, 'public_status' => 'sent_back', 'hidden_at' => null,
            'public_note' => 'Brighter photo <please>', 'hidden_reason' => null];
        $html = renderMediaProfileHtml($this->vm(['profile' => $sent, 'consent' => $consent, 'status' => mediaProfileStatus($sent, $consent)]));
        $this->assertStringContainsString('Brighter photo &lt;please&gt;', $html);

        $hidden = ['hidden_at' => '2026-09-27', 'hidden_reason' => 'Sponsor dispute'] + $sent;
        $html = renderMediaProfileHtml($this->vm(['profile' => $hidden, 'consent' => $consent, 'status' => mediaProfileStatus($hidden, $consent)]));
        $this->assertStringContainsString('Hidden by WCMA', $html);
        $this->assertStringContainsString('Sponsor dispute', $html);
    }

    public function testErrorsAndPostedInputAreShownBack(): void
    {
        $html = renderMediaProfileHtml($this->vm(['errors' => ['Keep the blurb to 500 characters or fewer.'],
            'input' => ['blurb' => 'Typed <text>', 'hometown' => 'Olds', 'consent_media' => '1', 'is_minor' => '1', 'guardian_name' => 'Pat']]));
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('Keep the blurb to 500 characters or fewer.', $html);
        $this->assertStringContainsString('Typed &lt;text&gt;</textarea>', $html);
        $this->assertStringContainsString('value="Olds"', $html);
        $this->assertStringContainsString('value="Pat"', $html);
    }
}
