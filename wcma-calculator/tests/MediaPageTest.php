<?php
// wcma-calculator/tests/MediaPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../media-lib.php';
require_once __DIR__ . '/../media-page.php';

use PHPUnit\Framework\TestCase;

final class MediaPageTest extends TestCase
{
    private function entry(array $o = []): array {
        return array_merge(mediaEntry(['id' => 5, 'name' => 'Jane <Doe>'], ['blurb' => 'Loves <b>hairpins</b>.', 'hometown' => 'Red Deer, AB',
            'racing_since' => 2015, 'photo_path' => 'uploads/media/x.jpg', 'pronunciation' => 'Doh', 'social_handle' => 'janed'],
            [['name' => 'Acme', 'url' => 'https://acme.test/?a=1&b=2']], '42', '2004 Honda S2000 (Silver)', 'GT3', true), $o);
    }

    public function testEntryCardEscapesEverythingAndLinksSponsors(): void
    {
        $html = renderMediaEntryHtml($this->entry(), false);
        $this->assertStringContainsString('Jane &lt;Doe&gt;', $html);
        $this->assertStringContainsString('Loves &lt;b&gt;hairpins&lt;/b&gt;.', $html);
        $this->assertStringContainsString('src="media-photo.php?driver_id=5"', $html);
        $this->assertStringContainsString('Say it: Doh', $html);
        $this->assertStringContainsString('href="https://acme.test/?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('rel="sponsored noopener"', $html);
        $this->assertStringNotContainsString('data-copy', $html);
    }

    public function testKitCardHasCopyTextAndPhotoDownload(): void
    {
        $html = renderMediaEntryHtml($this->entry(), true);
        $this->assertStringContainsString('data-copy', $html);
        $this->assertStringContainsString(h(mediaCopyText($this->entry())), $html);
        $this->assertStringContainsString('href="media-photo.php?driver_id=5&amp;download=1" download', $html);
    }

    public function testAnnouncerListsCarsWithAndWithoutProfiles(): void
    {
        $html = renderAnnouncerHtml([
            'events' => [['id' => 3, 'name' => 'Fall Sprint', 'event_date' => '2026-10-11']], 'eventId' => 3, 'q' => '', 'extra' => [],
            'roster' => [
                ['number' => '7', 'car' => 'Mazda MX-5', 'class' => '', 'drivers' => [['name' => 'Bo Bell', 'entry' => null]]],
                ['number' => '42', 'car' => '2004 Honda S2000 (Silver)', 'class' => 'GT3', 'drivers' => [['name' => 'Jane <Doe>', 'entry' => $this->entry()]]],
            ],
        ]);
        $this->assertStringContainsString('<option value="3" selected>', $html);
        $this->assertLessThan(strpos($html, '#42'), strpos($html, '#7'));
        $this->assertStringContainsString('No media profile', $html);
        $this->assertStringContainsString('Loves &lt;b&gt;hairpins&lt;/b&gt;.', $html);
        $this->assertStringContainsString('name="q"', $html);
    }

    public function testAnnouncerWithNoEventsOrEmptyRoster(): void
    {
        $this->assertStringContainsString('No events yet.', renderAnnouncerHtml(['events' => [], 'eventId' => 0, 'roster' => [], 'q' => '', 'extra' => []]));
        $html = renderAnnouncerHtml(['events' => [['id' => 3, 'name' => 'Fall Sprint', 'event_date' => '2026-10-11']], 'eventId' => 3, 'roster' => [], 'q' => '', 'extra' => []]);
        $this->assertStringContainsString('No cars on this event yet.', $html);
    }

    public function testKitShowsZipOnlyWhenAvailable(): void
    {
        $vm = ['events' => [], 'eventId' => 0, 'entries' => [$this->entry()], 'zip' => true];
        $this->assertStringContainsString('href="media.php?action=kit-zip&amp;event=0"', renderMediaKitHtml($vm));
        $this->assertStringNotContainsString('kit-zip', renderMediaKitHtml(['zip' => false] + $vm));
        $this->assertStringContainsString('No drivers have shared a profile yet.', renderMediaKitHtml(['entries' => []] + $vm));
    }

    public function testReviewQueueHasAcceptSendBackAndHideForms(): void
    {
        $html = renderMediaReviewHtml(['csrf' => 'tok', 'q' => '', 'found' => [],
            'queue' => [['driver_id' => 5, 'driver_name' => 'Jane <Doe>', 'updated_at' => '2026-09-27 10:00:00', 'entry' => $this->entry()]]]);
        $this->assertStringContainsString('Jane &lt;Doe&gt;', $html);
        foreach (['media-accept', 'media-send-back', 'media-hide'] as $a) {
            $this->assertStringContainsString('action="media.php?action=' . $a . '"', $html);
        }
        $this->assertSame(3, substr_count($html, 'name="csrf_token" value="tok"'));
        $this->assertSame(2, substr_count($html, 'name="seen" value="2026-09-27 10:00:00"'));
        $this->assertStringContainsString('name="note" required', $html);
        $this->assertStringNotContainsString('approv', strtolower($html));
        $this->assertStringContainsString('Nothing waiting for review.', renderMediaReviewHtml(['csrf' => 't', 'q' => '', 'found' => [], 'queue' => []]));
    }

    public function testHideSearchOffersUnhideForHiddenProfiles(): void
    {
        $html = renderMediaReviewHtml(['csrf' => 'tok', 'q' => 'doe', 'queue' => [], 'found' => [
            ['driver_id' => 5, 'driver_name' => 'Jane Doe', 'hidden_at' => null, 'public_status' => 'accepted'],
            ['driver_id' => 6, 'driver_name' => 'John Doe', 'hidden_at' => '2026-09-27 10:00:00', 'hidden_reason' => 'Dispute', 'public_status' => 'none'],
        ]]);
        $this->assertStringContainsString('action="media.php?action=media-hide"', $html);
        $this->assertStringContainsString('action="media.php?action=media-unhide"', $html);
        $this->assertStringContainsString('Dispute', $html);
    }

    public function testPublicPageShowsTheProfileWithoutStaffControls(): void
    {
        $html = renderPublicDriverHtml($this->entry());
        $this->assertStringContainsString('<h1>Jane &lt;Doe&gt;</h1>', $html);
        $this->assertStringContainsString('src="media-photo.php?driver_id=5"', $html);
        $this->assertStringContainsString('#42', $html);
        $this->assertStringContainsString('rel="sponsored noopener"', $html);
        $this->assertStringContainsString('@janed', $html);
        $this->assertStringNotContainsString('data-copy', $html);
        $this->assertStringNotContainsString('<form', $html);
    }
}
