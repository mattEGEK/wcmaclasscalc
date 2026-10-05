<?php
// wcma-calculator/tests/GearStartRequestTest.php — gear start links create a record, so a GET only goes
// ahead from a click inside the hub; anything else gets a confirm button that posts with the CSRF
// token (bug list 2026-10-02, item 7).
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../gear-lib.php';

use PHPUnit\Framework\TestCase;

final class GearStartRequestTest extends TestCase
{
    public function testAClickInsideTheHubOrATypedAddressGoesAhead(): void
    {
        $this->assertTrue(gearStartGetTrusted(['HTTP_SEC_FETCH_SITE' => 'same-origin']));
        $this->assertTrue(gearStartGetTrusted(['HTTP_SEC_FETCH_SITE' => 'none']));
    }

    public function testALinkFromElsewhereOrAnOldBrowserIsAsked(): void
    {
        $this->assertFalse(gearStartGetTrusted(['HTTP_SEC_FETCH_SITE' => 'cross-site']));
        $this->assertFalse(gearStartGetTrusted(['HTTP_SEC_FETCH_SITE' => 'same-site']));
        $this->assertFalse(gearStartGetTrusted([]));
    }

    public function testAPrefetchIsAsked(): void
    {
        $this->assertFalse(gearStartGetTrusted(['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_PURPOSE' => 'prefetch;prerender']));
        $this->assertFalse(gearStartGetTrusted(['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_PURPOSE' => 'prefetch']));
    }

    public function testEveryStartRouteChecksBeforeItsHandler(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../gear.php'));
        foreach (['start' => 'handleGearStart(', 'start-ice' => 'handleGearStartIce(', 'start-ta-drift' => 'handleGearStartTaDrift('] as $route => $handler) {
            $case = strpos($src, "case '$route':");
            $this->assertNotFalse($case, $route);
            $body = substr($src, $case, strpos($src, 'break;', $case) - $case);
            $check = strpos($body, 'requireGearStartRequest();');
            $this->assertNotFalse($check, "$route checks the request");
            $this->assertLessThan(strpos($body, $handler), $check, "$route checks before it creates anything");
        }
        // The confirm form posts with the CSRF token, and a POST is checked for it.
        $guard = substr($src, strpos($src, 'function requireGearStartRequest('), 1400);
        $this->assertStringContainsString("validateCsrfToken(\$_POST['csrf_token'] ?? '')", $guard);
        $this->assertStringContainsString('name="csrf_token"', $guard);
        $this->assertStringContainsString('<form method="post"', $guard);
    }
}
