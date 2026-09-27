<?php
// wcma-calculator/tests/MediaLibTest.php
require_once __DIR__ . '/../media-lib.php';

use PHPUnit\Framework\TestCase;

final class MediaLibTest extends TestCase
{
    private function consentRow(int $media, int $public = 0): array {
        return ['consent_media' => $media, 'consent_public' => $public, 'is_minor' => 0, 'guardian_name' => null];
    }

    private function profile(array $o = []): array {
        return array_merge(['driver_id' => 5, 'blurb' => 'Fast.', 'pronunciation' => null, 'hometown' => null,
            'racing_since' => null, 'social_handle' => null, 'photo_path' => null, 'public_status' => 'none',
            'hidden_at' => null, 'public_note' => null], $o);
    }

    public function testMediaCanAccess(): void
    {
        $this->assertFalse(mediaCanAccess(null));
        $this->assertFalse(mediaCanAccess(['id' => 1, 'role' => 'inspector', 'is_media' => 0]));
        $this->assertTrue(mediaCanAccess(['id' => 1, 'role' => 'user', 'is_media' => 1]));
        $this->assertTrue(mediaCanAccess(['id' => 1, 'role' => 'admin']));
    }

    public function testCurrentConsent(): void
    {
        $this->assertSame(['media' => false, 'public' => false], mediaCurrentConsent(null));
        $this->assertSame(['media' => true, 'public' => false], mediaCurrentConsent($this->consentRow(1)));
        $this->assertSame(['media' => true, 'public' => true], mediaCurrentConsent($this->consentRow(1, 1)));
        $this->assertSame(['media' => false, 'public' => false], mediaCurrentConsent($this->consentRow(0, 1)));
    }

    public function testUsableNeedsConsentContentAndNotHidden(): void
    {
        $p = $this->profile();
        $this->assertFalse(mediaUsable(null, $this->consentRow(1), 'club'));
        $this->assertFalse(mediaUsable($p, null, 'club'));
        $this->assertTrue(mediaUsable($p, $this->consentRow(1), 'club'));
        $this->assertFalse(mediaUsable($this->profile(['blurb' => '  ', 'photo_path' => null]), $this->consentRow(1), 'club'));
        $this->assertTrue(mediaUsable($this->profile(['blurb' => '', 'photo_path' => 'uploads/media/x.jpg']), $this->consentRow(1), 'club'));
        $this->assertFalse(mediaUsable($this->profile(['hidden_at' => '2026-09-27 10:00:00']), $this->consentRow(1), 'club'));
        $this->assertFalse(mediaUsable($p, $this->consentRow(1, 1), 'public'));   // not accepted yet
        $this->assertTrue(mediaUsable($this->profile(['public_status' => 'accepted']), $this->consentRow(1, 1), 'public'));
        $this->assertFalse(mediaUsable($this->profile(['public_status' => 'accepted']), $this->consentRow(1, 0), 'public'));
        $this->assertFalse(mediaUsable($this->profile(['public_status' => 'accepted', 'hidden_at' => 'x']), $this->consentRow(1, 1), 'public'));
    }

    public function testConsentInput(): void
    {
        $r = mediaConsentInput(['consent_media' => '1', 'consent_public' => '1'], true);
        $this->assertTrue($r['ok']);
        $this->assertSame(['consent_media' => 1, 'consent_public' => 1, 'is_minor' => 0, 'guardian_name' => null, 'on_behalf' => 0], $r['consent']);

        $r = mediaConsentInput(['consent_public' => '1'], true);   // public without media means neither
        $this->assertSame(0, $r['consent']['consent_public']);

        $r = mediaConsentInput(['consent_media' => '1', 'is_minor' => '1', 'guardian_name' => ' '], true);
        $this->assertFalse($r['ok']);
        $this->assertSame("Enter the parent or guardian's name.", $r['error']);
        $r = mediaConsentInput(['consent_media' => '1', 'is_minor' => '1', 'guardian_name' => '  Pat   Lee '], true);
        $this->assertSame('Pat Lee', $r['consent']['guardian_name']);

        $r = mediaConsentInput(['consent_media' => '1'], false);
        $this->assertFalse($r['ok']);
        $this->assertSame('Confirm that this driver agreed, or untick the consent box.', $r['error']);
        $r = mediaConsentInput(['consent_media' => '1', 'on_behalf_confirm' => '1'], false);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['consent']['on_behalf']);

