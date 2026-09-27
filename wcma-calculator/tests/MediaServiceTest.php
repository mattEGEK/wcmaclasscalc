<?php
// wcma-calculator/tests/MediaServiceTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../media-lib.php';
require_once __DIR__ . '/../media-service.php';
require_once __DIR__ . '/../pretech-email.php';
require_once __DIR__ . '/../media-email.php';

use PHPUnit\Framework\TestCase;

final class MediaServiceTest extends TestCase
{
    private string $base;

    protected function setUp(): void {
        $this->base = sys_get_temp_dir() . '/wcma_media_' . uniqid();
        mkdir($this->base, 0755, true);
    }

    private function user(PDO $pdo, string $email, string $name = 'Jordan Lee'): int {
        return db_create_user($pdo, ['email' => $email, 'name' => $name, 'password_hash' => 'x', 'google_id' => null]);
    }

    /** A real 1x1 PNG in a temp file, standing in for an upload. */
    private function png(): string {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        return $path;
    }

    public function testOnlyTheOwnerCanSave(): void
    {
        $pdo = make_temp_pdo();
        $a = $this->user($pdo, 'a@example.com', 'Ann Ames');
        $b = $this->user($pdo, 'b@example.com', 'Bo Bell');
        $db = (int)db_get_self_driver($pdo, $b)['id'];
        $r = mediaSaveProfile($pdo, $a, $db, ['blurb' => 'Hijack', 'consent_media' => '1'], null, $this->base, 'rename');
        $this->assertSame(['ok' => false, 'errors' => ['Choose one of your drivers.'], 'status' => null], $r);
        $this->assertNull(db_get_media_profile($pdo, $db));
        $this->assertNull(db_get_latest_media_consent($pdo, $db));
    }

    public function testSelfSaveWithPhotoConsentAndPublicRequest(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $d = (int)db_get_self_driver($pdo, $u)['id'];
        $r = mediaSaveProfile($pdo, $u, $d, [
            'blurb' => 'Fast.', 'sponsor_name' => ['Acme'], 'sponsor_url' => ['acme.test'],
            'consent_media' => '1', 'consent_public' => '1',
        ], $this->png(), $this->base, 'rename');
        $this->assertTrue($r['ok'], implode(' ', $r['errors']));
        $this->assertSame('pending_review', $r['status']);
        $p = db_get_media_profile($pdo, $d);
        $this->assertMatchesRegularExpression('#^uploads/media/driver-' . $d . '-[0-9a-f]{12}\.png$#', $p['photo_path']);
        $this->assertFileExists($this->base . '/' . $p['photo_path']);
        $this->assertSame('https://acme.test', db_get_sponsors($pdo, $d)[0]['url']);
        $c = db_get_latest_media_consent($pdo, $d);
        $this->assertSame([1, 1, 0, $u, MEDIA_CONSENT_WORDING_VERSION],
            [(int)$c['consent_media'], (int)$c['consent_public'], (int)$c['on_behalf'], (int)$c['given_by_user_id'], (int)$c['wording_version']]);

        // Re-saving the same thing adds no consent row and keeps the photo.
        mediaSaveProfile($pdo, $u, $d, ['blurb' => 'Fast.', 'sponsor_name' => ['Acme'], 'sponsor_url' => ['https://acme.test'],
            'consent_media' => '1', 'consent_public' => '1'], null, $this->base, 'rename');
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM media_consents")->fetchColumn());
        $this->assertSame($p['photo_path'], db_get_media_profile($pdo, $d)['photo_path']);
    }

    public function testReplacingThePhotoDeletesTheOldFileAndSendsAnAcceptedProfileBackToReview(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $d = (int)db_get_self_driver($pdo, $u)['id'];
        $post = ['blurb' => 'Fast.', 'consent_media' => '1', 'consent_public' => '1'];
        mediaSaveProfile($pdo, $u, $d, $post, $this->png(), $this->base, 'rename');
        $first = db_get_media_profile($pdo, $d)['photo_path'];
        db_set_media_public_status($pdo, $d, 'accepted', $u, null);

        $r = mediaSaveProfile($pdo, $u, $d, $post, $this->png(), $this->base, 'rename');
        $this->assertSame('pending_review', $r['status']);
        $this->assertFileDoesNotExist($this->base . '/' . $first);
        $this->assertFileExists($this->base . '/' . db_get_media_profile($pdo, $d)['photo_path']);
    }

