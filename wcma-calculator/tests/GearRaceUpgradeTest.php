<?php
// wcma-calculator/tests/GearRaceUpgradeTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../gear-lib.php';

use PHPUnit\Framework\TestCase;

final class GearRaceUpgradeTest extends TestCase
{
    /** @return array{0: PDO, 1: int, 2: int} pdo, user, a summer gear record accepted at TA/Drift */
    private function taDriftGear(): array {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'up' . uniqid() . '@example.com', 'name' => 'Pat', 'password_hash' => 'x', 'google_id' => null]);
        $id = (int)gearCreate($pdo, $u, 'Pat Winters', '', 2026)['id'];
        $this->assertTrue(gearAcceptInPerson($pdo, $id, $u, 'ta_drift')['ok']);
        return [$pdo, $u, $id];
    }

    private function addRacePhotos(PDO $pdo, int $id): void {
        foreach (photoRequirements('gear') as $key => $def) {
            if ($def['tier'] !== 'required') continue;
            db_upsert_inspection_photo($pdo, ['subject_type' => 'gear_record', 'subject_id' => $id, 'requirement_key' => $key,
                'requirement_version' => 1, 'file_path' => 'uploads/x.jpg', 'typed_value' => null]);
        }
    }

    public function testUpgradeKeepsTaDriftCoverUntilAccepted(): void
    {
        [$pdo, $u, $id] = $this->taDriftGear();
        $this->assertTrue(gearStartRaceUpgrade($pdo, $id)['ok']);
        $gear = db_get_gear_record($pdo, $id);
        $this->assertTrue(gearIsRaceUpgrade($gear));
        $this->assertSame('draft', $gear['photo_status']);
        $this->assertNull($gear['photo_tier']);
        $this->assertSame(array_keys(photoRequirements('gear')), array_keys(photoRequirementsFor($gear, 'gear')));   // the race list
        $this->assertTrue(gearCoversTier($gear, TECH_TIER_TA_DRIFT));
        $this->assertFalse(gearCoversTier($gear, TECH_TIER_RACE));
        $this->assertSame('submitted', gearAccessShape($gear)['status']);   // the owner can add photos

        $this->addRacePhotos($pdo, $id);
        $this->assertTrue(gearSubmit($pdo, $id)['ok']);
        $this->assertSame([$id], array_map(fn(array $g): int => (int)$g['id'], db_get_gear_awaiting_photo_review($pdo)));
        $this->assertTrue(gearCoversTier(db_get_gear_record($pdo, $id), TECH_TIER_TA_DRIFT));

        $this->assertSame('These are race gear photos: accept them at Race, or send them back.', gearAcceptByPhotos($pdo, $id, $u, 'ta_drift')['error']);
        $this->assertTrue(gearAcceptByPhotos($pdo, $id, $u, 'race')['ok']);
        $gear = db_get_gear_record($pdo, $id);
        $this->assertNull($gear['level']);
        $this->assertSame('accepted', $gear['photo_status']);
        $this->assertFalse(gearIsRaceUpgrade($gear));
        $this->assertTrue(gearCoversTier($gear, TECH_TIER_RACE));
        $this->assertSame([], db_get_gear_awaiting_photo_review($pdo));
    }

    public function testUpgradePhotosCanBeSentBack(): void
    {
        [$pdo, $u, $id] = $this->taDriftGear();
        gearStartRaceUpgrade($pdo, $id);
        $this->addRacePhotos($pdo, $id);
        gearSubmit($pdo, $id);
        $r = gearSendBack($pdo, $id, ['helmet_label' => 'Blurry']);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $gear = db_get_gear_record($pdo, $id);
        $this->assertSame('needs_changes', $gear['photo_status']);
        $this->assertSame('accepted', $gear['status']);
        $this->assertSame('ta_drift', $gear['level']);
    }

    public function testOnlyTaDriftGearCanBeUpgraded(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'up2' . uniqid() . '@example.com', 'name' => 'Pat', 'password_hash' => 'x', 'google_id' => null]);
        $open = (int)gearCreate($pdo, $u, 'Open Driver', '', 2026)['id'];
        $race = (int)gearCreate($pdo, $u, 'Race Driver', '', 2026)['id'];
        gearAcceptInPerson($pdo, $race, $u);
        foreach ([$open, $race] as $id) {
            $this->assertSame('Only gear accepted at TA/Drift can be upgraded to race gear.', gearStartRaceUpgrade($pdo, $id)['error']);
        }
        $this->assertFalse(gearStartRaceUpgrade($pdo, 99999)['ok']);
    }

    public function testInspectorCanUpgradeInPerson(): void
    {
        [$pdo, $u, $id] = $this->taDriftGear();
        gearStartRaceUpgrade($pdo, $id);
        $this->assertTrue(gearUpgradeToRaceInPerson($pdo, $id, $u)['ok']);
        $gear = db_get_gear_record($pdo, $id);
        $this->assertNull($gear['level']);
        $this->assertSame('in_person', $gear['accepted_via']);
        $this->assertTrue(gearCoversTier($gear, TECH_TIER_RACE));
        $this->assertSame([], db_get_gear_awaiting_photo_review($pdo));
        $this->assertFalse(gearUpgradeToRaceInPerson($pdo, $id, $u)['ok']);   // already race
    }

    /** Final-review Important finding 1: TA/Drift photos pending, then accepted in person at TA/Drift. */
    public function testInPersonAcceptanceOverPendingTaDriftPhotosIsNotARaceUpgrade(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'inp' . uniqid() . '@example.com', 'name' => 'Pat', 'password_hash' => 'x', 'google_id' => null]);
        $event = db_create_event($pdo, 'WSCC TA', gearSeasonNow() . '-07-12', null, 'summer', 'WSCC');
        $sheet = db_get_tech_sheet($pdo, test_make_ta_drift_sheet($pdo, $u, test_make_car($pdo, $u, '86'), $event));
        $id = (int)gearStartTaDriftForSheet($pdo, $sheet, $u)['id'];

        foreach (photoRequirementsFor(db_get_gear_record($pdo, $id), 'gear') as $key => $def) {
            if ($def['tier'] !== 'required') continue;
            db_upsert_inspection_photo($pdo, ['subject_type' => 'gear_record', 'subject_id' => $id, 'requirement_key' => $key,
                'requirement_version' => 1, 'file_path' => 'uploads/x.jpg', 'typed_value' => null]);
        }
        db_mark_gear_photos_draft($pdo, $id);
        $this->assertTrue(gearSubmit($pdo, $id)['ok']);
        $this->assertSame([$id], array_map(fn(array $g): int => (int)$g['id'], db_get_gear_awaiting_photo_review($pdo)));

        // The inspector checks the gear in person at TA/Drift instead of reviewing the photos.
        $this->assertTrue(gearAcceptInPerson($pdo, $id, $u, 'ta_drift')['ok']);
        $gear = db_get_gear_record($pdo, $id);
        $this->assertSame('accepted', $gear['status']);
        $this->assertSame('ta_drift', $gear['level']);

        $this->assertFalse(gearIsRaceUpgrade($gear));
        $this->assertSame([], db_get_gear_awaiting_photo_review($pdo));   // not stuck in the queue

        // The owner page's "Race gear photos" heading (gear-page.php) only renders when $upgrading
        // (gearIsRaceUpgrade($gear)) is true; already asserted false above, so it stays hidden here.
        $this->assertSame('These photos only cover TA/Drift gear: accept them at TA/Drift.',
            gearAcceptByPhotos($pdo, $id, $u, 'race')['error']);   // neither queue accepts it as an "upgrade"
        $this->assertSame('This driver\'s gear has already been teched for the season.', gearSubmit($pdo, $id)['error']);
    }

    /**
     * A record's photo_tier is NULL by default (the race list) even before anyone chooses TA/Drift
     * photos, so photo_tier IS NULL alone cannot tell "explicit race upgrade in progress" apart from
     * "never touched the TA/Drift photo list". A driver who started (draft, unsubmitted) race-list
     * photos and is then accepted in person at TA/Drift must not be misdetected as a race upgrade
     * either — db_accept_gear_in_person closing the pending photo set is what prevents this.
     */
    public function testInPersonAcceptanceOverAbandonedDraftPhotosIsNotARaceUpgrade(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'abn' . uniqid() . '@example.com', 'name' => 'Pat', 'password_hash' => 'x', 'google_id' => null]);
        $id = (int)gearCreate($pdo, $u, 'Pat Winters', '', 2026)['id'];
        $this->addRacePhotos($pdo, $id);
        db_mark_gear_photos_draft($pdo, $id);   // never submitted; photo_tier stays NULL (the race list default)
        $this->assertNull(db_get_gear_record($pdo, $id)['photo_tier']);

        $this->assertTrue(gearAcceptInPerson($pdo, $id, $u, 'ta_drift')['ok']);
        $gear = db_get_gear_record($pdo, $id);
        $this->assertSame('accepted', $gear['status']);
        $this->assertSame('ta_drift', $gear['level']);
        $this->assertNull($gear['photo_tier']);

        $this->assertFalse(gearIsRaceUpgrade($gear));
        $this->assertSame([], db_get_gear_awaiting_photo_review($pdo));
    }

    public function testPagesAndRoutes(): void
    {
        $this->assertContains('gear-record-upgrade-race', INSPECT_POST_ACTIONS);
        $this->assertStringContainsString("case 'gear-record-upgrade-race':", file_get_contents(__DIR__ . '/../inspect.php'));
        $this->assertStringContainsString("case 'upgrade-race':", file_get_contents(__DIR__ . '/../gear.php'));
        $page = file_get_contents(__DIR__ . '/../gear-page.php');
        $this->assertStringContainsString('action="gear.php?action=upgrade-race"', $page);
        $this->assertStringContainsString('gearIsRaceUpgrade($gear)', $page);
        $this->assertStringContainsString('action="inspect.php?action=gear-record-upgrade-race"', file_get_contents(__DIR__ . '/../admin-gear.php'));
    }
}
