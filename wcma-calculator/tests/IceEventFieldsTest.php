<?php
// wcma-calculator/tests/IceEventFieldsTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../ice-rules.php';

final class IceEventFieldsTest extends TestCase
{
    public function testMissingDisciplineIsSummer(): void
    {
        $this->assertSame(['ok' => true, 'discipline' => 'summer', 'club' => null, 'error' => null], iceEventFields([]));
    }

    public function testSummerEventDropsClub(): void
    {
        $this->assertSame(['ok' => true, 'discipline' => 'summer', 'club' => null, 'error' => null],
            iceEventFields(['discipline' => 'summer', 'host_club' => 'NASCC']));
    }

    public function testIceEventWithClub(): void
    {
        $this->assertSame(['ok' => true, 'discipline' => 'ice', 'club' => 'WSCC', 'error' => null],
            iceEventFields(['discipline' => 'ice', 'host_club' => 'WSCC']));
    }

    public function testIceEventRequiresKnownClub(): void
    {
        $this->assertFalse(iceEventFields(['discipline' => 'ice'])['ok']);
        $this->assertFalse(iceEventFields(['discipline' => 'ice', 'host_club' => ''])['ok']);
        $this->assertFalse(iceEventFields(['discipline' => 'ice', 'host_club' => 'XYZ'])['ok']);
        $this->assertSame('Choose the host club for an ice event.', iceEventFields(['discipline' => 'ice'])['error']);
    }

    public function testUnknownDisciplineIsRejected(): void
    {
        $this->assertFalse(iceEventFields(['discipline' => 'rally'])['ok']);
        $this->assertFalse(iceEventFields(['discipline' => ['ice']])['ok']);
    }

    public function testSummerEventsTakeAListedClubOrNone(): void
    {
        $this->assertSame('ESCC', iceEventFields(['discipline' => 'summer', 'host_club' => 'ESCC'], ['ESCC', 'NASCC'])['club']);
        $this->assertNull(iceEventFields(['discipline' => 'summer', 'host_club' => 'NOPE'], ['ESCC'])['club']);
        $this->assertNull(iceEventFields(['discipline' => 'summer', 'host_club' => ''], ['ESCC'])['club']);
        // Ice still only takes clubs with ice rules, whatever the list says.
        $this->assertFalse(iceEventFields(['discipline' => 'ice', 'host_club' => 'ESCC'], ['ESCC'])['ok']);
    }
}
