<?php
/**
 * admin/audit.php -- the audit trail (FR6).
 *
 * Filter by action, person or date, page through the results, and export the
 * filtered rows as CSV for evidence. The log is append-only: nothing here can
 * edit or delete a row.
 */
require_once __DIR__ . '/../includes/init.php';
$me = require_role(['admin']);

$action = clean_get('action', 40);
$nameq  = clean_get('user', 60);
$from   = clean_get('from', 10);
$to     = clean_get('to', 10);
$export = isset($_GET['export']);

$where = ['1 = 1'];
$args  = [];
if ($action !== '') {
    $where[] = 'l.action = ?';
    $args[]  = strtoupper($action);
}
if ($nameq !== '') {
    $where[] = 'u.full_name LIKE ?';
    $args[]  = '%' . $nameq . '%';
}
if (valid_date($from)) {
    $where[] = 'l.created_at >= ?';
    $args[]  = $from . ' 00:00:00';
}
if (valid_date($to)) {
    $where[] = 'l.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $args[]  = $to;
}
$w = implode(' AND ', $where);
$base = "FROM audit_logs l LEFT JOIN users u ON u.user_id = l.user_id WHERE $w";

// ---- CSV export ------------------------------------------------------
if ($export) {
    $st = $pdo->prepare("SELECT l.log_id, l.created_at, l.action, l.entity, l.entity_id, l.details, l.ip_address,
                                u.full_name, u.role $base ORDER BY l.log_id DESC LIMIT 5000");
    $st->execute($args);
    $logs = $st->fetchAll();
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

$st = $pdo->prepare("SELECT COUNT(*) $base");
$st->execute($args);
$found = (int)$st->fetchColumn();
$per   = 50;
$pages = max(1, (int)ceil($found / $per));
$page  = min(max(1, (int)($_GET['p'] ?? 1)), $pages);

$st = $pdo->prepare("SELECT l.log_id, l.created_at, l.action, l.entity, l.entity_id, l.details, l.ip_address,
                            u.full_name, u.role $base ORDER BY l.log_id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$logs = $st->fetchAll();

$actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$keep    = array_filter(['action' => $action, 'user' => $nameq, 'from' => $from, 'to' => $to]);

// Security-relevant actions stand out in the list.
$tone = static fn(string $a): string => match (true) {
    in_array($a, ['LOGIN_FAILED', 'LOGIN_BLOCKED'], true)                      => 'declined',
    in_array($a, ['RESET_PASSWORD', 'TOGGLE_ACTIVE', 'EXPORT', 'MOVE'], true) => 'pending',
    in_array($a, ['CREATE', 'REGISTER', 'BOOK', 'CONFIRM'], true)             => 'confirmed',
    default                                                                   => 'completed',
};

$page_title   = 'Audit log';
$page_sub     = number_format($found) . ' ' . ($keep ? 'matching entries' : 'entries on record') . ' · append-only';
$page_actions = '<a class="btn btn--ghost" href="?' . e(http_build_query($keep + ['export' => 'csv'])) . '">' . icon('download') . 'Export CSV</a>';
require __DIR__ . '/../includes/header.php';
?>
<section class="card">
  <form method="get" class="toolbar">
    <select name="action" aria-label="Action">
      <option value="">All actions</option>
<?php foreach ($actions as $a): ?>
      <option value="<?= e($a) ?>"<?= $action === $a ? ' selected' : '' ?>><?= e(ucfirst(strtolower(str_replace('_', ' ', $a)))) ?></option>
<?php endforeach; ?>
    </select>
    <div class="search grow"><?= icon('search') ?>
      <input name="user" value="<?= e($nameq) ?>" maxlength="60" placeholder="Person" aria-label="Person">
    </div>
    <input type="date" name="from" value="<?= e($from) ?>" aria-label="From date">
    <input type="date" name="to" value="<?= e($to) ?>" aria-label="To date">
    <button type="submit" class="btn--ghost">Filter</button>
<?php if ($keep): ?><a class="btn btn--link" href="<?= e(url('/admin/audit.php')) ?>">Clear</a><?php endif; ?>
  </form>

<?php if (!$logs): ?>
  <?= empty_state('No entries match these filters.', '', 'shield') ?>
<?php else: ?>
  <div class="scroll">
  <table>
    <thead><tr><th>Time</th><th>Person</th><th>Action</th><th class="hide-sm">Record</th><th>Details</th><th class="hide-sm">IP address</th></tr></thead>
    <tbody>
<?php foreach ($logs as $l): ?>
    <tr>
      <td class="nowrap"><?= e(fmt_date((string)$l['created_at'])) ?><small><?= e(substr((string)$l['created_at'], 11, 8)) ?></small></td>
      <td><?= $l['full_name'] ? e($l['full_name']) . '<small>' . e(role_label((string)$l['role'])) . '</small>' : '<span class="muted">Unknown</span>' ?></td>
      <td><span class="badge <?= $tone((string)$l['action']) ?>"><?= e(ucfirst(strtolower(str_replace('_', ' ', (string)$l['action'])))) ?></span></td>
      <td class="hide-sm"><?= e($l['entity']) ?><?= $l['entity_id'] !== null ? ' <span class="muted">#' . (int)$l['entity_id'] . '</span>' : '' ?></td>
      <td><div class="trunc" title="<?= e($l['details']) ?>"><?= e($l['details'] ?? '') ?></div></td>
      <td class="hide-sm mono"><?= e($l['ip_address']) ?></td>
    </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= pager($page, $pages, $keep) ?>
<?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