    public function testCoDriverNeedsOnBehalfConfirmationAndRecordsIt(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $sam = db_create_driver($pdo, $u, 'Sam Patel');
        $r = mediaSaveProfile($pdo, $u, $sam, ['blurb' => 'Hi', 'consent_media' => '1'], null, $this->base, 'rename');
        $this->assertFalse($r['ok']);
        $this->assertSame(['Confirm that this driver agreed, or untick the consent box.'], $r['errors']);

        $r = mediaSaveProfile($pdo, $u, $sam, ['blurb' => 'Hi', 'consent_media' => '1', 'on_behalf_confirm' => '1',
            'is_minor' => '1', 'guardian_name' => 'Pat Patel'], null, $this->base, 'rename');
        $this->assertTrue($r['ok']);
        $c = db_get_latest_media_consent($pdo, $sam);
        $this->assertSame([1, 1, 'Pat Patel'], [(int)$c['on_behalf'], (int)$c['is_minor'], $c['guardian_name']]);
    }

    public function testValidationErrorsWriteNothingAndKeepNoFile(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $d = (int)db_get_self_driver($pdo, $u)['id'];
        $r = mediaSaveProfile($pdo, $u, $d, ['blurb' => str_repeat('a', 501)], $this->png(), $this->base, 'rename');
        $this->assertFalse($r['ok']);
        $this->assertNull(db_get_media_profile($pdo, $d));
        $this->assertDirectoryDoesNotExist($this->base . '/uploads/media');

        $bad = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($bad, 'not an image');
        $r = mediaSaveProfile($pdo, $u, $d, ['blurb' => 'ok'], $bad, $this->base, 'rename');
        $this->assertSame(['That file is not a photo we can read.'], $r['errors']);
    }

    public function testWithdrawTurnsConsentOffAndClearsPublicStatus(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $d = (int)db_get_self_driver($pdo, $u)['id'];
        mediaSaveProfile($pdo, $u, $d, ['blurb' => 'Fast.', 'consent_media' => '1', 'consent_public' => '1'], null, $this->base, 'rename');
        db_set_media_public_status($pdo, $d, 'accepted', $u, null);

        $this->assertFalse(mediaWithdraw($pdo, $u + 100, $d));
        $this->assertTrue(mediaWithdraw($pdo, $u, $d));
        $this->assertSame(['media' => false, 'public' => false], mediaCurrentConsent(db_get_latest_media_consent($pdo, $d)));
        $this->assertSame('none', db_get_media_profile($pdo, $d)['public_status']);
        $this->assertSame('Fast.', db_get_media_profile($pdo, $d)['blurb']);   // data kept
        $this->assertSame([], db_get_consented_driver_ids($pdo));
    }

    public function testDeleteRemovesPhotoProfileAndConsent(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $d = (int)db_get_self_driver($pdo, $u)['id'];
        mediaSaveProfile($pdo, $u, $d, ['blurb' => 'Fast.', 'consent_media' => '1'], $this->png(), $this->base, 'rename');
        $photo = db_get_media_profile($pdo, $d)['photo_path'];

        $this->assertTrue(mediaDeleteProfile($pdo, $u, $d, $this->base));
        $this->assertNull(db_get_media_profile($pdo, $d));
        $this->assertFileDoesNotExist($this->base . '/' . $photo);
        $this->assertFalse(mediaCurrentConsent(db_get_latest_media_consent($pdo, $d))['media']);
        $this->assertSame(2, (int)$pdo->query("SELECT COUNT(*) FROM media_consents")->fetchColumn());
    }

    public function testAnnouncerRosterUsesSheetsThenOwnerAndOnlyShowsConsentedProfiles(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $e = db_create_event($pdo, 'Fall Sprint', date('Y') . '-10-11', null);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        db_accept_declaration($pdo, $sub, $u);
        $sheet = test_make_sheet($pdo, $u, $sub, $e, '42', 'Jordan Lee');
        db_add_tech_sheet_driver($pdo, $sheet, 2, 'Sam Patel', '{}');
        $car7 = test_make_car($pdo, $u, '7');
        db_tag_event($pdo, $u, $e, $car7);   // tagged, never sheeted: falls back to the owner

        $self = (int)db_get_self_driver($pdo, $u)['id'];
        mediaSaveProfile($pdo, $u, $self, ['blurb' => 'Fast.', 'consent_media' => '1'], null, $this->base, 'rename');

        $roster = mediaAnnouncerRoster($pdo, $e);
        $this->assertSame(['7', '42'], array_column($roster, 'number'));
        $this->assertSame('IT1', $roster[1]['class']);
        $this->assertSame(['Jordan Lee', 'Sam Patel'], array_column($roster[1]['drivers'], 'name'));
        $this->assertSame('Fast.', $roster[1]['drivers'][0]['entry']['blurb']);
        $this->assertNull($roster[1]['drivers'][1]['entry']);   // Sam has no consent
        $this->assertSame('Jordan Lee', $roster[0]['drivers'][0]['name']);
        $this->assertSame('', $roster[0]['class']);   // no sheet and no accepted declaration
    }

