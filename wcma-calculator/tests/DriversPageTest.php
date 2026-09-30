<?php
// wcma-calculator/tests/DriversPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../drivers-lib.php';
require_once __DIR__ . '/../drivers-page.php';
require_once __DIR__ . '/../media-lib.php';

use PHPUnit\Framework\TestCase;

final class DriversPageTest extends TestCase
{
    private function vm(array $drivers, array $o = []): array {
        return array_merge([
            'rows' => driversRows($drivers, [], 1, 2026), 'season' => 2026, 'csrf' => 'tok',
            'licenceLink' => ['label' => '2026 Race Licences', 'url' => 'https://msr.test/l?a=1&b=2'],
        ], $o);
    }

    public function testYouComeFirstThenCoDriversEachWithGearAndLicence(): void
    {
        $html = renderDriversHtml($this->vm([
            ['id' => 1, 'name' => 'Jordan Lee', 'licence_no' => '2026-0412'],
            ['id' => 2, 'name' => 'Sam <Patel>', 'licence_no' => null],
        ]));
        $this->assertLessThan(strpos($html, 'Co-drivers you manage'), strpos($html, '<h2>You</h2>'));
        $this->assertStringContainsString('Jordan Lee (you)', $html);
        $this->assertStringContainsString('Sam &lt;Patel&gt;', $html);
        $this->assertSame(2, substr_count($html, 'Needs gear tech 2026'));
        $this->assertStringContainsString('href="gear.php?action=start&amp;driver_id=2">Add gear photos</a>', $html);
        $this->assertStringContainsString('id="licence-1" name="licence_no" maxlength="40" value="2026-0412"', $html);
        $this->assertStringContainsString('name="action" value="licence"', $html);
        $this->assertStringContainsString('name="driver_id" value="2"', $html);
    }

    public function testLicencesPointAtMotorsportRegAndTheAddFormIsThere(): void
    {
        $html = renderDriversHtml($this->vm([['id' => 1, 'name' => 'Jordan Lee', 'licence_no' => null]]));
        $this->assertStringContainsString('Licences are managed on MotorsportReg.', $html);
        $this->assertStringContainsString('href="https://msr.test/l?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('No co-drivers yet.', $html);
        $this->assertStringContainsString('name="action" value="add"', $html);
        $this->assertStringContainsString('Add a co-driver', $html);
        $this->assertStringContainsString('href="profile.php"', $html);
        $this->assertStringNotContainsString('Renew', $html);
        $this->assertStringNotContainsString('target="_blank"', renderDriversHtml($this->vm([['id' => 1, 'name' => 'J', 'licence_no' => null]], ['licenceLink' => null])));
    }

    public function testEachDriverShowsItsMediaProfileStatusAndLink(): void
    {
        $consent = ['consent_media' => 1, 'consent_public' => 0, 'is_minor' => 0, 'guardian_name' => null];
        $rows = driversRows(
            [['id' => 1, 'name' => 'Jordan Lee', 'licence_no' => null], ['id' => 2, 'name' => 'Sam Patel', 'licence_no' => null]],
            [], 1, 2026,
            [1 => ['profile' => ['blurb' => 'Fast.', 'photo_path' => null, 'hidden_at' => null, 'public_status' => 'none'], 'consent' => $consent, 'sponsors' => []]]
        );
        $html = renderDriversHtml($this->vm([], ['rows' => $rows]));
        $this->assertStringContainsString('Shared with clubs', $html);
        $this->assertStringContainsString('href="media-profile.php?driver_id=1">Edit media profile</a>', $html);
        $this->assertStringContainsString('Not set up', $html);
        $this->assertStringContainsString('href="media-profile.php?driver_id=2">Set up media profile</a>', $html);
    }

    public function testIceGearLineShowsWhenPresentAndNotOtherwise(): void
    {
        $rows = driversRows([['id' => 1, 'name' => 'Jordan Lee', 'licence_no' => null]], [], 1, 2026, [], [
            1 => ['state' => 'none', 'label' => 'Needs ice gear check 2027', 'gearId' => null, 'sheetId' => 9],
        ]);
        $html = renderDriversHtml($this->vm([], ['rows' => $rows]));
        $this->assertStringContainsString('Ice gear:', $html);
        $this->assertStringContainsString('Needs ice gear check 2027', $html);
        $this->assertStringContainsString('href="gear.php?action=start-ice&amp;sheet_id=9"', $html);

        $withoutIce = renderDriversHtml($this->vm([['id' => 1, 'name' => 'Jordan Lee', 'licence_no' => null]]));
        $this->assertStringNotContainsString('Ice gear:', $withoutIce);
    }

    public function testIceOnlyUserSeesNoSummerGearPrompt(): void
    {
        $driver = [['id' => 1, 'name' => 'Jordan Lee', 'licence_no' => null]];
        $ice = [1 => ['state' => 'none', 'label' => 'Needs ice gear check 2027', 'gearId' => null, 'sheetId' => 9]];
        $iceOnly = renderDriversHtml($this->vm([], ['rows' => driversRows($driver, [], 1, 2026, [], $ice, false)]));
        $this->assertStringNotContainsString('Needs gear tech 2026', $iceOnly);
        $this->assertStringNotContainsString('gear.php?action=start&amp;driver_id=1', $iceOnly);
        $this->assertStringContainsString('Ice gear:', $iceOnly);

        $both = renderDriversHtml($this->vm([], ['rows' => driversRows($driver, [], 1, 2026, [], $ice, true)]));
        $this->assertStringContainsString('Needs gear tech 2026', $both);
    }

    public function testNoBannedWording(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i',
            file_get_contents(__DIR__ . '/../drivers-page.php') . file_get_contents(__DIR__ . '/../drivers-lib.php'));
    }
}
