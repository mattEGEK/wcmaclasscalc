<?php
use PHPUnit\Framework\TestCase;

final class FeedbackSyncTest extends TestCase
{
    private const CFG = ['token' => 'tok', 'repo' => 'o/r', 'admin_base' => 'https://x.test'];

    private function seed(PDO $pdo): int
    {
        return db_insert_feedback($pdo, [
            'type' => 'bug', 'message' => 'Broken', 'name' => 'Pat', 'email' => 'p@x.co',
            'created_at' => '2026-09-23 10:00:00',
        ]);
    }

    private function ctx(array $o = []): array
    {
        return array_merge([
            'ip' => '1.2.3.4', 'now' => strtotime('2026-09-23 12:00:00'),
            'user' => null, 'user_agent' => 'UA', 'rate_limit' => 5, 'rate_window' => 3600,
            'github' => self::CFG,
        ], $o);
    }

    private function good(): array
    {
        return ['type' => 'bug', 'message' => 'Something broke', 'email' => '', 'website' => ''];
    }

    private function okHttp(): callable
    {
        return fn($url, $token, $payload) => ['status' => 201, 'body' => json_encode(['number' => 12, 'html_url' => 'https://github.com/o/r/issues/12'])];
    }

    // ── sync ──
    public function testSyncSuccessStoresIssueAndSendsCorrectRequest(): void
    {
        $pdo = make_temp_pdo();
        $id = $this->seed($pdo);
        $seen = [];
        $http = function ($url, $token, $payload) use (&$seen) {
            $seen = compact('url', 'token', 'payload');
            return ['status' => 201, 'body' => json_encode(['number' => 12, 'html_url' => 'https://github.com/o/r/issues/12'])];
        };

        $this->assertTrue(feedbackSyncToGithub($pdo, $id, self::CFG, $http));
        $row = db_get_feedback($pdo, $id);
        $this->assertSame(12, (int)$row['github_issue_number']);
        $this->assertSame('https://github.com/o/r/issues/12', $row['github_issue_url']);
        $this->assertSame('https://api.github.com/repos/o/r/issues', $seen['url']);
        $this->assertSame('tok', $seen['token']);
        $this->assertSame('[Bug] Broken', $seen['payload']['title']);
        $this->assertSame(['feedback', 'bug'], $seen['payload']['labels']);
    }

    public function testSyncHttpErrorRecordsError(): void
    {
        $pdo = make_temp_pdo();
        $id = $this->seed($pdo);
        $http = fn() => ['status' => 401, 'body' => '{"message":"Bad credentials"}'];

        $this->assertFalse(feedbackSyncToGithub($pdo, $id, self::CFG, $http));
        $row = db_get_feedback($pdo, $id);
        $this->assertNull($row['github_issue_number']);
        $this->assertStringContainsString('HTTP 401', $row['github_error']);
    }

    public function testSyncExceptionRecordsError(): void
    {
        $pdo = make_temp_pdo();
        $id = $this->seed($pdo);
        $http = function () { throw new RuntimeException('timeout'); };

        $this->assertFalse(feedbackSyncToGithub($pdo, $id, self::CFG, $http));
        $this->assertSame('timeout', db_get_feedback($pdo, $id)['github_error']);
    }

    public function testSyncSkippedWithoutTokenAndRecordsNothing(): void
    {
        $pdo = make_temp_pdo();
        $id = $this->seed($pdo);
        $called = false;
        $http = function () use (&$called) { $called = true; return ['status' => 201, 'body' => '{}']; };

        $this->assertFalse(feedbackSyncToGithub($pdo, $id, ['token' => '', 'repo' => 'o/r', 'admin_base' => ''], $http));
        $this->assertFalse($called);
        $this->assertNull(db_get_feedback($pdo, $id)['github_error']);
    }

    public function testSyncDoesNotDuplicateAlreadySyncedIssue(): void
    {
        $pdo = make_temp_pdo();
        $id = $this->seed($pdo);
        db_set_feedback_github($pdo, $id, 5, 'https://github.com/o/r/issues/5');
        $called = false;
        $http = function () use (&$called) { $called = true; return ['status' => 201, 'body' => '{}']; };

        $this->assertTrue(feedbackSyncToGithub($pdo, $id, self::CFG, $http));
        $this->assertFalse($called);
    }

    public function testRetryAfterFailureSucceedsAndClearsError(): void
    {
        $pdo = make_temp_pdo();
        $id = $this->seed($pdo);
        feedbackSyncToGithub($pdo, $id, self::CFG, fn() => ['status' => 500, 'body' => 'oops']);
        $this->assertNotNull(db_get_feedback($pdo, $id)['github_error']);

        $this->assertTrue(feedbackSyncToGithub($pdo, $id, self::CFG, $this->okHttp()));
        $this->assertNull(db_get_feedback($pdo, $id)['github_error']);
    }

