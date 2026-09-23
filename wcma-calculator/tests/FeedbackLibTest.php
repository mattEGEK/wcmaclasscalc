<?php
use PHPUnit\Framework\TestCase;

final class FeedbackLibTest extends TestCase
{
    private function row(array $o = []): array
    {
        return array_merge([
            'id' => 7, 'type' => 'bug',
            'message' => "Class is wrong @octocat\nsecond line",
            'name' => 'Pat', 'email' => 'pat@example.com', 'user_id' => 3,
            'page_url' => 'https://x.test/car-classing.html',
            'user_agent' => 'UA', 'viewport' => '1200x800',
            'calc_inputs' => '{"competitionWeight":"2800"}',
        ], $o);
    }

    // ── validate ──
    public function testValidateAcceptsGoodInput(): void
    {
        [$clean, $errors] = feedbackValidate([
            'type' => 'idea', 'message' => '  Add dark mode  ', 'email' => 'a@b.co',
            'page_url' => 'https://x.test/', 'viewport' => '1x1', 'calc_inputs' => '{"a":1}',
        ]);
        $this->assertSame([], $errors);
        $this->assertSame('idea', $clean['type']);
        $this->assertSame('Add dark mode', $clean['message']);
        $this->assertSame('a@b.co', $clean['email']);
        $this->assertSame('{"a":1}', $clean['calc_inputs']);
    }

    public function testValidateRejectsBadTypeEmptyMessageBadEmail(): void
    {
        [, $errors] = feedbackValidate(['type' => 'nope', 'message' => '  ', 'email' => 'not-an-email']);
        $this->assertCount(3, $errors);
    }

    public function testValidateRejectsTooLongMessage(): void
    {
        [, $errors] = feedbackValidate(['type' => 'bug', 'message' => str_repeat('a', 2001)]);
        $this->assertCount(1, $errors);
        [, $ok] = feedbackValidate(['type' => 'bug', 'message' => str_repeat('a', 2000)]);
        $this->assertSame([], $ok);
    }

    public function testValidateBlankEmailBecomesNullAndBadCalcInputsDropped(): void
    {
        [$clean, $errors] = feedbackValidate(['type' => 'bug', 'message' => 'x', 'email' => '', 'calc_inputs' => 'not json']);
        $this->assertSame([], $errors);
        $this->assertNull($clean['email']);
        $this->assertNull($clean['calc_inputs']);
    }

    public function testValidateCollapsesWhitespaceInPageUrlAndViewport(): void
    {
        [$clean] = feedbackValidate([
            'type' => 'bug', 'message' => 'x',
            'page_url' => "https://x/
@someuser  y", 'viewport' => "10
x 5",
        ]);
        $this->assertSame('https://x/ @someuser y', $clean['page_url']);
        $this->assertSame('10 x 5', $clean['viewport']);
    }

    // ── issue ──
    public function testBuildIssueTitleLabelsAndBody(): void
    {
        $issue = feedbackBuildIssue($this->row(), 'https://x.test/admin.php?action=feedback-view&id=7');

        $this->assertSame("[Bug] Class is wrong @\u{200B}octocat second line", $issue['title']);
        $this->assertSame(['feedback', 'bug'], $issue['labels']);
        $this->assertStringContainsString("> Class is wrong @\u{200B}octocat", $issue['body']);
        $this->assertStringContainsString('> second line', $issue['body']);
        $this->assertStringNotContainsString('@octocat', $issue['body']);
        $this->assertStringContainsString("pat@\u{200B}example.com", $issue['body']);
        $this->assertStringContainsString('```json', $issue['body']);
        $this->assertStringContainsString('competitionWeight', $issue['body']);
        $this->assertStringContainsString('https://x.test/admin.php?action=feedback-view&id=7', $issue['body']);
    }

