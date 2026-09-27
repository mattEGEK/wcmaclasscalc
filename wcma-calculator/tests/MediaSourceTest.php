<?php
// wcma-calculator/tests/MediaSourceTest.php
//
// Source-level guards for the media controllers (they need config.php/sessions, so they cannot run here).
use PHPUnit\Framework\TestCase;

final class MediaSourceTest extends TestCase
{
    private function src(string $file): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
    }

    public function testSessionCarriesTheMediaFlag(): void
    {
        $src = $this->src('session_bootstrap.php');
        $this->assertStringContainsString("'is_media' => (int)(\$_SESSION['user_is_media'] ?? 0)", $src);
        $this->assertStringContainsString("\$_SESSION['user_is_media'] = (int)(\$user['is_media'] ?? 0);", $src);
    }

    public function testAdminCanSetTheMediaFlagByCsrfCheckedPost(): void
    {
        $src = $this->src('admin.php');
        $this->assertMatchesRegularExpression("/case 'set-media':\s*adminRequirePost\('admin.php\?action=users'\);\s*handleSetMedia\(\\\$pdo, \\\$postId, \(\\\$_POST\['is_media'\] \?\? ''\) === '1'\);/", $src);
        $this->assertStringContainsString('function handleSetMedia(PDO $pdo, int $id, bool $on): void', $src);
        $this->assertStringContainsString('action="admin.php?action=set-media"', $src);
        $this->assertStringContainsString('name="is_media" value="1"', $src);
    }

    public function testMediaProfileControllerGuardsOwnershipAndCsrf(): void
    {
        $src = $this->src('media-profile.php');
        $this->assertStringContainsString("require_role('user')", $src);
        $this->assertMatchesRegularExpression("/REQUEST_METHOD'\] === 'POST'\) \{\s*if \(!validateCsrfToken\(/", $src);
        $this->assertStringContainsString('mediaOwnedDriver($pdo, $uid, $driverId)', $src);
        $this->assertStringContainsString('http_response_code(404)', $src);
        $this->assertStringContainsString('UPLOAD_ERR_NO_FILE', $src);
        $this->assertStringContainsString('js/photo-resize.js', $src);
    }

    public function testPhotoEndpointChecksAccessAndNeverRevealsWhy(): void
    {
        $src = $this->src('media-photo.php');
        $this->assertStringContainsString('mediaPhotoAllowed(current_user(), $driver, $profile, db_get_latest_media_consent($pdo, $driverId))', $src);
        $this->assertStringContainsString("header('X-Content-Type-Options: nosniff');", $src);
        $this->assertStringContainsString("header('Cache-Control: private, max-age=0, must-revalidate');", $src);
        $this->assertSame(1, substr_count($src, 'readfile('));
        $this->assertStringContainsString("str_starts_with(\$path, MEDIA_PHOTO_DIR . '/')", $src);
    }

    public function testHomeDismissesThePromptAndDriversPageLoadsMediaStatus(): void
    {
        $index = $this->src('index.php');
        $this->assertStringContainsString("case 'media-prompt-dismiss':", $index);
        $this->assertStringContainsString('db_dismiss_media_prompt($pdo, $uid);', $index);
        $this->assertStringContainsString("'mediaPrompt' =>", $index);
        $drivers = $this->src('drivers.php');
        $this->assertStringContainsString('db_get_media_bundle($pdo, array_map(fn(array $d): int => (int)$d[\'id\'], $drivers))', $drivers);
        $profile = $this->src('media-profile.php');
        $this->assertStringContainsString("=== 'self'", $profile);
    }

    public function testMediaControllerIsGatedAndReviewActionsArePostOnly(): void
    {
        $src = $this->src('media.php');
        $this->assertStringContainsString("\$user = require_role('user');", $src);
        $this->assertMatchesRegularExpression('/if \(!mediaCanAccess\(\$user\)\) \{\s*http_response_code\(403\);\s*hubRenderForbidden\(\);\s*exit;/', $src);
        $this->assertStringContainsString("class_exists('ZipArchive')", $src);
    }

    public function testZipBuildChecksOpenAndAlwaysCleansUpTheTempFile(): void
    {
        $src = $this->src('media.php');
        $this->assertStringContainsString('$zip->open($tmp, ZipArchive::OVERWRITE) === true', $src);
        $this->assertMatchesRegularExpression('/finally\s*\{.*?unlink\(\$tmp\);.*?\}/s', $src);
    }

    public function testReviewPostsAreCsrfCheckedAndEmailTheOwner(): void
    {
        $src = $this->src('media.php');
        $this->assertMatchesRegularExpression("/if \(in_array\(\\\$action, MEDIA_POST_ACTIONS, true\)\) \{\s*if \(\\\$_SERVER\['REQUEST_METHOD'\] !== 'POST'\)/", $src);
        $this->assertStringContainsString("if (!validateCsrfToken(\$_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }", $src);
        $this->assertStringContainsString("mediaNotifyOwner(\$pdo, \$r['notify']", $src);
        $this->assertStringContainsString("'emailSmtpSend'", $src);
    }
}
