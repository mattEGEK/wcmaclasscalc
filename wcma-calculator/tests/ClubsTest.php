<?php
// wcma-calculator/tests/ClubsTest.php
require_once __DIR__ . '/../clubs-lib.php';
require_once __DIR__ . '/../ice-rules.php';

use PHPUnit\Framework\TestCase;

final class ClubsTest extends TestCase
{
    public function testTableIsCreatedAndIceClubsSeededOnce(): void
    {
        $pdo = make_temp_pdo();
        $codes = array_column(db_get_clubs($pdo), 'code');
        $this->assertSame(['NASCC', 'WSCC'], $codes);
        $this->assertSame('', db_get_club($pdo, 'NASCC')['msr_url']);
        db_update_club($pdo, 'NASCC', 'NASCC Ice', 'https://msr.example/nascc', true);
        db_init($pdo);   // runs again on every request
        $this->assertSame('NASCC Ice', db_get_club($pdo, 'NASCC')['name']);
        $this->assertCount(2, db_get_clubs($pdo));
    }

    public function testSeedMatchesTheIceRules(): void
    {
        foreach (DB_ICE_CLUB_SEED as $code => $name) $this->assertSame(iceClubLabel($code), $name);
        $this->assertSame(iceClubCodes(), array_keys(DB_ICE_CLUB_SEED));
    }

    public function testCreateUpdateAndActiveFilter(): void
    {
        $pdo = make_temp_pdo();
        db_create_club($pdo, 'ESCC', 'Edmonton Sports Car Club', 'https://msr.example/escc');
        db_update_club($pdo, 'WSCC', 'Winnipeg Sports Car Club', '', false);
        $this->assertSame(['ESCC', 'NASCC'], array_column(db_get_clubs($pdo, true), 'code'));
        $this->assertNull(db_get_club($pdo, 'NOPE'));
    }

    public function testValidation(): void
    {
        $ok = clubValidate(' escc ', ' Edmonton  Sports Car Club ', ' https://msr.example/escc ');
        $this->assertSame(['ok' => true, 'error' => null, 'code' => 'ESCC', 'name' => 'Edmonton Sports Car Club', 'url' => 'https://msr.example/escc'], $ok);
        $this->assertTrue(clubValidate('CAS', 'Calgary Auto Sports', '')['ok']);
        $this->assertSame('Enter a short code of 2 to 12 letters, numbers or dashes.', clubValidate('E', 'x', '')['error']);
        $this->assertSame('Enter a short code of 2 to 12 letters, numbers or dashes.', clubValidate('ES CC', 'x', '')['error']);
        $this->assertSame('Enter the club name (120 characters at most).', clubValidate('ESCC', '  ', '')['error']);
        $this->assertSame('Enter the MotorsportReg link as a full address starting with https://, or leave it blank.', clubValidate('ESCC', 'x', 'http://msr.example')['error']);
        $this->assertSame('Enter the MotorsportReg link as a full address starting with https://, or leave it blank.', clubValidate('ESCC', 'x', 'javascript:alert(1)')['error']);
    }

    public function testClubForEvent(): void
    {
        $this->assertNull(clubForEvent(null, ['discipline' => 'summer', 'host_club' => null]));
        $this->assertSame(['name' => 'Edmonton Sports Car Club', 'url' => 'https://msr.example/escc'],
            clubForEvent(['code' => 'ESCC', 'name' => 'Edmonton Sports Car Club', 'msr_url' => 'https://msr.example/escc', 'active' => 0], ['discipline' => 'summer', 'host_club' => 'ESCC']));
        // Ice event whose club row is missing: the ice rules name, no link.
        $this->assertSame(['name' => 'Northern Alberta Sports Car Club', 'url' => ''], clubForEvent(null, ['discipline' => 'ice', 'host_club' => 'NASCC']));
        // A stored link that isn't https is never shown.
        $this->assertSame('', clubForEvent(['code' => 'X', 'name' => 'X', 'msr_url' => 'http://x', 'active' => 1], ['host_club' => 'X'])['url']);
    }

    public function testAnEventsOwnMotorsportRegLinkBeatsTheClubPage(): void
    {
        $club = ['code' => 'ESCC', 'name' => 'Edmonton Sports Car Club', 'msr_url' => 'https://msr.example/escc', 'active' => 1];
        $this->assertSame(['name' => 'Edmonton Sports Car Club', 'url' => 'https://msr.example/e/7'],
            clubForEvent($club, ['discipline' => 'summer', 'host_club' => 'ESCC', 'msr_url' => 'https://msr.example/e/7']));
        // No host club: still the event's link, with no club name.
        $this->assertSame(['name' => '', 'url' => 'https://msr.example/e/7'],
            clubForEvent(null, ['discipline' => 'summer', 'host_club' => null, 'msr_url' => 'https://msr.example/e/7']));
        // An event link that isn't https is never shown; the club page still is.
        $this->assertSame('https://msr.example/escc', clubForEvent($club, ['host_club' => 'ESCC', 'msr_url' => 'http://x'])['url']);
        $this->assertSame('', eventRegisterUrl(['msr_url' => 'javascript:alert(1)']));
        $this->assertSame('', eventRegisterUrl(['name' => 'no column yet']));
        $this->assertSame('https://msr.example/e/7', eventRegisterUrl(['msr_url' => 'https://msr.example/e/7']));
    }

    public function testEventLinkValidation(): void
    {
        $this->assertNull(eventMsrUrlError(''));
        $this->assertNull(eventMsrUrlError('https://www.motorsportreg.com/events/fall-sprint'));
        $this->assertSame(EVENT_MSR_URL_ERROR, eventMsrUrlError('www.motorsportreg.com/events/x'));
        $this->assertSame(EVENT_MSR_URL_ERROR, eventMsrUrlError('http://www.motorsportreg.com/events/x'));
    }

    public function testRowPickerKeepsAnInactiveCurrentClubSelected(): void
    {
        $clubs = [
            ['code' => 'ESCC', 'name' => 'Edmonton', 'msr_url' => '', 'active' => 0],
            ['code' => 'NASCC', 'name' => 'Northern Alberta', 'msr_url' => '', 'active' => 0],
            ['code' => 'WSCC', 'name' => 'Winnipeg', 'msr_url' => '', 'active' => 1],
        ];
        $summer = eventClubOptions($clubs, ['discipline' => 'summer', 'host_club' => 'ESCC'], ['NASCC', 'WSCC']);
        $this->assertSame(['', 'ESCC', 'WSCC'], array_column($summer, 'code'));
        $this->assertSame('ESCC — Edmonton (inactive)', $summer[1]['label']);
        $this->assertSame([false, true, false], array_column($summer, 'selected'));
        $ice = eventClubOptions($clubs, ['discipline' => 'ice', 'host_club' => 'NASCC'], ['NASCC', 'WSCC']);
        $this->assertSame(['NASCC', 'WSCC'], array_column($ice, 'code'));
        $this->assertSame([true, false], array_column($ice, 'selected'));
        $none = eventClubOptions($clubs, ['discipline' => 'summer', 'host_club' => null], ['NASCC', 'WSCC']);
        $this->assertSame(['', 'WSCC'], array_column($none, 'code'));
        $this->assertTrue($none[0]['selected']);
    }
}
