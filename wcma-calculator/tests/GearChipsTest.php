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
        $this->assertStringContainsString('<a href="gear.php?name=Sam%20Coach">Add gear record</a>', $html);
    }

    public function testAdminChipsLinkToTheAdminGearReviewAndNeverOfferToAdd(): void
    {
        $html = renderGearChips([
            $this->link('Jane Racer', $this->gear(4, ['photo_status' => 'submitted'])),
            $this->link('Sam Coach', null),
        ], 'admin');

        $this->assertStringContainsString('href="admin.php?action=gear-record&amp;id=4">Photos pending review</a>', $html);
        $this->assertStringContainsString('Sam Coach: <span class="badge-pending">No gear record</span>', $html);
        $this->assertStringNotContainsString('Add gear record', $html);
        $this->assertStringNotContainsString('gear.php', $html);
    }

    public function testNamesAreEscapedInTextAndInTheUrl(): void
    {
        $html = renderGearChips([$this->link('<b>"Al" & Co', null)], 'owner');
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringContainsString('gear.php?name=%3Cb%3E%22Al%22%20%26%20Co', $html);
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
}
