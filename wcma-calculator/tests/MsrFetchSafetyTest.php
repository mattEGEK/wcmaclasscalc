<?php
// wcma-calculator/tests/MsrFetchSafetyTest.php — the MotorsportReg fetch only goes to https on
// motorsportreg.com, checks every redirect, and reads a capped amount (bug list 2026-10-02, item 9).
require_once __DIR__ . '/../msr-lib.php';

use PHPUnit\Framework\TestCase;

final class MsrFetchSafetyTest extends TestCase
{
    public function testOnlyHttpsOnMotorsportReg(): void
    {
        $this->assertTrue(msrIsMsrUrl('https://www.motorsportreg.com/orgs/nascc'));
        $this->assertTrue(msrIsMsrUrl('https://api.motorsportreg.com/rest/calendars/organization/X.json'));
        $this->assertTrue(msrIsMsrUrl('https://motorsportreg.com/'));
        $this->assertFalse(msrIsMsrUrl('http://www.motorsportreg.com/'));
        $this->assertFalse(msrIsMsrUrl('https://motorsportreg.com.evil.example/'));
        $this->assertFalse(msrIsMsrUrl('https://evilmotorsportreg.com/'));
        $this->assertFalse(msrIsMsrUrl('https://user:pw@www.motorsportreg.com/'));
        $this->assertFalse(msrIsMsrUrl('https://www.motorsportreg.com:8080/'));
        $this->assertFalse(msrIsMsrUrl('file:///etc/passwd'));
        $this->assertFalse(msrIsMsrUrl('https://169.254.169.254/latest/meta-data/'));
    }

    public function testRedirectsMustStayOnMotorsportReg(): void
    {
        $from = 'https://www.motorsportreg.com/orgs/nascc';
        $this->assertSame('https://www.motorsportreg.com/orgs/nascc/', msrRedirectTarget($from, '/orgs/nascc/'));
        $this->assertSame('https://www.motorsportreg.com/orgs/x', msrRedirectTarget($from, 'x'));
        $this->assertSame('https://msr.motorsportreg.com/a', msrRedirectTarget($from, '//msr.motorsportreg.com/a'));
        $this->assertSame('https://motorsportreg.com/b', msrRedirectTarget($from, 'https://motorsportreg.com/b'));
        $this->assertNull(msrRedirectTarget($from, 'https://example.com/'));
        $this->assertNull(msrRedirectTarget($from, '//example.com/'));
        $this->assertNull(msrRedirectTarget($from, 'http://www.motorsportreg.com/'));
        $this->assertNull(msrRedirectTarget($from, 'http://127.0.0.1/'));
        $this->assertNull(msrRedirectTarget($from, ''));
    }

    public function testAnAddressOffMotorsportRegIsNeverFetched(): void
    {
        $r = msrHttpGet('https://example.com/');
        $this->assertFalse($r['ok']);
        $this->assertSame('That address is not on MotorsportReg.', $r['error']);
    }

    public function testTheClubPageLookupFetchesTheCheckedAddress(): void
    {
        $seen = [];
        $fetch = function (string $u) use (&$seen): array { $seen[] = $u; return ['ok' => true, 'status' => 200, 'body' => 'uidClub/2386B6E3-96BC-AE58-0812CF4B556BCBC2', 'error' => '']; };
        $r = msrOrgIdFromInput('  https://WWW.motorsportreg.com/orgs/nascc?utm_source=x  ', $fetch);
        $this->assertTrue($r['ok']);
        $this->assertSame(['https://www.motorsportreg.com/orgs/nascc'], $seen);
    }

    public function testTheFetchIsCappedAndFollowsRedirectsByHand(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../msr-lib.php'));
        $fn = substr($src, strpos($src, 'function msrHttpGet('), 2600);
        $this->assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $fn);
        $this->assertStringContainsString('CURLOPT_PROTOCOLS => CURLPROTO_HTTPS', $fn);
        $this->assertStringContainsString('MSR_MAX_BYTES', $fn);
        $this->assertStringContainsString('msrRedirectTarget($url, $location)', $fn);
    }
}