        $r = mediaConsentInput([], false);   // no consent at all needs no confirmation
        $this->assertTrue($r['ok']);
        $this->assertSame(0, $r['consent']['consent_media']);
    }

    public function testConsentChanged(): void
    {
        $none = ['consent_media' => 0, 'consent_public' => 0, 'is_minor' => 0, 'guardian_name' => null, 'on_behalf' => 0];
        $yes = ['consent_media' => 1] + $none;
        $this->assertFalse(mediaConsentChanged(null, $none));
        $this->assertTrue(mediaConsentChanged(null, $yes));
        $this->assertFalse(mediaConsentChanged($yes, $yes));
        $this->assertTrue(mediaConsentChanged($yes, ['consent_public' => 1] + $yes));
        $this->assertTrue(mediaConsentChanged($yes, ['is_minor' => 1, 'guardian_name' => 'Pat'] + $yes));
    }

    public function testValidateFields(): void
    {
        $r = mediaValidateFields([
            'blurb' => "Line one\r\nline two", 'pronunciation' => ' Sin-field ', 'hometown' => '',
            'racing_since' => '2015', 'social_handle' => '@fastjordan',
            'sponsor_name' => ['Acme', '', 'Bob\'s Garage', 'Evil'], 'sponsor_url' => ['acme.com', '', '', 'javascript:alert(1)'],
        ], 2026);
        $this->assertSame(['Sponsor 4 website must start with http:// or https://.'], $r['errors']);

        $r = mediaValidateFields([
            'blurb' => "Line one\r\nline two", 'pronunciation' => ' Sin-field ', 'hometown' => '',
            'racing_since' => '2015', 'social_handle' => '@fastjordan',
            'sponsor_name' => ['Acme', '', "Bob's Garage"], 'sponsor_url' => ['acme.com', '', ''],
        ], 2026);
        $this->assertSame([], $r['errors']);
        $this->assertSame(['blurb' => "Line one\nline two", 'pronunciation' => 'Sin-field', 'hometown' => null,
            'racing_since' => 2015, 'social_handle' => 'fastjordan'], $r['fields']);
        $this->assertSame([['name' => 'Acme', 'url' => 'https://acme.com'], ['name' => "Bob's Garage", 'url' => null]], $r['sponsors']);

        $this->assertSame(['Keep the blurb to 500 characters or fewer.'], mediaValidateFields(['blurb' => str_repeat('a', 501)], 2026)['errors']);
        $this->assertSame([], mediaValidateFields(['blurb' => str_repeat('é', 500)], 2026)['errors']);
        $this->assertSame(['Racing since must be a year from 1950 to 2026.'], mediaValidateFields(['racing_since' => '2027'], 2026)['errors']);
        $this->assertSame(['Racing since must be a year from 1950 to 2026.'], mediaValidateFields(['racing_since' => '15'], 2026)['errors']);
        $this->assertSame(['Sponsor 1 needs a name as well as a website.'], mediaValidateFields(['sponsor_name' => [''], 'sponsor_url' => ['acme.com']], 2026)['errors']);
        $this->assertSame(['Add at most 6 sponsors.'], mediaValidateFields(['sponsor_name' => array_fill(0, 7, 'X')], 2026)['errors']);
        $this->assertSame(['Hometown is too long (60 characters at most).'], mediaValidateFields(['hometown' => str_repeat('a', 61)], 2026)['errors']);
        $this->assertSame([], mediaValidateFields(['blurb' => ['not', 'a', 'string']], 2026)['errors']);   // junk input is ignored, not fatal
    }

    public function testContentChanged(): void
    {
        $p = $this->profile(['blurb' => 'Fast.', 'racing_since' => 2015]);
        $fields = ['blurb' => 'Fast.', 'pronunciation' => null, 'hometown' => null, 'racing_since' => 2015, 'social_handle' => null];
        $before = [['id' => 9, 'driver_id' => 5, 'name' => 'Acme', 'url' => null, 'sort_order' => 0]];
        $same = [['name' => 'Acme', 'url' => null]];
        $this->assertFalse(mediaContentChanged($p, $before, $fields, $same, false));
        $this->assertTrue(mediaContentChanged($p, $before, $fields, $same, true));
        $this->assertTrue(mediaContentChanged($p, $before, ['blurb' => 'Faster.'] + $fields, $same, false));
        $this->assertTrue(mediaContentChanged($p, $before, $fields, [['name' => 'Acme', 'url' => 'https://a.test']], false));
        $this->assertTrue(mediaContentChanged(null, [], $fields, [], false));
    }

    public function testNextPublicStatus(): void
    {
        $this->assertSame('none', mediaNextPublicStatus('accepted', false, false));
        $this->assertSame('pending_review', mediaNextPublicStatus('none', true, false));
        $this->assertSame('accepted', mediaNextPublicStatus('accepted', true, false));
        $this->assertSame('pending_review', mediaNextPublicStatus('accepted', true, true));
        $this->assertSame('pending_review', mediaNextPublicStatus('sent_back', true, true));
        $this->assertSame('sent_back', mediaNextPublicStatus('sent_back', true, false));
        $this->assertSame('pending_review', mediaNextPublicStatus('pending_review', true, true));
    }

    public function testProfileStatus(): void
    {
        $this->assertSame('Not set up', mediaProfileStatus(null, null)['label']);
        $this->assertSame('Not set up', mediaProfileStatus($this->profile(), null)['label']);
        $this->assertSame('Shared with clubs', mediaProfileStatus($this->profile(), $this->consentRow(1))['label']);
        $this->assertSame('Public page: waiting for review', mediaProfileStatus($this->profile(['public_status' => 'pending_review']), $this->consentRow(1, 1))['label']);
        $this->assertSame('Public page live', mediaProfileStatus($this->profile(['public_status' => 'accepted']), $this->consentRow(1, 1))['label']);
        $this->assertSame('Public page sent back', mediaProfileStatus($this->profile(['public_status' => 'sent_back']), $this->consentRow(1, 1))['label']);
        $hidden = mediaProfileStatus($this->profile(['hidden_at' => 'x', 'public_status' => 'accepted']), $this->consentRow(1, 1));
        $this->assertSame(['state' => 'hidden', 'label' => 'Hidden by WCMA', 'class' => 'hub-status--todo'], $hidden);
    }

    public function testPhotoAccess(): void
    {
        $driver = ['id' => 5, 'owner_user_id' => 7];
        $p = $this->profile(['photo_path' => 'uploads/media/driver-5-a.jpg']);
        $owner = ['id' => 7, 'role' => 'user'];
        $media = ['id' => 8, 'role' => 'user', 'is_media' => 1];
        $other = ['id' => 9, 'role' => 'user'];
        $this->assertTrue(mediaPhotoAllowed($owner, $driver, $p, null));
        $this->assertTrue(mediaPhotoAllowed($media, $driver, $p, null));
        $this->assertFalse(mediaPhotoAllowed($other, $driver, $p, $this->consentRow(1)));
        $this->assertFalse(mediaPhotoAllowed(null, $driver, $p, $this->consentRow(1, 1)));
        $live = $this->profile(['photo_path' => 'uploads/media/driver-5-a.jpg', 'public_status' => 'accepted']);
        $this->assertTrue(mediaPhotoAllowed(null, $driver, $live, $this->consentRow(1, 1)));
        $this->assertFalse(mediaPhotoAllowed(null, $driver, array_merge($live, ['hidden_at' => 'x']), $this->consentRow(1, 1)));
        $this->assertFalse(mediaPhotoAllowed($owner, $driver, $this->profile(['photo_path' => null]), null));
    }

    public function testRosterDriverIdsPreferEventSheetsThenLatestSheetThenOwner(): void
    {
        $eventSheets = [['id' => 10, 'driver_id' => 1], ['id' => 11, 'driver_id' => 1]];
        $sheetDrivers = [10 => [['driver_id' => 2]], 20 => [['driver_id' => 4]]];
        $this->assertSame([1, 2], mediaRosterDriverIds($eventSheets, ['id' => 20, 'driver_id' => 3], $sheetDrivers, 9));
        $this->assertSame([3, 4], mediaRosterDriverIds([], ['id' => 20, 'driver_id' => 3], $sheetDrivers, 9));
        $this->assertSame([9], mediaRosterDriverIds([], null, $sheetDrivers, 9));
        $this->assertSame([9], mediaRosterDriverIds([], ['id' => 30, 'driver_id' => null], [], 9));
        $this->assertSame([], mediaRosterDriverIds([], null, [], null));
    }

    public function testAcceptedClassAndCarLabel(): void
    {
        $this->assertSame('GT3', mediaAcceptedClass([['review_status' => 'submitted', 'calculated_class' => 'GT2'], ['review_status' => 'accepted', 'calculated_class' => 'GT3']]));
        $this->assertSame('', mediaAcceptedClass([['review_status' => 'submitted', 'calculated_class' => 'GT2']]));
        $this->assertSame('2004 Honda S2000 (Silver)', mediaCarLabel(['year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'colour' => 'Silver']));
        $this->assertSame('Mazda Miata (Red)', mediaCarLabel(['car_make' => 'Mazda', 'car_model' => 'Miata', 'car_colour' => 'Red']));
        $this->assertSame('Honda S2000', mediaCarLabel(['year' => '', 'make' => 'Honda', 'model' => 'S2000', 'colour' => null]));
    }

    public function testEntryCopyTextSlugAndCsv(): void
    {
        $e = mediaEntry(['id' => 5, 'name' => 'Jane Doe'], $this->profile(['blurb' => 'Loves the hairpin.', 'hometown' => 'Red Deer, AB',
            'racing_since' => 2015, 'photo_path' => 'uploads/media/x.jpg', 'pronunciation' => 'Doh', 'social_handle' => 'janed']),
            [['name' => 'Acme Tires', 'url' => 'https://acme.test'], ['name' => "Bob's Garage", 'url' => null]],
            '42', '2004 Honda S2000 (Silver)', 'GT3', true);
        $this->assertSame(5, $e['driver_id']);
        $this->assertTrue($e['has_photo']);
        $this->assertSame("#42 Jane Doe — 2004 Honda S2000 (Silver)\nRed Deer, AB · Racing since 2015\nLoves the hairpin.\nSupported by: Acme Tires, Bob's Garage",
            mediaCopyText($e));

        $bare = mediaEntry(['id' => 6, 'name' => 'Bo Bell'], $this->profile(['blurb' => '']), [], '', '', '', false);
        $this->assertSame('Bo Bell', mediaCopyText($bare));

        $this->assertSame('jane-doe', mediaSlug('  Jane  Doé!! '));
        $this->assertSame('driver', mediaSlug('!!!'));

        $rows = mediaCsvRows([$e, $bare], 'https://hub.test');
        $this->assertSame(['number', 'name', 'pronunciation', 'hometown', 'racing_since', 'car', 'class', 'blurb', 'sponsors', 'social_handle', 'public_url'], $rows[0]);
        $this->assertSame(['42', 'Jane Doe', 'Doh', 'Red Deer, AB', '2015', '2004 Honda S2000 (Silver)', 'GT3', 'Loves the hairpin.',
            "Acme Tires (https://acme.test); Bob's Garage", 'janed', 'https://hub.test/driver.php?id=5'], $rows[1]);
        $this->assertSame('', $rows[2][10]);
    }
}
