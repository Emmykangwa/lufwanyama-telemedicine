<?php
/**
 * admin/centres.php -- the district's health centres.
 *
 * Add, rename and (de)activate centres. Each row shows whether the centre can
 * actually work: a centre with no active doctor cannot answer a request and
 * one with no health worker cannot raise one, so those gaps are flagged with
 * a one-click way to fill them.
 */
require_once __DIR__ . '/../includes/init.php';
$me = require_role(['admin']);

$editId = (int)($_GET['edit'] ?? 0);
$err  = '';
$form = ['name' => '', 'location' => '', 'is_active' => 1];

if ($editId) {
    $st = $pdo->prepare('SELECT * FROM facilities WHERE facility_id = ?');
    $st->execute([$editId]);
    $row = $st->fetch();
    if (!$row) {
        redirect('/admin/centres.php');
    }
    $form = ['name' => $row['name'], 'location' => (string)$row['location'], 'is_active' => (int)$row['is_active']];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $fid = (int)($_POST['facility_id'] ?? 0);
    $form['name']      = clean('name', 120);
    $form['location']  = clean('location', 120);
    $form['is_active'] = $fid ? (isset($_POST['is_active']) ? 1 : 0) : 1;

    if (mb_strlen($form['name']) < 3) {
        $err = 'Give the health centre a name.';
    } else {
        try {
            if ($fid) {
                $pdo->prepare('UPDATE facilities SET name = ?, location = ?, is_active = ? WHERE facility_id = ?')
                    ->execute([$form['name'], $form['location'] ?: null, $form['is_active'], $fid]);
                audit($pdo, 'UPDATE', 'facility', $fid, $form['name'] . ($form['is_active'] ? '' : ' (inactive)'));
                flash('ok', $form['name'] . ' updated.');
            } else {
                $pdo->prepare('INSERT INTO facilities (name, location) VALUES (?, ?)')
                    ->execute([$form['name'], $form['location'] ?: null]);
                $fid = (int)$pdo->lastInsertId();
                audit($pdo, 'CREATE', 'facility', $fid, $form['name']);
                flash('ok', $form['name'] . ' added. Next, register its doctors and health workers.');
            }
            redirect('/admin/centres.php');
        } catch (PDOException $ex) {
            $err = $ex->getCode() === SQLSTATE_UNIQUE_VIOLATION
                ? 'A health centre with that name already exists.'
                : 'Could not save the health centre.';
        }
    }
    $editId = $fid;
}

$centres = $pdo->query(
    "SELECT f.facility_id, f.name, f.location, f.is_active,
            (SELECT COUNT(*) FROM users u WHERE u.facility_id = f.facility_id AND u.role = 'doctor' AND u.is_active = 1)        AS doctors,
            (SELECT COUNT(*) FROM users u WHERE u.facility_id = f.facility_id AND u.role = 'health_worker' AND u.is_active = 1) AS workers,
            (SELECT COUNT(*) FROM patients p WHERE p.facility_id = f.facility_id) AS patients,
            (SELECT COUNT(*) FROM appointments a WHERE a.facility_id = f.facility_id AND a.status = 'pending') AS pending
       FROM facilities f ORDER BY f.is_active DESC, f.name"
)->fetchAll();

$page_title = 'Health centres';
$page_sub   = count(array_filter($centres, static fn($c) => (int)$c['is_active'])) . ' active of ' . count($centres);
require __DIR__ . '/../includes/header.php';
?>
<div class="grid grid-main">
<section class="card">
<?php if (!$centres): ?>
  <?= empty_state('No health centres yet. Add the first one to start registering staff.', '', 'building') ?>
<?php else: ?>
  <div class="scroll">
  <table>
    <thead><tr><th>Centre</th><th class="num">Doctors</th><th class="num">Health workers</th><th class="num hide-sm">Patients</th><th class="num hide-sm">Pending</th><th>Status</th><th class="act"></th></tr></thead>
    <tbody>
<?php foreach ($centres as $c): $fid = (int)$c['facility_id']; ?>
    <tr<?= (int)$c['is_active'] ? '' : ' class="is-off"' ?>>
      <td><b class="rowlink"><?= e($c['name']) ?></b><small><?= e($c['location'] ?: '—') ?></small></td>
      <td class="num"><?= (int)$c['doctors'] ?: '<a class="badge declined" href="' . e(url('/admin/staff_new.php?role=doctor&centre=' . $fid)) . '" title="Add a doctor">None · add</a>' ?></td>
      <td class="num"><?= (int)$c['workers'] ?: '<a class="badge declined" href="' . e(url('/admin/staff_new.php?role=health_worker&centre=' . $fid)) . '" title="Add a health worker">None · add</a>' ?></td>
      <td class="num hide-sm"><a href="<?= e(url('/admin/patients.php?centre=' . $fid)) ?>"><?= (int)$c['patients'] ?></a></td>
      <td class="num hide-sm"><?= (int)$c['pending'] ? '<span class="badge pending">' . (int)$c['pending'] . '</span>' : '0' ?></td>
      <td><?= state_pill((bool)$c['is_active']) ?></td>
      <td class="act">
        <a class="btn btn--ghost btn--sm" href="<?= e(url('/admin/staff.php?centre=' . $fid)) ?>">Staff</a>
        <a class="btn btn--ghost btn--sm" href="?edit=<?= $fid ?>">Edit</a>
      </td>
    </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<aside>
  <form method="post" class="card" id="centre-form">
    <?= csrf_field() ?>
    <input type="hidden" name="facility_id" value="<?= (int)$editId ?>">
    <header class="card__h"><h2><?= $editId ? 'Edit health centre' : 'Add a health centre' ?></h2>
      <?= $editId ? '<a class="btn btn--link" href="' . e(url('/admin/centres.php')) . '">Cancel</a>' : '' ?></header>
    <div class="card__b">
      <?php if ($err): ?><div class="msg err" role="alert"><?= icon('alert') ?><span><?= e($err) ?></span></div><?php endif; ?>
      <div class="field">
        <label for="name">Name</label>
        <input id="name" name="name" value="<?= e($form['name']) ?>" maxlength="120" required placeholder="e.g. Mibenge Rural Health Centre">
      </div>
      <div class="field">
        <label for="location">Location <span class="opt">(optional)</span></label>
        <input id="location" name="location" value="<?= e($form['location']) ?>" maxlength="120" placeholder="Ward or village">
      </div>
<?php if ($editId): ?>
      <div class="field">
        <label class="check"><input type="checkbox" name="is_active" value="1"<?= $form['is_active'] ? ' checked' : '' ?>> Active &mdash; patients can book here</label>
      </div>
<?php endif; ?>
    </div>
    <div class="card__f"><button type="submit"><?= icon($editId ? 'save' : 'plus') ?><?= $editId ? 'Save changes' : 'Add centre' ?></button></div>
  </form>
</aside>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
