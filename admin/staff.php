<?php
/**
 * admin/staff.php -- the staff directory (FR1).
 *
 * Doctors, health workers and administrators exist only because an
 * administrator created them here; nobody can register into a staff role
 * from a public page. Filter by role, centre and status; open a person to
 * edit them, reset their password or deactivate them.
 */
require_once __DIR__ . '/../includes/init.php';
$me = require_role(['admin']);

const STAFF_ROLES = ['doctor', 'health_worker', 'admin'];

$role   = clean_get('role', 20);
$centre = (int)clean_get('centre', 10);
$state  = clean_get('state', 10);
$q      = clean_get('q', 60);
if (!in_array($role, STAFF_ROLES, true)) {
    $role = '';
}
if (!in_array($state, ['active', 'inactive'], true)) {
    $state = '';
}

$facilities = $pdo->query('SELECT facility_id, name, is_active FROM facilities ORDER BY name')->fetchAll();

// Tab counts ignore the role filter but honour the others.
$where = ["u.role IN ('doctor','health_worker','admin')"];
$args  = [];
if ($centre > 0) {
    $where[] = 'u.facility_id = ?';
    $args[]  = $centre;
}
if ($state !== '') {
    $where[] = 'u.is_active = ?';
    $args[]  = $state === 'active' ? 1 : 0;
}
if ($q !== '') {
    $where[] = '(u.full_name LIKE ? OR u.phone LIKE ? OR u.email LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%");
}
$st = $pdo->prepare('SELECT u.role, COUNT(*) AS c FROM users u WHERE ' . implode(' AND ', $where) . ' GROUP BY u.role');
$st->execute($args);
$roleCounts = array_fill_keys(STAFF_ROLES, 0);
foreach ($st->fetchAll() as $r) {
    $roleCounts[$r['role']] = (int)$r['c'];
}

if ($role !== '') {
    $where[] = 'u.role = ?';
    $args[]  = $role;
}
$found = 0;
foreach (STAFF_ROLES as $r) {
    if ($role === '' || $role === $r) {
        $found += $roleCounts[$r];
    }
}
$per   = 25;
$pages = max(1, (int)ceil($found / $per));
$page  = min(max(1, (int)($_GET['p'] ?? 1)), $pages);

$st = $pdo->prepare(
    'SELECT u.user_id, u.full_name, u.phone, u.email, u.role, u.is_active, u.last_login_at, u.created_at,
            f.name AS facility
       FROM users u LEFT JOIN facilities f ON f.facility_id = u.facility_id
      WHERE ' . implode(' AND ', $where) . '
   ORDER BY u.is_active DESC, FIELD(u.role, \'doctor\', \'health_worker\', \'admin\'), u.full_name
      LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per)
);
$st->execute($args);
$rows = $st->fetchAll();

// Centres that cannot work: no active doctor answers, or no health worker books.
$gaps = $pdo->query(
    "SELECT f.facility_id, f.name,
            COALESCE(SUM(u.role = 'doctor' AND u.is_active = 1), 0)        AS doctors,
            COALESCE(SUM(u.role = 'health_worker' AND u.is_active = 1), 0) AS workers
       FROM facilities f LEFT JOIN users u ON u.facility_id = f.facility_id
      WHERE f.is_active = 1
   GROUP BY f.facility_id, f.name
     HAVING doctors = 0 OR workers = 0
   ORDER BY f.name"
)->fetchAll();

$keep = array_filter(['role' => $role, 'centre' => $centre ?: '', 'state' => $state, 'q' => $q]);

$page_title   = 'Staff';
$activeStaff  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('doctor','health_worker','admin') AND is_active = 1")->fetchColumn();
$page_sub     = $activeStaff . ' active ' . ($activeStaff === 1 ? 'account' : 'accounts') . ' across ' . count(array_filter($facilities, static fn($f) => (int)$f['is_active'])) . ' health centres';
$page_actions = '<a class="btn" href="' . e(url('/admin/staff_new.php')) . '">' . icon('userplus') . 'Add staff member</a>';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($gaps):
    $parts = [];
    foreach ($gaps as $g) {
        $missing = [];
        foreach (['doctor' => 'doctors', 'health_worker' => 'workers'] as $roleKey => $col) {
            if ((int)$g[$col] === 0) {
                $missing[] = '<a href="' . e(url('/admin/staff_new.php?centre=' . (int)$g['facility_id'] . '&role=' . $roleKey)) . '">'
                           . e(strtolower(role_label($roleKey))) . '</a>';
            }
        }
        $parts[] = '<b>' . e($g['name']) . '</b> has no active ' . implode(' or ', $missing);
    } ?>
