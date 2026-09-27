<?php
// wcma-calculator/tests/GearChipsTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';

use PHPUnit\Framework\TestCase;

final class GearChipsTest extends TestCase
{
    private function link(string $name, ?array $gear): array {
        return [
            'driver_number' => 1, 'name' => $name, 'name_norm' => gearNameNorm($name), 'gear' => $gear,
            'status' => $gear !== null ? gearStatus($gear) : ['state' => 'none', 'via' => null],
        ];
    }

    private function gear(int $id, array $o = []): array {
        return array_merge(['id' => $id, 'season' => 2026, 'status' => 'open', 'photo_status' => null, 'accepted_via' => null], $o);
    }

    public function testNoLinksRendersNothing(): void
    {
        $this->assertSame('', renderGearChips([], 'owner'));
        $this->assertSame('', renderGearChips([], 'admin'));
    }

    public function testOwnerChipsLinkToTheGearPageOrOfferToAddOne(): void
    {
        $html = renderGearChips([
            $this->link('Jane Racer', $this->gear(4, ['status' => 'accepted', 'accepted_via' => 'in_person'])),
            $this->link('Sam Coach', null),
        ], 'owner');

        $this->assertStringContainsString('<ul class="gear-chips">', $html);
        $this->assertStringContainsString('Jane Racer: <a class="badge-ok" href="gear.php?action=pretech&amp;id=4">Gear teched 2026</a>', $html);
        $this->assertStringContainsString('Sam Coach: <span class="badge-pending">No gear record</span>', $html);
        $this->assertStringContainsString('<a href="drivers.php">Go to Drivers</a>', $html);
    }

    public function testAdminChipsLinkToTheAdminGearReviewAndNeverOfferToAdd(): void
    {
        $html = renderGearChips([
            $this->link('Jane Racer', $this->gear(4, ['photo_status' => 'submitted'])),
            $this->link('Sam Coach', null),
        ], 'admin');

        $this->assertStringContainsString('href="inspect.php?action=gear-record&amp;id=4">Photos pending review</a>', $html);
        $this->assertStringContainsString('Sam Coach: <span class="badge-pending">No gear record</span>', $html);
        $this->assertStringNotContainsString('Go to Drivers', $html);
        $this->assertStringNotContainsString('gear.php', $html);
    }

    public function testUnknownAudienceGetsAdminRenderingWithNoCompetitorLinks(): void
    {
        $html = renderGearChips([
            $this->link('Jane Racer', $this->gear(4)),
            $this->link('Sam Coach', null),
        ], 'bogus');

        $this->assertStringNotContainsString('gear.php', $html);
        $this->assertStringNotContainsString('Go to Drivers', $html);
        $this->assertStringContainsString('inspect.php?action=gear-record&amp;id=4', $html);
    }

    public function testNamesAreEscapedInTextAndInTheUrl(): void
    {
        $html = renderGearChips([$this->link('<b>"Al" & Co', null)], 'owner');
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;&quot;Al&quot; &amp; Co', $html);
    }

    public function testStatusLabelsCoverEveryStateWithoutBannedWording(): void
    {
        $states = [
            $this->gear(1, ['status' => 'accepted', 'accepted_via' => 'photos', 'photo_status' => 'accepted']),
            $this->gear(2, ['photo_status' => 'needs_changes']),
            $this->gear(3, ['photo_status' => 'submitted']),
            $this->gear(4, ['photo_status' => 'draft']),
            $this->gear(5),
        ];
        $html = renderGearChips(array_map(fn(array $g): array => $this->link('D' . $g['id'], $g), $states), 'owner');
        foreach (['Gear pre-teched 2026', 'Photos need changes', 'Photos pending review', 'Photos in progress', 'Needs gear check at the track'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', strip_tags($html));
    }

    public function testIceChipsShowLevelAndTheCreateFormHasALevelPicker(): void
    {
        $accepted = ['driver_number' => 1, 'name' => 'Sam', 'name_norm' => 'sam', 'discipline' => 'ice', 'default_level' => 'caged',
                     'gear' => ['id' => 3, 'season' => 2027, 'discipline' => 'ice', 'level' => 'street_safe'],
                     'status' => ['state' => 'accepted', 'via' => 'in_person']];
        $this->assertStringContainsString('Gear teched Ice 2027 · street-safe', renderGearChips([$accepted], 'owner'));

        $none = ['driver_number' => 1, 'name' => 'Sam', 'name_norm' => 'sam', 'discipline' => 'ice', 'default_level' => 'caged',
                 'gear' => null, 'status' => ['state' => 'none', 'via' => null]];
        $html = renderGearChips([$none], 'admin', ['csrf' => 'tok', 'sheet_id' => 9, 'sheet_season' => gearSeasonNow('ice')]);
        $this->assertStringContainsString('<select name="level"', $html);
        $this->assertStringContainsString('<option value="caged" selected>caged</option>', $html);
        $this->assertStringContainsString('<option value="street_safe">street-safe</option>', $html);
    }

    public function testSummerCreateFormHasNoLevelPicker(): void
    {
        $none = ['driver_number' => 1, 'name' => 'Sam', 'name_norm' => 'sam', 'gear' => null, 'status' => ['state' => 'none', 'via' => null]];
        $this->assertStringNotContainsString('name="level"', renderGearChips([$none], 'admin', ['csrf' => 'tok', 'sheet_id' => 9]));
    }
}
