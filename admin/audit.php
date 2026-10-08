<?php
/**
 * admin/audit.php -- the audit trail (FR6).
 *
 * Filter by action, by user or by date, and export the filtered rows as CSV
 * for evidence. The log is append-only: nothing here can edit or delete a row.
 */
require_once __DIR__ . '/../includes/init.php';
$me = require_role(['admin']);

$action = clean_get('action', 40);
$nameq  = clean_get('user', 60);
$from   = clean_get('from', 10);
$to     = clean_get('to', 10);
$export = isset($_GET['export']);

$sql = 'SELECT l.log_id, l.created_at, l.action, l.entity, l.entity_id, l.details, l.ip_address,
               u.full_name, u.role
          FROM audit_logs l LEFT JOIN users u ON u.user_id = l.user_id
         WHERE 1 = 1';
$args = [];

if ($action !== '') {
    $sql   .= ' AND l.action = ?';
    $args[] = strtoupper($action);
}
if ($nameq !== '') {
    // utf8mb4_unicode_ci makes LIKE case-insensitive already.
    $sql   .= ' AND u.full_name LIKE ?';
    $args[] = '%' . $nameq . '%';
}
if (valid_date($from)) {
    $sql   .= ' AND l.created_at >= ?';
    $args[] = $from . ' 00:00:00';
}
if (valid_date($to)) {
    $sql   .= ' AND l.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $args[] = $to;
}
$sql .= ' ORDER BY l.log_id DESC LIMIT ' . ($export ? '5000' : '200');

$st = $pdo->prepare($sql);
$st->execute($args);
$logs = $st->fetchAll();

// ---- CSV export ------------------------------------------------------
if ($export) {
    audit($pdo, 'EXPORT', 'audit_log', null, count($logs) . ' rows');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    // The explicit $escape argument keeps PHP 8.4+ from raising a deprecation
    // notice, which would otherwise be written into the middle of the download.
    $row = static function (array $fields) use ($out): void {
        fputcsv($out, $fields, ',', '"', '');
    };
    $row(['log_id', 'time', 'user', 'role', 'action', 'entity', 'entity_id', 'details', 'ip']);
    foreach ($logs as $l) {
        $row([
            $l['log_id'], $l['created_at'], $l['full_name'] ?? 'unknown', $l['role'] ?? '',
            $l['action'], $l['entity'], $l['entity_id'], $l['details'], $l['ip_address'],
        ]);
    }
    fclose($out);
    exit;
}

$actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$total   = (int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();

$page_title = t('audit');
require __DIR__ . '/../includes/header.php';
?>
<p class="hint"><?= $total ?> entries on record. Showing the newest <?= count($logs) ?> that match the filter.</p>

<form method="get" class="card noprint row">
  <div>
    <label for="action"><?= e(t('action')) ?></label>
    <select id="action" name="action">
      <option value="">All</option>
<?php foreach ($actions as $a): ?>
      <option value="<?= e($a) ?>"<?= $action === $a ? ' selected' : '' ?>><?= e($a) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="user">User name</label>
    <input id="user" name="user" value="<?= e($nameq) ?>" maxlength="60">
  </div>
  <div>
    <label for="from"><?= e(t('from')) ?></label>
    <input id="from" name="from" type="date" value="<?= e($from) ?>">
  </div>
  <div>
    <label for="to"><?= e(t('to')) ?></label>
    <input id="to" name="to" type="date" value="<?= e($to) ?>">
  </div>
  <div><button type="submit"><?= icon('search') ?><?= e(t('apply')) ?></button></div>
  <div><button type="submit" name="export" value="csv" class="alt"><?= icon('download') ?>CSV</button></div>
</form>

<div class="scroll">
<table>
  <tr><th>Time</th><th>User</th><th><?= e(t('action')) ?></th><th>Record</th><th>Details</th><th>IP</th></tr>
<?php if (!$logs): ?>
  <tr><td colspan="6"><?= e(t('no_records')) ?></td></tr>
<?php endif; ?>
<?php foreach ($logs as $l): ?>
  <tr>
    <td><?= e(substr((string)$l['created_at'], 0, 19)) ?></td>
    <td><?= e($l['full_name'] ?? 'unknown') ?><br><small><?= e(str_replace('_', ' ', (string)($l['role'] ?? ''))) ?></small></td>
    <td><?= e($l['action']) ?></td>
    <td><?= e($l['entity']) ?><?= $l['entity_id'] !== null ? ' #' . (int)$l['entity_id'] : '' ?></td>
    <td><?= e($l['details']) ?></td>
    <td><?= e($l['ip_address']) ?></td>
  </tr>
<?php endforeach; ?>
</table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
