<?php
/**
 * Admin "Feedback" tab: list, detail view, status changes, GitHub retry.
 * Included by admin.php, which provides requireAuth() and the routing.
 */

const FEEDBACK_ADMIN_NAV = '<a href="admin.php">Submissions</a> <a href="admin.php?action=users">Manage Users</a> <a href="admin.php?action=events">Events</a> <a href="admin.php?action=settings">Settings</a>';

function handleFeedbackList(PDO $pdo): void {
    renderFeedbackListPage(db_get_all_feedback($pdo), getFlash());
}

function handleFeedbackView(PDO $pdo, int $id): void {
    $row = db_get_feedback($pdo, $id);
    if (!$row) {
        setFlash('Feedback not found.', 'error');
        header('Location: admin.php?action=feedback');
        exit;
    }
    $cfg = feedbackGithubConfig(feedbackBaseUrl($_SERVER));
    renderFeedbackViewPage($row, generateCsrfToken(), getFlash(), $cfg['token'] !== '');
}

function handleFeedbackStatus(PDO $pdo, int $id): void {
    $status = (string)($_POST['status'] ?? '');
    if (!db_get_feedback($pdo, $id) || !in_array($status, FEEDBACK_STATUSES, true)) {
        setFlash('Invalid request.', 'error');
        header('Location: admin.php?action=feedback');
        exit;
    }
    db_update_feedback_status($pdo, $id, $status);
    setFlash('Status updated.', 'success');
    header('Location: admin.php?action=feedback-view&id=' . $id);
    exit;
}

function handleFeedbackRetry(PDO $pdo, int $id): void {
    $cfg = feedbackGithubConfig(feedbackBaseUrl($_SERVER));
    if (!db_get_feedback($pdo, $id)) {
        setFlash('Feedback not found.', 'error');
        header('Location: admin.php?action=feedback');
        exit;
    }
    if ($cfg['token'] === '') {
        setFlash('GitHub sync is not configured (GITHUB_TOKEN is empty in config.php).', 'error');
    } elseif (feedbackSyncToGithub($pdo, $id, $cfg, 'feedbackGithubHttp')) {
        setFlash('Synced to GitHub.', 'success');
    } else {
        setFlash('GitHub sync failed — see the error below.', 'error');
    }
    header('Location: admin.php?action=feedback-view&id=' . $id);
    exit;
}

function feedbackGithubCell(array $f): string {
    if ($f['github_issue_number'] !== null) {
        return '<td><a href="' . h((string)$f['github_issue_url']) . '" target="_blank" rel="noopener">#' . (int)$f['github_issue_number'] . '</a></td>';
    }
    if ($f['github_error'] !== null) {
        return '<td class="badge-fail" title="' . h((string)$f['github_error']) . '">Not synced</td>';
    }
    return '<td>—</td>';
}

function renderFeedbackListPage(array $rows, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Feedback — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Feedback', FEEDBACK_ADMIN_NAV . renderCommonNav('admin')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <p class="list-summary"><?= count($rows) ?> item<?= count($rows) === 1 ? '' : 's' ?></p>
  <table class="data-table">
    <thead>
      <tr><th>Received</th><th>Type</th><th>Message</th><th>From</th><th>Status</th><th>GitHub</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="7" class="empty">No feedback yet.</td></tr>
    <?php else: foreach ($rows as $f): ?>
      <tr>
        <td><?= h(date('M j, Y H:i', strtotime($f['created_at']))) ?></td>
        <td><?= h(feedbackTypeLabel($f['type'])) ?></td>
        <td><?= h(mb_strimwidth((string)preg_replace('/\s+/u', ' ', $f['message']), 0, 80, '…')) ?></td>
        <td><?= h(feedbackReporterText($f)) ?></td>
        <td><?= h($f['status']) ?></td>
        <?= feedbackGithubCell($f) ?>
        <td class="actions"><a href="admin.php?action=feedback-view&id=<?= (int)$f['id'] ?>">View</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<script src="js/form-feedback.js"></script>
</body>
</html><?php
}

function renderFeedbackViewPage(array $f, string $csrf, ?array $flash, bool $githubEnabled): void {
    $needsSync = $f['github_issue_number'] === null;
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Feedback #<?= (int)$f['id'] ?> — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Feedback #' . (int)$f['id'], '<a href="admin.php?action=feedback">← Back to list</a>' . renderCommonNav('admin')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <div class="detail-card" style="margin-bottom:1.5rem">
    <h2><?= h(feedbackTypeLabel($f['type'])) ?> — <?= h(date('M j, Y H:i', strtotime($f['created_at']))) ?></h2>
    <p><?= nl2br(h($f['message'])) ?></p>
    <table class="detail-table">
      <tr><td>From</td><td><?= h(feedbackReporterText($f)) ?></td></tr>
      <tr><td>Page</td><td><?= h((string)$f['page_url']) ?></td></tr>
      <tr><td>Browser</td><td><?= h((string)$f['user_agent']) ?></td></tr>
      <tr><td>Viewport</td><td><?= h((string)$f['viewport']) ?></td></tr>
      <tr><td>GitHub</td><td>
        <?php if ($f['github_issue_number'] !== null): ?>
          <a href="<?= h((string)$f['github_issue_url']) ?>" target="_blank" rel="noopener">Issue #<?= (int)$f['github_issue_number'] ?></a>
        <?php elseif ($f['github_error'] !== null): ?>
          <span class="badge-fail">Not synced</span> <?= h($f['github_error']) ?>
        <?php else: ?>
          Not sent<?= $githubEnabled ? '' : ' (GitHub sync is not configured)' ?>
        <?php endif; ?>
      </td></tr>
    </table>
    <?php if (!empty($f['calc_inputs'])): ?>
      <h3>Calculator inputs</h3>
      <pre><?= h((string)json_encode(json_decode($f['calc_inputs'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    <?php endif; ?>
  </div>

  <div class="detail-card" style="margin-bottom:1.5rem">
    <h2>Status</h2>
    <form method="post" action="admin.php?action=feedback-status" class="edit-form">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
      <label for="feedback-status">Status</label>
      <select id="feedback-status" name="status">
        <?php foreach (FEEDBACK_STATUSES as $s): ?>
        <option value="<?= h($s) ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= h($s) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-actions"><button type="submit" class="btn btn-primary">Save status</button></div>
    </form>
    <?php if ($needsSync && $githubEnabled): ?>
    <form method="post" action="admin.php?action=feedback-retry" style="margin-top:1rem">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
      <button type="submit" class="btn btn-secondary" data-loading-text="Syncing…">Retry GitHub sync</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<script src="js/form-feedback.js"></script>
</body>
</html><?php
}
