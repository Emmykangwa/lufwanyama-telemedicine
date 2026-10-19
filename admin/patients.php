<?php
/**
 * admin/patients.php -- the district patient register.
 *
 * Read-only for clinical content. The administrator can move a patient to
 * another health centre (health workers may not, so a patient cannot be
 * pulled between clinics without oversight) and manage a patient's login.
 */
require_once __DIR__ . '/../includes/init.php';
$me = require_role(['admin']);

$facilities = $pdo->query('SELECT facility_id, name, is_active FROM facilities ORDER BY name')->fetchAll();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $pid = (int)($_POST['patient_id'] ?? 0);
    $fac = (int)($_POST['facility_id'] ?? 0);
    $st  = $pdo->prepare('SELECT full_name FROM patients WHERE patient_id = ?');
    $st->execute([$pid]);
    $name = $st->fetchColumn();
    $ok = $fac === 0;
    foreach ($facilities as $f) {
        $ok = $ok || (int)$f['facility_id'] === $fac;
    }
    if ($name === false || !$ok) {
        flash('err', 'Choose a patient and a health centre.');
    } else {
        $pdo->prepare('UPDATE patients SET facility_id = ? WHERE patient_id = ?')->execute([$fac ?: null, $pid]);
        audit($pdo, 'MOVE', 'patient', $pid, $fac ? 'To facility ' . $fac : 'Detached from facility');
        flash('ok', $name . ($fac ? ' moved.' : ' is no longer on a centre register.'));
    }
    redirect('/admin/patients.php?' . http_build_query(array_filter(['q' => $_POST['q'] ?? '', 'centre' => $_POST['centre'] ?? ''])));
}

$q      = clean_get('q', 60);
$centre = clean_get('centre', 10);   // '' all, 'none' unattached, or an id
$move   = (int)($_GET['move'] ?? 0);

$where = ['1 = 1'];
$args  = [];
if ($centre === 'none') {
    $where[] = 'p.facility_id IS NULL';
} elseif ((int)$centre > 0) {
    $where[] = 'p.facility_id = ?';
    $args[]  = (int)$centre;
}
if ($q !== '') {
    $where[] = '(p.full_name LIKE ? OR p.patient_number LIKE ? OR p.phone LIKE ? OR p.village LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%", "%$q%");
}
$w = implode(' AND ', $where);

$st = $pdo->prepare("SELECT COUNT(*) FROM patients p WHERE $w");
$st->execute($args);
$found = (int)$st->fetchColumn();
$per   = 25;
$pages = max(1, (int)ceil($found / $per));
$page  = min(max(1, (int)($_GET['p'] ?? 1)), $pages);

$st = $pdo->prepare(
    "SELECT p.patient_id, p.patient_number, p.full_name, p.sex, p.dob, p.phone, p.village, p.user_id,
            f.name AS centre, u.is_active AS login_active,
            (SELECT COUNT(*) FROM appointments a WHERE a.patient_id = p.patient_id) AS visits,
            (SELECT MAX(a.appointment_date) FROM appointments a WHERE a.patient_id = p.patient_id) AS last_visit
       FROM patients p
  LEFT JOIN facilities f ON f.facility_id = p.facility_id
  LEFT JOIN users u      ON u.user_id = p.user_id
      WHERE $w
   ORDER BY p.full_name
      LIMIT $per OFFSET " . (($page - 1) * $per)
);
$st->execute($args);
$rows = $st->fetchAll();

$unattached = (int)$pdo->query('SELECT COUNT(*) FROM patients WHERE facility_id IS NULL')->fetchColumn();

$moving = null;
if ($move) {
    $st = $pdo->prepare('SELECT patient_id, full_name, patient_number, facility_id FROM patients WHERE patient_id = ?');
    $st->execute([$move]);
    $moving = $st->fetch() ?: null;
}

