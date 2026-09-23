<?php
/**
 * Feedback: validation, GitHub issue / email content, recipient lookup.
 * No session, PHPMailer or network dependencies — the endpoint injects those —
 * so everything here is unit-testable. Requires db.php to be loaded first.
 */

const FEEDBACK_TYPES       = ['bug', 'feedback', 'idea'];
const FEEDBACK_STATUSES    = ['new', 'triaged', 'resolved'];
const FEEDBACK_MESSAGE_MAX = 2000;

function feedbackTypeLabel(string $type): string {
    return ['bug' => 'Bug', 'feedback' => 'Feedback', 'idea' => 'Idea'][$type] ?? 'Feedback';
}

function feedbackHashIp(string $ip): string {
    return hash('sha256', 'wcma-feedback|' . $ip);
}

function feedbackRecipient(PDO $pdo): array {
    return [
        'email' => db_get_setting($pdo, 'feedback_recipient_email', config_default('FEEDBACK_RECIPIENT_EMAIL', 'classing@wcma.ca')),
        'name'  => db_get_setting($pdo, 'feedback_recipient_name', config_default('FEEDBACK_RECIPIENT_NAME', 'WCMA Classing')),
    ];
}

/** @return array{0: array, 1: string[]} [clean fields, error messages] */
function feedbackValidate(array $in): array {
    $errors = [];

    $type = (string)($in['type'] ?? '');
    if (!in_array($type, FEEDBACK_TYPES, true)) {
        $errors[] = 'Please choose a type.';
    }

    $message = trim((string)($in['message'] ?? ''));
    if ($message === '') {
        $errors[] = 'Please enter a message.';
    } elseif (mb_strlen($message) > FEEDBACK_MESSAGE_MAX) {
        $errors[] = 'Message must be ' . FEEDBACK_MESSAGE_MAX . ' characters or fewer.';
    }

    $email = trim((string)($in['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address or leave it blank.';
    }

    $calc = null;
    $rawCalc = (string)($in['calc_inputs'] ?? '');
    if ($rawCalc !== '') {
        $decoded = json_decode($rawCalc, true);
        if (is_array($decoded)) {
            $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES);
            if ($encoded !== false && strlen($encoded) <= 4000) {
                $calc = $encoded;
            }
        }
    }

    return [[
        'type'        => $type,
        'message'     => $message,
        'email'       => $email !== '' ? $email : null,
        'page_url'    => mb_substr(trim((string)($in['page_url'] ?? '')), 0, 500),
        'viewport'    => mb_substr(trim((string)($in['viewport'] ?? '')), 0, 30),
        'calc_inputs' => $calc,
    ], $errors];
}

function feedbackReporterText(array $row): string {
    $parts = [];
    if (!empty($row['name']))    $parts[] = $row['name'];
    if (!empty($row['email']))   $parts[] = '(' . $row['email'] . ')';
    if (!empty($row['user_id'])) $parts[] = 'user #' . (int)$row['user_id'];
    return $parts ? implode(' ', $parts) : 'Anonymous guest';
}

function feedbackNeutraliseMentions(string $text): string {
    return str_replace('@', "@\u{200B}", $text);
}

function feedbackTitleSnippet(string $message, int $max = 60): string {
    $one = trim((string)preg_replace('/\s+/u', ' ', $message));
    return mb_strlen($one) > $max ? mb_substr($one, 0, $max) . '…' : $one;
}

function feedbackInlineCode(string $s): string {
    return '`' . str_replace('`', "'", $s) . '`';
}

/** @return array{title: string, body: string, labels: string[]} */
function feedbackBuildIssue(array $row, string $adminUrl): array {
    $type   = (string)($row['type'] ?? 'feedback');
    $labels = ['bug' => ['feedback', 'bug'], 'feedback' => ['feedback'], 'idea' => ['feedback', 'enhancement']][$type] ?? ['feedback'];
    $title  = feedbackNeutraliseMentions('[' . feedbackTypeLabel($type) . '] ' . feedbackTitleSnippet((string)$row['message']));

    $quoted = implode("\n", array_map(
        fn($line) => '> ' . $line,
        preg_split('/\R/u', feedbackNeutraliseMentions((string)$row['message']))
    ));

    $body  = $quoted . "\n\n";
    $body .= '- **Type:** ' . feedbackTypeLabel($type) . "\n";
    $body .= '- **Reporter:** ' . feedbackInlineCode(feedbackReporterText($row)) . "\n";
    if (!empty($row['page_url']))   $body .= '- **Page:** '     . feedbackInlineCode((string)$row['page_url']) . "\n";
    if (!empty($row['user_agent'])) $body .= '- **Browser:** '  . feedbackInlineCode((string)$row['user_agent']) . "\n";
    if (!empty($row['viewport']))   $body .= '- **Viewport:** ' . feedbackInlineCode((string)$row['viewport']) . "\n";
    $body .= '- **Admin entry:** ' . $adminUrl . "\n";

    if (!empty($row['calc_inputs'])) {
        $decoded = json_decode((string)$row['calc_inputs'], true);
        if (is_array($decoded)) {
            $pretty = str_replace('```', "'''", (string)json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $body .= "\n**Calculator inputs**\n\n```json\n" . $pretty . "\n```\n";
        }
    }

    return ['title' => $title, 'body' => $body, 'labels' => $labels];
}

/** @return array{subject: string, html: string, text: string} */
function feedbackBuildEmail(array $row, ?string $issueUrl, string $adminUrl): array {
    $typeLabel = feedbackTypeLabel((string)($row['type'] ?? 'feedback'));
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $reporter = feedbackReporterText($row);

    $subject = 'WCMA Feedback (' . $typeLabel . '): ' . feedbackTitleSnippet((string)$row['message']);

    $rows = [
        ['Type', $typeLabel],
        ['From', $reporter],
        ['Page', (string)($row['page_url'] ?? '')],
        ['Browser', (string)($row['user_agent'] ?? '')],
        ['Viewport', (string)($row['viewport'] ?? '')],
    ];

    $html  = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">';
    $html .= '<h2 style="color: #1a5490;">New ' . $esc($typeLabel) . ' from the WCMA Calculator</h2>';
    $html .= '<p>' . nl2br($esc($row['message'])) . '</p>';
    $html .= '<table cellpadding="5" cellspacing="0">';
    foreach ($rows as [$label, $value]) {
        if ($value === '') continue;
        $html .= '<tr><td><strong>' . $esc($label) . ':</strong></td><td>' . $esc($value) . '</td></tr>';
    }
    if (!empty($row['calc_inputs'])) {
        $html .= '<tr><td><strong>Calculator inputs:</strong></td><td><code>' . $esc($row['calc_inputs']) . '</code></td></tr>';
    }
    if ($issueUrl) {
        $html .= '<tr><td><strong>GitHub issue:</strong></td><td><a href="' . $esc($issueUrl) . '">' . $esc($issueUrl) . '</a></td></tr>';
    }
    $html .= '<tr><td><strong>Admin entry:</strong></td><td><a href="' . $esc($adminUrl) . '">' . $esc($adminUrl) . '</a></td></tr>';
    $html .= '</table></body></html>';

    $text  = 'New ' . $typeLabel . " from the WCMA Calculator\n\n" . $row['message'] . "\n\n";
    $text .= 'From: ' . $reporter . "\n";
    if (!empty($row['page_url'])) $text .= 'Page: ' . $row['page_url'] . "\n";
    if ($issueUrl) $text .= 'GitHub issue: ' . $issueUrl . "\n";
    $text .= 'Admin entry: ' . $adminUrl . "\n";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

function feedbackBaseUrl(array $server): string {
    $https = !empty($server['HTTPS']) && $server['HTTPS'] !== 'off';
    $dir   = rtrim(str_replace('\\', '/', dirname($server['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($server['HTTP_HOST'] ?? 'localhost') . $dir;
}

function feedbackAdminUrl(string $base, int $id): string {
    return rtrim($base, '/') . '/admin.php?action=feedback-view&id=' . $id;
}

// ── GitHub sync ───────────────────────────────────────────────────────────────

function feedbackGithubConfig(string $adminBase): array {
    return [
        'token'      => config_default('GITHUB_TOKEN', ''),
        'repo'       => config_default('GITHUB_REPO', 'mattEGEK/wcmaclasscalc'),
        'admin_base' => $adminBase,
    ];
}

/** Real transport for feedbackSyncToGithub(). Throws RuntimeException on transport errors. */
function feedbackGithubHttp(string $url, string $token, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: wcma-calculator',
            'Content-Type: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('GitHub request failed: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => (string)$body];
}

/**
 * Creates a GitHub issue for a stored feedback row. Returns true if the row
 * has an issue afterwards. Failures are recorded on the row, never thrown.
 */
function feedbackSyncToGithub(PDO $pdo, int $id, array $cfg, callable $http): bool {
    if (($cfg['token'] ?? '') === '' || ($cfg['repo'] ?? '') === '') {
        return false;
    }
    $row = db_get_feedback($pdo, $id);
    if (!$row) {
        return false;
    }
    if ($row['github_issue_number'] !== null) {
        return true;
    }

    $issue = feedbackBuildIssue($row, feedbackAdminUrl((string)($cfg['admin_base'] ?? ''), $id));
    try {
        $res = $http('https://api.github.com/repos/' . $cfg['repo'] . '/issues', $cfg['token'], [
            'title'  => $issue['title'],
            'body'   => $issue['body'],
            'labels' => $issue['labels'],
        ]);
        $data = json_decode((string)($res['body'] ?? ''), true);
        if (($res['status'] ?? 0) === 201 && is_array($data) && isset($data['number'], $data['html_url'])) {
            db_set_feedback_github($pdo, $id, (int)$data['number'], (string)$data['html_url']);
            return true;
        }
        db_set_feedback_github_error($pdo, $id, 'HTTP ' . ($res['status'] ?? 0) . ': ' . mb_substr((string)($res['body'] ?? ''), 0, 300));
    } catch (Throwable $e) {
        db_set_feedback_github_error($pdo, $id, mb_substr($e->getMessage(), 0, 300));
    }
    return false;
}

// ── Submission orchestrator ───────────────────────────────────────────────────

/** @return array{status: int, body: array} */
function feedbackHandleSubmission(PDO $pdo, array $in, array $ctx, callable $http, callable $notify): array {
    if (trim((string)($in['website'] ?? '')) !== '') {
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    [$clean, $errors] = feedbackValidate($in);
    if ($errors) {
        return ['status' => 422, 'body' => ['ok' => false, 'errors' => $errors]];
    }

    $ipHash = feedbackHashIp((string)($ctx['ip'] ?? ''));
    $now    = (int)($ctx['now'] ?? time());
    $since  = date('Y-m-d H:i:s', $now - (int)($ctx['rate_window'] ?? 3600));
    if (db_count_recent_feedback_by_ip_hash($pdo, $ipHash, $since) >= (int)($ctx['rate_limit'] ?? 5)) {
        return ['status' => 429, 'body' => ['ok' => false, 'errors' => ['You have sent a lot of feedback recently. Please try again later.']]];
    }

    $user = $ctx['user'] ?? null;
    if ($clean['email'] === null && !empty($user['email'])) {
        $clean['email'] = $user['email'];
    }

    try {
        $id = db_insert_feedback($pdo, $clean + [
            'name'       => $user['name'] ?? null,
            'user_id'    => $user['id'] ?? null,
            'user_agent' => mb_substr((string)($ctx['user_agent'] ?? ''), 0, 300),
            'ip_hash'    => $ipHash,
            'created_at' => date('Y-m-d H:i:s', $now),
        ]);
    } catch (Throwable $e) {
        error_log('Feedback insert failed: ' . $e->getMessage());
        return ['status' => 500, 'body' => ['ok' => false, 'errors' => ['Sorry, we could not save your feedback. Please try again.']]];
    }

    feedbackSyncToGithub($pdo, $id, $ctx['github'] ?? [], $http);

    $row = db_get_feedback($pdo, $id);
    try {
        $notify($row, $row['github_issue_url']);
    } catch (Throwable $e) {
        error_log('Feedback notification failed: ' . $e->getMessage());
    }

    return ['status' => 200, 'body' => ['ok' => true]];
}
