<?php
// wcma-calculator/tests/ReadinessLoaderTest.php
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../readiness-lib.php';

use PHPUnit\Framework\TestCase;

final class ReadinessLoaderTest extends TestCase
{
    public function testLoaderBuildsTheInputShapeFromTheDatabase(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        $car = test_make_car($pdo, $u, '42');
        $archived = test_make_car($pdo, $u, '9');
        db_archive_car($pdo, $u, $archived);
        $event = db_create_event($pdo, 'Fall Sprint', '2026-10-11', null);
        db_tag_event($pdo, $u, $event, $car);
        $sheet = db_insert_tech_sheet($pdo, [
            'submission_id' => $sub, 'user_id' => $u, 'event_id' => $event, 'sheet_type' => 'endurance',
            'entrant_name' => 'Team', 'driver_name' => 'Jordan Lee', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => '42', 'class' => 'IT1', 'engine_cc' => null, 'engine_hp' => null,
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ]);
        db_add_tech_sheet_driver($pdo, $sheet, 2, 'Sam Patel', '{}');
        gearCreate($pdo, $u, 'Jordan Lee', '', 2026);
        $self = (int)db_get_self_driver($pdo, $u)['id'];
        db_set_at_track($pdo, 'car', $car, 2026);

        $in = loadReadinessInputs($pdo, $u, '2026-09-26');

        $this->assertSame([$car], array_keys($in['cars']));                     // archived car excluded
        $this->assertSame('2026-09-26', $in['today']);
        $this->assertSame([['event_id' => $event, 'car_id' => $car, 'formats' => 'race', 'supps_ack_at' => null]], $in['plans']);
        $this->assertSame('submitted', $in['declarations'][$car]['review_status']);
        $this->assertCount(1, $in['sheets']);
        $sam = (int)db_find_driver($pdo, $u, 'Sam Patel')['id'];
        $this->assertSame([$sam], $in['sheetDrivers'][$sheet]);
        $this->assertSame($self, $in['selfDriverId']);
        $this->assertArrayHasKey($sam, $in['drivers']);
        $this->assertArrayHasKey("$self:2026", $in['gear']);
        $this->assertSame(["car:$car@2026"], $in['atTrack']);

        $result = buildReadiness($in);   // the shape is directly usable
        $this->assertCount(1, $result['events']);
    }

    public function testTaggedIceEventNowProducesIceItems(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'ice@example.com', 'name' => 'Ice Racer', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $u, '7');
        $iceEvent = db_create_event($pdo, 'NASCC Ice #1', '2026-12-12', null, 'ice', 'NASCC');
        db_tag_event($pdo, $u, $iceEvent, $car);

        $in = loadReadinessInputs($pdo, $u, '2026-09-26');
        $this->assertSame([$iceEvent], array_map(fn($e) => (int)$e['id'], $in['events']));
        $kinds = array_map(fn($i) => $i['kind'], buildReadiness($in)['events'][0]['items']);
        $this->assertNotContains('declaration', $kinds);
        $this->assertContains('tech_sheet', $kinds);
    }

    public function testLoaderGathersIceGearCarryOverFhrAndIceAtTrack(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'both@example.com', 'name' => 'Both Seasons', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $u, '7');
        db_create_event($pdo, 'NASCC Ice #1', '2027-01-10', null, 'ice', 'NASCC');
        $self = (int)db_get_self_driver($pdo, $u)['id'];
        $summer = (int)gearCreate($pdo, $u, (string)db_get_driver($pdo, $self)['name'], '', 2026)['id'];
        $ice = (int)gearCreate($pdo, $u, (string)db_get_driver($pdo, $self)['name'], '', 2027, 'ice')['id'];
        db_upsert_inspection_photo($pdo, ['subject_type' => 'gear_record', 'subject_id' => $ice, 'requirement_key' => 'ice_fhr_label',
            'requirement_version' => 1, 'file_path' => 'uploads/x.jpg', 'typed_value' => null]);
        db_mark_gear_photos_draft($pdo, $ice);
        db_transition_gear_photo_status($pdo, $ice, ['draft'], 'submitted');
        gearAcceptByPhotos($pdo, $ice, $u, 'caged');
        db_set_at_track($pdo, 'car', $car, 2027, 'ice', 'NASCC');

        $in = loadReadinessInputs($pdo, $u, '2026-09-26');
        $this->assertSame($summer, (int)$in['gear']["$self:2026"]['id']);
        $this->assertSame($ice, (int)$in['iceGear']["$self:2027"]['id']);
        $this->assertTrue($in['iceGearFhr'][$ice]);
        $this->assertContains("car:$car@ice:NASCC:2027", $in['atTrack']);
    }
}