$keep = array_filter(['q' => $q, 'centre' => $centre]);
$page_title = 'Patients';
$page_sub   = $found . ' on record' . ($unattached ? ' · <a href="?centre=none">' . $unattached . ' not attached to a centre</a>' : '');
require __DIR__ . '/../includes/header.php';
?>
<div class="grid<?= $moving ? ' grid-main' : '' ?>">
<section class="card">
  <form method="get" class="toolbar">
    <div class="search grow"><?= icon('search') ?>
      <input name="q" value="<?= e($q) ?>" placeholder="Search name, patient number, phone or village" aria-label="Search patients" maxlength="60">
    </div>
    <select name="centre" aria-label="Health centre">
      <option value="">All health centres</option>
      <option value="none"<?= $centre === 'none' ? ' selected' : '' ?>>Not attached</option>
<?php foreach ($facilities as $f): ?>
      <option value="<?= (int)$f['facility_id'] ?>"<?= $centre === (string)$f['facility_id'] ? ' selected' : '' ?>><?= e($f['name']) ?></option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="btn--ghost">Filter</button>
<?php if ($keep): ?><a class="btn btn--link" href="<?= e(url('/admin/patients.php')) ?>">Clear</a><?php endif; ?>
  </form>

<?php if (!$rows): ?>
  <?= empty_state($keep ? 'No patients match these filters.' : 'No patients registered yet.', '', 'users') ?>
<?php else: ?>
  <div class="scroll">
  <table>
    <thead><tr><th>Patient</th><th class="hide-sm">Sex / age</th><th class="hide-sm">Village</th><th>Health centre</th><th class="num">Visits</th><th class="hide-sm">Login</th><th class="act"></th></tr></thead>
    <tbody>
<?php foreach ($rows as $r): $age = age_from($r['dob']); ?>
    <tr>
      <td><b class="rowlink"><?= e($r['full_name']) ?></b><small class="mono"><?= e($r['patient_number']) ?><?= $r['phone'] ? ' · ' . e($r['phone']) : '' ?></small></td>
      <td class="hide-sm"><?= e($r['sex'] ?? '—') ?><?= $age !== null ? ' · ' . $age . ' y' : '' ?></td>
      <td class="hide-sm"><?= e($r['village'] ?: '—') ?></td>
      <td><?= $r['centre'] ? e($r['centre']) : '<span class="badge pending">Not attached</span>' ?></td>
      <td class="num"><?= (int)$r['visits'] ?><small><?= $r['last_visit'] ? e(fmt_date($r['last_visit'], false)) : '' ?></small></td>
      <td class="hide-sm"><?= $r['user_id']
            ? '<a href="' . e(url('/admin/staff_edit.php?id=' . (int)$r['user_id'])) . '">' . ((int)$r['login_active'] ? 'Manage' : 'Inactive') . '</a>'
            : '<span class="muted">None</span>' ?></td>
      <td class="act"><a class="btn btn--ghost btn--sm" href="?<?= e(http_build_query($keep + ['move' => (int)$r['patient_id'], 'p' => $page])) ?>">Move</a></td>
    </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= pager($page, $pages, $keep) ?>
<?php endif; ?>
</section>

<?php if ($moving): ?>
<aside>
  <form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="patient_id" value="<?= (int)$moving['patient_id'] ?>">
    <input type="hidden" name="q" value="<?= e($q) ?>">
    <input type="hidden" name="centre" value="<?= e($centre) ?>">
    <header class="card__h"><h2>Move patient</h2><a class="btn btn--link" href="?<?= e(http_build_query($keep)) ?>">Cancel</a></header>
    <div class="card__b">
      <p><b><?= e($moving['full_name']) ?></b> <span class="mono muted"><?= e($moving['patient_number']) ?></span></p>
      <div class="field">
        <label for="facility_id">Register at</label>
        <select id="facility_id" name="facility_id">
          <option value="0">Not attached to a centre</option>
<?php foreach ($facilities as $f): if (!(int)$f['is_active']) { continue; } ?>
          <option value="<?= (int)$f['facility_id'] ?>"<?= (int)$f['facility_id'] === (int)$moving['facility_id'] ? ' selected' : '' ?>><?= e($f['name']) ?></option>
<?php endforeach; ?>
        </select>
      </div>
      <p class="hint">Existing appointments stay with the centre that made them.</p>
    </div>
    <div class="card__f"><button type="submit">Move patient</button></div>
  </form>
</aside>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
