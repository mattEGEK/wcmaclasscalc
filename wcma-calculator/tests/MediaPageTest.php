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
        $this->assertStringContainsString('href="media-photo.php?driver_id=5" download', $html);
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
}
