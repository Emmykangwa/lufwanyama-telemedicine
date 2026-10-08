<?php
/**
 * health/patients.php -- the patient register for ONE facility.
 *
 * Data isolation: the list only ever contains patients whose facility_id is
 * this health worker's facility. A patient who registered themselves (and so
 * belongs to no facility yet) is not browsable: the worker must know the exact
 * patient number or phone number to attach them to the clinic, and that action
 * is written to the audit log.
 */
require_once __DIR__ . '/../includes/init.php';
$me = require_role(['health_worker']);

$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $lookup = str_replace(' ', '', clean('lookup', 30));

    if ($lookup === '') {
        $err = 'Enter the patient number or phone number.';
    } else {
        $st = $pdo->prepare(
            'SELECT patient_id, full_name, patient_number, facility_id
               FROM patients
              WHERE (upper(patient_number) = upper(?) OR phone = ?)
              LIMIT 1'
        );
        $st->execute([$lookup, $lookup]);
        $found = $st->fetch();

        if (!$found) {
            $err = 'No patient found with that number.';
        } elseif ($found['facility_id'] !== null && (int)$found['facility_id'] === (int)$me['facility_id']) {
            $err = $found['full_name'] . ' is already registered at this facility.';
        } elseif ($found['facility_id'] !== null) {
            // Belongs to another clinic: do not disclose which one.
            $err = 'That patient is registered at another facility. Ask the administrator to move them.';
        } else {
            $pdo->prepare('UPDATE patients SET facility_id = ? WHERE patient_id = ?')
                ->execute([$me['facility_id'], $found['patient_id']]);
            audit($pdo, 'LINK', 'patient', (int)$found['patient_id'],
                'Attached to facility ' . $me['facility_id']);
            flash('ok', $found['full_name'] . ' (' . $found['patient_number'] . ') is now on this facility register.');
            redirect('/health/patients.php');
        }
    }
}

$q    = trim((string)($_GET['q'] ?? ''));
$sql  = "SELECT p.patient_id, p.patient_number, p.full_name, p.sex, p.dob, p.phone, p.village,
                (p.user_id IS NOT NULL) AS has_login,
                (SELECT COUNT(*) FROM appointments a WHERE a.patient_id = p.patient_id) AS visits
           FROM patients p
          WHERE p.facility_id = ?";
$args = [$me['facility_id']];
if ($q !== '') {
    // The default utf8mb4_unicode_ci collation already makes LIKE case-insensitive.
    $sql   .= ' AND (p.full_name LIKE ? OR p.patient_number LIKE ? OR p.phone LIKE ?)';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
}
$sql .= ' ORDER BY p.full_name LIMIT 200';

$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$page_title = t('patients');
require __DIR__ . '/../includes/header.php';
?>
<p class="actions noprint">
  <a class="btn" href="<?= e(url('/health/add_patient.php')) ?>"><?= icon('userplus') ?><?= e(t('add_patient')) ?></a>
  <a class="btn alt" href="<?= e(url('/health/new_appointment.php')) ?>"><?= icon('send') ?><?= e(t('new_appointment')) ?></a>
</p>

<div class="card noprint">
  <h2><?= e(t('search')) ?></h2>
  <form method="get" class="row">
    <div>
      <label for="q">Name, patient number or phone</label>
      <input id="q" name="q" value="<?= e($q) ?>" maxlength="60">
    </div>
    <div><button type="submit"><?= e(t('search')) ?></button></div>
  </form>
</div>

<div class="card noprint">
  <h2>Attach a patient who registered themselves</h2>
  <p class="hint">Ask the patient for the number they received when they registered.</p>
  <?php if ($err): ?><div class="msg err"><?= icon('alert') ?><span><?= e($err) ?></span></div><?php endif; ?>
  <form method="post" class="row">
    <?= csrf_field() ?>
    <div>
      <label for="lookup">Patient number or phone number</label>
      <input id="lookup" name="lookup" placeholder="LUF-26-01234" maxlength="30" required>
    </div>
    <div><button type="submit" class="alt">Attach</button></div>
  </form>
</div>

<div class="scroll">
<table>
  <caption><?= count($rows) ?> patient(s) on this facility register (first 200).</caption>
  <tr>
    <th><?= e(t('patient')) ?></th><th><?= e(t('sex')) ?></th><th><?= e(t('dob')) ?></th>
    <th><?= e(t('phone')) ?></th><th><?= e(t('village')) ?></th><th>Appointments</th><th></th>
  </tr>
<?php if (!$rows): ?>
  <tr><td colspan="7"><?= e(t('no_records')) ?></td></tr>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
  <tr>
    <td><?= e($r['full_name']) ?><br><small><?= e($r['patient_number']) ?><?= $r['has_login'] ? ' &middot; has login' : '' ?></small></td>
    <td><?= e($r['sex']) ?></td>
    <td><?= e($r['dob']) ?></td>
    <td><?= e($r['phone']) ?></td>
    <td><?= e($r['village']) ?></td>
    <td><?= (int)$r['visits'] ?></td>
    <td><a class="btn alt mini" href="<?= e(url('/health/new_appointment.php?patient_id=' . (int)$r['patient_id'])) ?>">Book</a></td>
  </tr>
<?php endforeach; ?>
</table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
