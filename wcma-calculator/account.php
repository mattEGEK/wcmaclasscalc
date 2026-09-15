<?php
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';

date_default_timezone_set('America/Denver');

$pdo = db_connect();
db_init($pdo);

const SUBMISSION_SOFT_CAP = 20;

function requireLogin(): array {
    $user = current_user();
    if ($user === null) {
        header('Location: auth.php?action=login');
        exit;
    }
    return $user;
}

function generateCsrfToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function setFlash(string $message, string $type): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$user = requireLogin();
$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':
    default:
        handleAccountList($pdo, $user);
}

function handleAccountList(PDO $pdo, array $user): void {
    $submissions = db_get_user_submissions($pdo, $user['id']);
    $count = db_count_user_submissions($pdo, $user['id']);
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderAccountListPage($submissions, $count, $csrf, $flash);
}

function renderAccountListPage(array $submissions, int $count, string $csrf, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Submissions — WCMA Calculator</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; margin: 0; background: #f0f2f5; }
  header { background: #1a5490; color: #fff; padding: .8rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
  header h1 { margin: 0; font-size: 1.2rem; }
  header a { color: #cde; font-size: .9rem; }
  main { padding: 1.5rem; }
  .flash, .banner { padding: .7rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: .9rem; }
  .flash.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
  .flash.error   { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
  .banner { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
  th { background: #1a5490; color: #fff; padding: .7rem 1rem; text-align: left; font-size: .85rem; }
  td { padding: .65rem 1rem; border-bottom: 1px solid #eee; font-size: .9rem; }
  tr:last-child td { border-bottom: none; }
  .actions a { color: #1a5490; }
  .empty { text-align: center; color: #888; padding: 2rem; }
</style>
</head>
<body>
<header>
  <h1>My Submissions</h1>
  <a href="car-classing.html">← Back to calculator</a>
</header>
<main>
  <?php if ($flash): ?>
  <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <?php if ($count > SUBMISSION_SOFT_CAP): ?>
  <div class="banner">You have <?= (int)$count ?> saved submissions — consider deleting some older ones.</div>
  <?php endif; ?>
  <table>
    <thead>
      <tr><th>Submitted</th><th>Vehicle</th><th>Class</th><th>Email</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php if (empty($submissions)): ?>
      <tr><td colspan="5" class="empty">No submissions yet.</td></tr>
    <?php else: foreach ($submissions as $s): ?>
      <tr>
        <td><?= h(date('M j, Y H:i', strtotime($s['submitted_at']))) ?></td>
        <td><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></td>
        <td><strong><?= h($s['calculated_class'] ?? '—') ?></strong></td>
        <td><?= $s['email_sent'] ? '✓' : '⚠ Failed' ?></td>
        <td class="actions">
          <a href="account.php?action=view&id=<?= (int)$s['id'] ?>">View</a>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</main>
</body>
</html><?php
}
