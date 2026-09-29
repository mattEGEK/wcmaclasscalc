<?php
// wcma-calculator/tests/OwnerRevokeNoticeTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../garage-lib.php';
require_once __DIR__ . '/../garage-page.php';
require_once __DIR__ . '/../reminders-lib.php';
require_once __DIR__ . '/../ice-sheet-lib.php';
require_once __DIR__ . '/../media-lib.php';
require_once __DIR__ . '/../drivers-lib.php';
require_once __DIR__ . '/../drivers-page.php';
require_once __DIR__ . '/../revoke-lib.php';

use PHPUnit\Framework\TestCase;

final class OwnerRevokeNoticeTest extends TestCase
{
    private function sheet(int $id, array $o = []): array {
        return array_merge(['id' => $id, 'car_id' => 3, 'event_id' => 20, 'season' => 2026, 'discipline' => 'summer', 'sheet_type' => 'standard',
                            'club' => null, 'status' => 'submitted', 'accepted_via' => null, 'photo_status' => null, 'revoke_note' => null], $o);
    }

    public function testNotesForIdentitiesThatAreNotAccepted(): void
    {
        $this->assertSame([], garageRevokeNotes([$this->sheet(1)]));
        $this->assertSame(['New engine'], garageRevokeNotes([$this->sheet(1, ['revoke_note' => 'Old note']), $this->sheet(2, ['revoke_note' => 'New engine'])]));
        // Accepted again on another sheet of the same identity: nothing to show.
        $this->assertSame([], garageRevokeNotes([$this->sheet(1, ['revoke_note' => 'New engine']), $this->sheet(2, ['status' => 'teched'])]));
        // A TA/Drift identity is separate from race.
        $this->assertSame(['Cage added'], garageRevokeNotes([$this->sheet(1, ['status' => 'teched']),
            $this->sheet(2, ['sheet_type' => 'ta_drift', 'club' => 'WSCC', 'revoke_note' => 'Cage added'])]));
    }

    public function testCarPageShowsTheNote(): void
    {
        $vm = ['car' => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'archived_at' => null],
               'class' => ['current' => null, 'earlierAccepted' => null], 'declarations' => [], 'season' => 2026,
               'techState' => 'none', 'techLabel' => 'Needs tech at the track', 'techAction' => null,
               'events' => ['tagged' => [], 'untagged' => [], 'earlierSheets' => []], 'seasons' => ['summer' => true, 'ice' => false],
               'csrf' => 'tok', 'detailsForm' => null, 'usesSummer' => true, 'ice' => null, 'revokeNotes' => ['New <engine>']];
        $this->assertStringContainsString(revokeNoticeHtml('New <engine>', 'Tech'), renderGarageCarHtml($vm));
    }

    public function testDriversShowTheLevelAndTheNote(): void
    {
        $drivers = [['id' => 5, 'name' => 'Jordan Lee', 'licence_no' => ''], ['id' => 6, 'name' => 'Sam Patel', 'licence_no' => '']];
        $gear = [5 => ['id' => 40, 'status' => 'accepted', 'accepted_via' => 'in_person', 'level' => 'ta_drift', 'photo_status' => null, 'revoke_note' => null],
                 6 => ['id' => 41, 'status' => 'open', 'accepted_via' => null, 'level' => null, 'photo_status' => null, 'revoke_note' => 'Helmet expired']];
        $rows = driversRows($drivers, $gear, 5, 2026);
        $this->assertSame('Gear teched 2026 · TA/Drift', $rows[0]['label']);
        $this->assertNull($rows[0]['revokeNote']);
        $this->assertSame('Helmet expired', $rows[1]['revokeNote']);
        $html = renderDriversHtml(['rows' => $rows, 'season' => 2026, 'csrf' => 'tok', 'licenceLink' => null]);
        $this->assertStringContainsString(revokeNoticeHtml('Helmet expired', 'Gear'), $html);
    }
}