    public function testKitEntriesListConsentedDriversWithTheirLatestCar(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $e = db_create_event($pdo, 'Fall Sprint', date('Y') . '-10-11', null);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        test_make_sheet($pdo, $u, $sub, $e, '42', 'Jordan Lee');
        $self = (int)db_get_self_driver($pdo, $u)['id'];
        mediaSaveProfile($pdo, $u, $self, ['blurb' => 'Fast.', 'consent_media' => '1'], null, $this->base, 'rename');
        $other = $this->user($pdo, 'o@example.com', 'Olive Odd');   // no consent

        $all = mediaKitEntries($pdo, 0, (int)date('Y'));
        $this->assertSame([$self], array_column($all, 'driver_id'));
        $this->assertSame('42', $all[0]['number']);
        $this->assertSame('Mazda MX-5 (Red)', $all[0]['car']);
        $this->assertSame([$self], array_column(mediaKitEntries($pdo, $e, (int)date('Y')), 'driver_id'));

        mediaWithdraw($pdo, $u, $self);
        $this->assertSame([], mediaKitEntries($pdo, 0, (int)date('Y')));
    }

    public function testReviewActions(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $d = (int)db_get_self_driver($pdo, $u)['id'];
        $this->assertSame('That profile no longer exists.', mediaReviewAction($pdo, 'media-accept', $d, $u, '')['error']);
        mediaSaveProfile($pdo, $u, $d, ['blurb' => 'Fast.', 'consent_media' => '1', 'consent_public' => '1'], null, $this->base, 'rename');

        $this->assertSame('Add a note so the driver knows what to change.', mediaReviewAction($pdo, 'media-send-back', $d, $u, ' ')['error']);
        $r = mediaReviewAction($pdo, 'media-send-back', $d, $u, '  Brighter   photo ');
        $this->assertSame(['ok' => true, 'error' => null, 'notify' => 'sent_back', 'note' => 'Brighter photo'], $r);
        $this->assertSame('That profile is not waiting for review.', mediaReviewAction($pdo, 'media-accept', $d, $u, '')['error']);

        mediaSaveProfile($pdo, $u, $d, ['blurb' => 'Faster.', 'consent_media' => '1', 'consent_public' => '1'], null, $this->base, 'rename');
        $this->assertSame(['ok' => true, 'error' => null, 'notify' => null, 'note' => ''], mediaReviewAction($pdo, 'media-accept', $d, $u, ''));
        $this->assertSame('accepted', db_get_media_profile($pdo, $d)['public_status']);

        $this->assertSame('Add a reason for hiding it.', mediaReviewAction($pdo, 'media-hide', $d, $u, '')['error']);
        $this->assertSame('hidden', mediaReviewAction($pdo, 'media-hide', $d, $u, 'Sponsor dispute')['notify']);
        $this->assertFalse(mediaUsable(db_get_media_profile($pdo, $d), db_get_latest_media_consent($pdo, $d), 'club'));
        mediaReviewAction($pdo, 'media-unhide', $d, $u, '');
        $this->assertTrue(mediaUsable(db_get_media_profile($pdo, $d), db_get_latest_media_consent($pdo, $d), 'public'));
        $this->assertSame('Unknown action.', mediaReviewAction($pdo, 'media-nuke', $d, $u, '')['error']);
    }

    public function testNotifyOwnerEmailsTheManagingAccountAndSurvivesAFailedSend(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $sam = db_create_driver($pdo, $u, 'Sam Patel');
        $sent = [];
        $ok = mediaNotifyOwner($pdo, 'sent_back', $sam, 'Brighter photo', 'https://hub.test', function (array $to, array $m) use (&$sent): bool {
            $sent[] = [$to, $m];
            return true;
        });
        $this->assertTrue($ok);
        $this->assertSame([['j@example.com', 'Jordan Lee']], $sent[0][0]);
        $this->assertStringContainsString('https://hub.test/media-profile.php?driver_id=' . $sam, $sent[0][1]['text']);
        $this->assertFalse(mediaNotifyOwner($pdo, 'hidden', $sam, 'x', 'https://hub.test', function (): bool { throw new RuntimeException('smtp down'); }));
    }
}