    // ── orchestrator ──
    public function testSubmissionSuccessStoresSyncsAndNotifies(): void
    {
        $pdo = make_temp_pdo();
        $notified = [];
        $notify = function ($row, $issueUrl) use (&$notified) { $notified = [$row, $issueUrl]; };

        $res = feedbackHandleSubmission($pdo, $this->good(), $this->ctx(), $this->okHttp(), $notify);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['body']['ok']);
        $rows = db_get_all_feedback($pdo);
        $this->assertCount(1, $rows);
        $this->assertSame('Something broke', $rows[0]['message']);
        $this->assertSame(12, (int)$rows[0]['github_issue_number']);
        $this->assertSame('UA', $rows[0]['user_agent']);
        $this->assertNotSame('1.2.3.4', $rows[0]['ip_hash']);
        $this->assertSame('https://github.com/o/r/issues/12', $notified[1]);
    }

    public function testSubmissionValidationErrorReturns422AndStoresNothing(): void
    {
        $pdo = make_temp_pdo();
        $res = feedbackHandleSubmission($pdo, ['type' => 'bug', 'message' => ''], $this->ctx(), $this->okHttp(), fn() => null);
        $this->assertSame(422, $res['status']);
        $this->assertFalse($res['body']['ok']);
        $this->assertCount(0, db_get_all_feedback($pdo));
    }

    public function testHoneypotReturnsSuccessButStoresNothing(): void
    {
        $pdo = make_temp_pdo();
        $in = $this->good() + [];
        $in['website'] = 'http://spam.test';
        $res = feedbackHandleSubmission($pdo, $in, $this->ctx(), $this->okHttp(), fn() => null);
        $this->assertSame(200, $res['status']);
        $this->assertCount(0, db_get_all_feedback($pdo));
    }

    public function testRateLimitReturns429AfterLimit(): void
    {
        $pdo = make_temp_pdo();
        $ctx = $this->ctx(['rate_limit' => 2]);
        $this->assertSame(200, feedbackHandleSubmission($pdo, $this->good(), $ctx, $this->okHttp(), fn() => null)['status']);
        $this->assertSame(200, feedbackHandleSubmission($pdo, $this->good(), $ctx, $this->okHttp(), fn() => null)['status']);
        $third = feedbackHandleSubmission($pdo, $this->good(), $ctx, $this->okHttp(), fn() => null);
        $this->assertSame(429, $third['status']);
        $this->assertCount(2, db_get_all_feedback($pdo));
    }

    public function testRateLimitIgnoresOtherIpsAndOldSubmissions(): void
    {
        $pdo = make_temp_pdo();
        $ctx = $this->ctx(['rate_limit' => 1]);
        feedbackHandleSubmission($pdo, $this->good(), $ctx, $this->okHttp(), fn() => null);

        $other = feedbackHandleSubmission($pdo, $this->good(), $this->ctx(['rate_limit' => 1, 'ip' => '9.9.9.9']), $this->okHttp(), fn() => null);
        $this->assertSame(200, $other['status']);

        $later = feedbackHandleSubmission($pdo, $this->good(), $this->ctx(['rate_limit' => 1, 'now' => strtotime('2026-09-23 14:00:00')]), $this->okHttp(), fn() => null);
        $this->assertSame(200, $later['status']);
    }

    public function testGithubFailureStillSucceedsForUser(): void
    {
        $pdo = make_temp_pdo();
        $res = feedbackHandleSubmission($pdo, $this->good(), $this->ctx(), fn() => ['status' => 500, 'body' => 'x'], fn() => null);
        $this->assertSame(200, $res['status']);
        $row = db_get_all_feedback($pdo)[0];
        $this->assertNotNull($row['github_error']);
    }

    public function testNotifyExceptionStillSucceedsForUser(): void
    {
        $pdo = make_temp_pdo();
        $notify = function () { throw new RuntimeException('smtp down'); };
        $res = feedbackHandleSubmission($pdo, $this->good(), $this->ctx(), $this->okHttp(), $notify);
        $this->assertSame(200, $res['status']);
        $this->assertCount(1, db_get_all_feedback($pdo));
    }

    public function testLoggedInUserIsRecordedAndEmailFallsBack(): void
    {
        $pdo = make_temp_pdo();
        $ctx = $this->ctx(['user' => ['id' => 4, 'name' => 'Sam', 'email' => 'sam@x.co']]);
        feedbackHandleSubmission($pdo, $this->good(), $ctx, $this->okHttp(), fn() => null);

        $row = db_get_all_feedback($pdo)[0];
        $this->assertSame(4, (int)$row['user_id']);
        $this->assertSame('Sam', $row['name']);
        $this->assertSame('sam@x.co', $row['email']);
    }

    public function testGithubSkippedWhenTokenEmpty(): void
    {
        $pdo = make_temp_pdo();
        $called = false;
        $http = function () use (&$called) { $called = true; return ['status' => 201, 'body' => '{}']; };
        $ctx = $this->ctx(['github' => ['token' => '', 'repo' => 'o/r', 'admin_base' => '']]);
        $res = feedbackHandleSubmission($pdo, $this->good(), $ctx, $http, fn() => null);

        $this->assertSame(200, $res['status']);
        $this->assertFalse($called);
        $this->assertCount(1, db_get_all_feedback($pdo));
    }
}