<div class="warnline"><?= icon('alert') ?><span><?= implode('; ', $parts) ?>. Requests there cannot be sent or answered until one is added.</span></div>
<?php endif; ?>

<section class="card">
  <?= tabs([
        ''              => ['All staff', array_sum($roleCounts)],
        'doctor'        => ['Doctors', $roleCounts['doctor']],
        'health_worker' => ['Health workers', $roleCounts['health_worker']],
        'admin'         => ['Administrators', $roleCounts['admin']],
      ], $role, 'role', array_filter(['centre' => $centre ?: '', 'state' => $state, 'q' => $q])) ?>

  <form method="get" class="toolbar">
<?php if ($role !== ''): ?><input type="hidden" name="role" value="<?= e($role) ?>"><?php endif; ?>
    <div class="search grow"><?= icon('search') ?>
      <input name="q" value="<?= e($q) ?>" placeholder="Search name, phone or email" aria-label="Search staff" maxlength="60">
    </div>
    <select name="centre" aria-label="Health centre">
      <option value="">All health centres</option>
<?php foreach ($facilities as $f): ?>
      <option value="<?= (int)$f['facility_id'] ?>"<?= $centre === (int)$f['facility_id'] ? ' selected' : '' ?>><?= e($f['name']) ?></option>
<?php endforeach; ?>
    </select>
    <select name="state" aria-label="Status">
      <option value="">Any status</option>
      <option value="active"<?= $state === 'active' ? ' selected' : '' ?>>Active</option>
      <option value="inactive"<?= $state === 'inactive' ? ' selected' : '' ?>>Inactive</option>
    </select>
    <button type="submit" class="btn--ghost">Filter</button>
<?php if ($q !== '' || $centre || $state !== ''): ?>
    <a class="btn btn--link" href="<?= e(url('/admin/staff.php' . ($role !== '' ? '?role=' . $role : ''))) ?>">Clear</a>
<?php endif; ?>
  </form>

<?php if (!$rows): ?>
  <?= empty_state($q !== '' || $centre || $state !== '' ? 'No staff match these filters.' : 'No staff registered yet.',
        '<a class="btn btn--sm" href="' . e(url('/admin/staff_new.php')) . '">' . icon('userplus') . 'Add staff member</a>', 'users') ?>
<?php else: ?>
  <div class="scroll">
  <table>
    <thead><tr><th>Name</th><th>Role</th><th>Health centre</th><th class="hide-sm">Phone</th><th class="hide-sm">Last sign-in</th><th>Status</th><th class="act"></th></tr></thead>
    <tbody>
<?php foreach ($rows as $u): ?>
    <tr<?= (int)$u['is_active'] ? '' : ' class="is-off"' ?>>
      <td><div class="who"><?= avatar($u['full_name']) ?><div>
        <a class="rowlink" href="<?= e(url('/admin/staff_edit.php?id=' . (int)$u['user_id'])) ?>"><?= e($u['full_name']) ?></a>
        <?= (int)$u['user_id'] === (int)$me['user_id'] ? ' <span class="tag">You</span>' : '' ?>
        <small><?= e($u['email'] ?: 'No email') ?></small></div></div></td>
      <td><?= role_tag($u['role']) ?></td>
      <td><?= $u['facility'] ? e($u['facility']) : '<span class="muted">District office</span>' ?></td>
      <td class="hide-sm mono"><?= e($u['phone']) ?></td>
      <td class="hide-sm"><?= $u['last_login_at'] ? e(fmt_datetime($u['last_login_at'])) : '<span class="muted">Never</span>' ?></td>
      <td><?= state_pill((bool)$u['is_active']) ?></td>
      <td class="act"><a class="btn btn--ghost btn--sm" href="<?= e(url('/admin/staff_edit.php?id=' . (int)$u['user_id'])) ?>">Manage</a></td>
    </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= pager($page, $pages, $keep) ?>
<?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
