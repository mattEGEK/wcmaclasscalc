<?php
// wcma-calculator/tests/EventsTagFormatsTest.php
require_once __DIR__ . '/../events-lib.php';

use PHPUnit\Framework\TestCase;

final class EventsTagFormatsTest extends TestCase
{
    public function testTaggingWithNothingTickedIsRefused(): void
    {
        $pdo = make_temp_pdo();
        $uid = db_create_user($pdo, ['email' => 'tad' . uniqid() . '@example.com', 'name' => 'TA Driver', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $uid, '86');
        $event = db_create_event($pdo, 'WSCC TA', '2099-07-12', null, 'summer', 'WSCC');

        // What index.php and garage.php pass when the picker was shown but every box was unticked.
        $this->assertSame(['ok' => false, 'error' => ENTRY_FORMAT_ERROR], eventsTagCar($pdo, $uid, $event, $car, [], false));
        $this->assertNull(db_get_entry($pdo, $uid, $event, $car));
    }
}
