<?php
// wcma-calculator/tests/MediaEmailTest.php
require_once __DIR__ . '/../pretech-email.php';
require_once __DIR__ . '/../media-email.php';

use PHPUnit\Framework\TestCase;

final class MediaEmailTest extends TestCase
{
    public function testSentBackEmail(): void
    {
        $m = mediaEmailSentBack(['name' => 'Sam <Patel>'], 'Brighter <photo>', 'https://hub.test/media-profile.php?driver_id=6');
        $this->assertSame('Your WCMA public driver page was sent back — Sam <Patel>', $m['subject']);
        $this->assertStringContainsString('Brighter &lt;photo&gt;', $m['html']);
        $this->assertStringContainsString('Brighter <photo>', $m['text']);
        $this->assertStringContainsString('https://hub.test/media-profile.php?driver_id=6', $m['text']);
        $this->assertStringContainsString('still used for announcing', $m['text']);
        $this->assertStringNotContainsString('approv', strtolower($m['text'] . $m['html']));
    }

    public function testHiddenEmail(): void
    {
        $m = mediaEmailHidden(['name' => 'Sam Patel'], 'Sponsor dispute', 'https://hub.test/media-profile.php?driver_id=6');
        $this->assertSame('Your WCMA driver profile was hidden — Sam Patel', $m['subject']);
        $this->assertStringContainsString('Sponsor dispute', $m['text']);
        $this->assertStringContainsString('not used for announcing, club promotion or the public page', $m['text']);
    }
}