    public function testBuildIssueMetadataLinesAreSingleLineAndNeutralised(): void
    {
        $issue = feedbackBuildIssue($this->row([
            'page_url' => "https://x/
@octocat",
            'user_agent' => "UA
@octocat",
            'viewport' => "1
@octocat",
        ]), 'u');
        $this->assertStringNotContainsString('@octocat', $issue['body']);
        foreach (['Page', 'Browser', 'Viewport'] as $label) {
            $this->assertSame(1, preg_match_all('/^- \*\*' . $label . ':\*\* `[^`
]*`$/m', $issue['body']), $label);
        }
    }

    public function testBuildIssueTruncatesTitleAt60Chars(): void
    {
        $issue = feedbackBuildIssue($this->row(['type' => 'feedback', 'message' => str_repeat('a', 100)]), 'u');
        $this->assertSame('[Feedback] ' . str_repeat('a', 60) . '…', $issue['title']);
        $this->assertSame(['feedback'], $issue['labels']);
    }

    public function testBuildIssueIdeaLabelsAndNoCalcBlock(): void
    {
        $issue = feedbackBuildIssue($this->row(['type' => 'idea', 'calc_inputs' => null]), 'u');
        $this->assertSame(['feedback', 'enhancement'], $issue['labels']);
        $this->assertStringNotContainsString('```json', $issue['body']);
    }

    // ── email ──
    public function testBuildEmailEscapesHtml(): void
    {
        $mail = feedbackBuildEmail($this->row(['message' => '<script>x</script>']), 'https://github.com/o/r/issues/1', 'https://x.test/a');
        $this->assertStringContainsString('&lt;script&gt;', $mail['html']);
        $this->assertStringNotContainsString('<script>', $mail['html']);
        $this->assertStringContainsString('https://github.com/o/r/issues/1', $mail['html']);
        $this->assertStringContainsString('Bug', $mail['subject']);
        $this->assertStringContainsString('https://x.test/a', $mail['text']);
    }

    public function testBuildEmailSubjectHasNoNewlines(): void
    {
        $mail = feedbackBuildEmail($this->row(['message' => "line1\r\nBcc: evil@x.test"]), null, 'u');
        $this->assertStringNotContainsString("\n", $mail['subject']);
        $this->assertStringNotContainsString("\r", $mail['subject']);
    }

    // ── recipient / urls ──
    public function testRecipientDefaultsThenOverride(): void
    {
        $pdo = make_temp_pdo();
        $this->assertSame(['email' => 'classing@wcma.ca', 'name' => 'WCMA Classing'], feedbackRecipient($pdo));

        db_set_setting($pdo, 'feedback_recipient_email', 'fb@wcma.ca');
        db_set_setting($pdo, 'feedback_recipient_name', 'Feedback Team');
        $this->assertSame(['email' => 'fb@wcma.ca', 'name' => 'Feedback Team'], feedbackRecipient($pdo));
    }

    public function testBaseUrlAndAdminUrl(): void
    {
        $base = feedbackBaseUrl(['HTTPS' => 'on', 'HTTP_HOST' => '221racing.com', 'SCRIPT_NAME' => '/classing/feedback.php']);
        $this->assertSame('https://221racing.com/classing', $base);
        $this->assertSame('https://221racing.com/classing/admin.php?action=feedback-view&id=9', feedbackAdminUrl($base . '/', 9));
        $this->assertSame('http://localhost:8080', feedbackBaseUrl(['HTTP_HOST' => 'localhost:8080', 'SCRIPT_NAME' => '/feedback.php']));
        $evil = ['HTTPS' => 'on', 'HTTP_HOST' => 'evil.test', 'SCRIPT_NAME' => '/x/feedback.php'];
        $this->assertSame('https://221racing.com/classing', feedbackBaseUrl($evil, ' https://221racing.com/classing/ '));
        $this->assertSame('https://evil.test/x', feedbackBaseUrl($evil, '  '));
    }

    public function testHashIpIsStableAndNotThePlainIp(): void
    {
        $this->assertSame(feedbackHashIp('1.2.3.4'), feedbackHashIp('1.2.3.4'));
        $this->assertNotSame(feedbackHashIp('1.2.3.4'), feedbackHashIp('1.2.3.5'));
        $this->assertStringNotContainsString('1.2.3.4', feedbackHashIp('1.2.3.4'));
    }

    public function testReporterText(): void
    {
        $this->assertSame('Pat (pat@example.com) user #3', feedbackReporterText($this->row()));
        $this->assertSame('Anonymous guest', feedbackReporterText(['name' => null, 'email' => null, 'user_id' => null]));
    }
}
