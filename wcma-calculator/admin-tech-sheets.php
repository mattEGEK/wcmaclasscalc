<?php
// wcma-calculator/admin-tech-sheets.php
//
// Admin "Tech Sheets" tab: event roster (who still needs tech at the track) and the
// in-person review page. Included by admin.php, which provides requireAuth(), the router
// and the CSRF/POST checks.

const TECH_SHEET_FILTERS = [
    'all' => 'All sheets',
    'needs_tech' => 'Needs tech at the track',
    'accepted' => 'Accepted',
];

const ADMIN_TECH_NAV = '<a href="admin.php">Submissions</a> <a href="admin.php?action=users">Manage Users</a> <a href="admin.php?action=events">Events</a> <a href="admin.php?action=settings">Settings</a> <a href="admin.php?action=feedback">Feedback</a>';

function handleTechSheetsList(PDO $pdo): void {
    $events = db_get_all_events($pdo);
    $eventId = isset($_GET['event']) ? max(0, (int)$_GET['event']) : techDefaultEventId($events, date('Y-m-d'));
    $filter = (string)($_GET['filter'] ?? 'all');
    if (!isset(TECH_SHEET_FILTERS[$filter])) $filter = 'all';

    $eventSheets = db_get_event_tech_sheets($pdo, $eventId);
    $seasonSheets = [];
    foreach (array_unique(array_map(fn(array $s): int => (int)$s['season'], $eventSheets)) as $season) {
        $seasonSheets = array_merge($seasonSheets, db_get_season_sheets($pdo, $season));
    }
    $rows = techBuildRoster($eventSheets, $seasonSheets);

    $counts = [
        'all' => count($rows),
        'needs_tech' => count(techRosterFilter($rows, 'needs_tech')),
        'accepted' => count(techRosterFilter($rows, 'accepted')),
    ];
    renderTechSheetsListPage($events, $eventId, $filter, techRosterFilter($rows, $filter), $counts, getFlash());
}

function renderTechSheetsListPage(array $events, int $eventId, string $filter, array $rows, array $counts, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tech Sheets — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Tech Sheets', ADMIN_TECH_NAV . renderCommonNav('admin')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <form method="get" action="admin.php" class="detail-card" style="margin-bottom:1rem">
    <input type="hidden" name="action" value="tech-sheets">
    <label for="tech-event">Event</label>
    <select id="tech-event" name="event">
      <option value="0"<?= $eventId === 0 ? ' selected' : '' ?>>All events</option>
      <?php foreach ($events as $e): ?>
      <option value="<?= (int)$e['id'] ?>"<?= (int)$e['id'] === $eventId ? ' selected' : '' ?>><?= h($e['name']) ?> (<?= h(date('M j, Y', strtotime($e['event_date']))) ?>)</option>
      <?php endforeach; ?>
    </select>
    <label for="tech-filter">Show</label>
    <select id="tech-filter" name="filter">
      <?php foreach (TECH_SHEET_FILTERS as $value => $label): ?>
      <option value="<?= h($value) ?>"<?= $value === $filter ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Apply</button>
    <p class="form-hint" style="margin-top:.5rem"><?= (int)$counts['all'] ?> sheets: <?= (int)$counts['accepted'] ?> accepted, <?= (int)$counts['needs_tech'] ?> still need tech at the track.</p>
  </form>

  <table class="data-table" id="tech-sheets-table">
    <thead><tr><th>Car #</th><th>Vehicle</th><th>Entrant</th><th>Class</th><th>Event</th><th>Car status</th><th>Sheet</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="8" class="empty-row">No tech sheets match.</td></tr>
    <?php else: foreach ($rows as $row): $s = $row['sheet']; $st = $row['status']; ?>
      <tr>
        <td><?= h($s['car_number']) ?></td>
        <td><?= h(trim($s['car_make'] . ' ' . $s['car_model'])) ?></td>
        <td><?= h($s['entrant_name']) ?></td>
        <td><?= h($s['class']) ?></td>
        <td><?= h($s['event_name'] ?? '—') ?></td>
        <td class="<?= h(techCarStatusBadgeClass($st['state'])) ?>"><?= h(techCarStatusLabel($st, (int)$s['season'])) ?></td>
        <td><?= $s['status'] === 'teched' ? 'Reviewed' : 'Submitted ' . h(date('M j', strtotime($s['created_at']))) ?></td>
        <td class="actions"><a href="admin.php?action=tech-sheet&id=<?= (int)$s['id'] ?>"><?= $s['status'] === 'teched' ? 'View' : 'Review' ?></a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
</body>
</html><?php
}
