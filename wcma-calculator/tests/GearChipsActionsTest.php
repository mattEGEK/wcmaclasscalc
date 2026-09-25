<?php
// wcma-calculator/tests/GearChipsActionsTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';

use PHPUnit\Framework\TestCase;

final class GearChipsActionsTest extends TestCase
{
    private const BUTTON = 'Create and accept gear in person';

    private function link(string $name, ?array $gear, int $number = 1): array {
        return [
            'driver_number' => $number, 'name' => $name, 'name_norm' => gearNameNorm($name), 'gear' => $gear,
            'status' => $gear !== null ? gearStatus($gear) : ['state' => 'none', 'via' => null],
        ];
    }

    private function gear(int $id): array {
        return ['id' => $id, 'season' => gearSeasonNow(), 'status' => 'open', 'photo_status' => null, 'accepted_via' => null];
    }

    private function adminOpts(array $o = []): array {
        return array_merge(['sheet_season' => gearSeasonNow(), 'csrf' => 'tok-1', 'sheet_id' => 12], $o);
    }

    public function testOwnerAddLinkIsHiddenOnPastSeasonSheets(): void
    {
        $html = renderGearChips([$this->link('Sam Coach', null)], 'owner', ['sheet_season' => gearSeasonNow() - 1]);
        $this->assertStringContainsString('Sam Coach: <span class="badge-pending">No gear record</span>', $html);
        $this->assertStringNotContainsString('Add gear record', $html);
        $this->assertStringNotContainsString('gear.php', $html);
    }

    public function testOwnerAddLinkStillShownForCurrentSeasonUnknownSeasonAndNoOptions(): void
    {
        $links = [$this->link('Sam Coach', null)];
        foreach ([['sheet_season' => gearSeasonNow()], ['sheet_season' => 0], []] as $opts) {
            $this->assertStringContainsString('<a href="gear.php?name=Sam%20Coach">Add gear record</a>', renderGearChips($links, 'owner', $opts));
        }
        $this->assertStringContainsString('Add gear record', renderGearChips($links, 'owner'));
    }

    public function testAdminGetsAOneTapFormForDriversWithNoRecord(): void
    {
        $html = renderGearChips([$this->link('Jane Racer', $this->gear(4)), $this->link('Sam Coach', null, 2)], 'admin', $this->adminOpts());

        $this->assertSame(1, substr_count($html, '<form '));
        $this->assertStringContainsString('<form method="post" action="admin.php?action=gear-create-accept" class="gear-inline-form">', $html);
        $this->assertStringContainsString('<input type="hidden" name="csrf_token" value="tok-1">', $html);
        $this->assertStringContainsString('<input type="hidden" name="sheet_id" value="12">', $html);
        $this->assertStringContainsString('<input type="hidden" name="driver_number" value="2">', $html);
        $this->assertStringContainsString('>' . self::BUTTON . '</button>', $html);
        $this->assertStringContainsString('Sam Coach: <span class="badge-pending">No gear record</span>', $html);
        $this->assertStringContainsString('href="admin.php?action=gear-record&amp;id=4"', $html);
    }

    public function testEveryDriverWithoutARecordGetsItsOwnForm(): void
    {
        $html = renderGearChips([$this->link('A', null, 1), $this->link('B', null, 2), $this->link('C', null, 3)], 'admin', $this->adminOpts());
        $this->assertSame(3, substr_count($html, '<form '));
        foreach ([1, 2, 3] as $n) {
            $this->assertStringContainsString('name="driver_number" value="' . $n . '"', $html);
        }
    }

    public function testExtraHiddenFieldsAndTokenAreEscaped(): void
    {
        $html = renderGearChips([$this->link('Sam Coach', null)], 'admin', $this->adminOpts([
            'csrf' => '"><x',
            'hidden' => ['back' => 'roster', 'a"b' => '<v>'],
        ]));

        $this->assertStringContainsString('name="back" value="roster"', $html);
        $this->assertStringContainsString('&lt;v&gt;', $html);
        $this->assertStringNotContainsString('"><x', $html);
        $this->assertStringNotContainsString('<v>', $html);
    }

    public function testAdminButtonNeedsBothTokenAndSheet(): void
    {
        $links = [$this->link('Sam Coach', null)];
        $withoutEither = [
            ['sheet_season' => gearSeasonNow()],
            $this->adminOpts(['csrf' => '']),
            $this->adminOpts(['sheet_id' => 0]),
        ];
        foreach ($withoutEither as $opts) {
            $html = renderGearChips($links, 'admin', $opts);
            $this->assertStringNotContainsString('<form', $html);
            $this->assertStringNotContainsString(self::BUTTON, $html);
        }
        $this->assertStringNotContainsString('<form', renderGearChips($links, 'admin'));
    }

    public function testNoButtonForPastSeasonOwnersOrDriversWithARecord(): void
    {
        $noRecord = [$this->link('Sam Coach', null)];

        $this->assertStringNotContainsString('<form', renderGearChips($noRecord, 'admin', $this->adminOpts(['sheet_season' => gearSeasonNow() - 1])));
        $this->assertStringNotContainsString('<form', renderGearChips($noRecord, 'owner', $this->adminOpts()));
        $this->assertStringNotContainsString('<form', renderGearChips([$this->link('Jane Racer', $this->gear(4))], 'admin', $this->adminOpts()));
    }

    public function testAdminOutputStillHasNoCompetitorLinks(): void
    {
        $html = renderGearChips([$this->link('Sam Coach', null)], 'admin', $this->adminOpts());
        $this->assertStringNotContainsString('gear.php', $html);
        $this->assertStringNotContainsString('Add gear record', $html);
    }

    public function testUnknownAudienceNeverGetsTheButton(): void
    {
        $this->assertStringNotContainsString('<form', renderGearChips([$this->link('Sam Coach', null)], 'bogus', $this->adminOpts()));
    }

    public function testCopyAvoidsBannedWording(): void
    {
        $html = renderGearChips([$this->link('Sam Coach', null)], 'admin', $this->adminOpts());
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', strip_tags($html));
    }
}
