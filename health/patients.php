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
$fac = (int)$me['facility_id'];

$err = '';
$lookup = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $lookup = clean('lookup', 30);
    $key    = str_replace(' ', '', $lookup);
    $phone  = normalize_phone($lookup);

    if ($key === '') {
        $err = 'Enter the patient number or phone number.';
    } else {
        $st = $pdo->prepare(
            'SELECT patient_id, full_name, patient_number, facility_id
               FROM patients
              WHERE (upper(patient_number) = upper(?) OR phone = ?)
              LIMIT 1'
        );
        $st->execute([$key, $phone]);
        $found = $st->fetch();

        if (!$found) {
            $err = 'No patient has that number. Check it with the patient, or register them.';
        } elseif ($found['facility_id'] !== null && (int)$found['facility_id'] === $fac) {
            $err = $found['full_name'] . ' is already on this register.';
        } elseif ($found['facility_id'] !== null) {
            // Belongs to another clinic: do not disclose which one.
            $err = 'That patient is registered at another health centre. The district administrator can move them.';
        } else {
            $pdo->prepare('UPDATE patients SET facility_id = ? WHERE patient_id = ?')
                ->execute([$fac, $found['patient_id']]);
            audit($pdo, 'LINK', 'patient', (int)$found['patient_id'], 'Attached to facility ' . $fac);
            flash('ok', $found['full_name'] . ' (' . $found['patient_number'] . ') is now on this register.');
            redirect('/health/patient.php?id=' . (int)$found['patient_id']);
        }
    }
}

$q    = clean_get('q', 60);
$where = 'p.facility_id = ?';
$args  = [$fac];
if ($q !== '') {
    // The default utf8mb4_unicode_ci collation already makes LIKE case-insensitive.
    $where .= ' AND (p.full_name LIKE ? OR p.patient_number LIKE ? OR p.phone LIKE ? OR p.village LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%", "%$q%");
}
$st = $pdo->prepare("SELECT COUNT(*) FROM patients p WHERE $where");
$st->execute($args);
$found = (int)$st->fetchColumn();
$per   = 25;
$pages = max(1, (int)ceil($found / $per));
$page  = min(max(1, (int)($_GET['p'] ?? 1)), $pages);

$st = $pdo->prepare(
    "SELECT p.patient_id, p.patient_number, p.full_name, p.sex, p.dob, p.phone, p.village,
            (p.user_id IS NOT NULL) AS has_login,
            (SELECT COUNT(*) FROM appointments a WHERE a.patient_id = p.patient_id) AS visits,
            (SELECT a.status FROM appointments a WHERE a.patient_id = p.patient_id
              ORDER BY a.appointment_date DESC, a.appointment_id DESC LIMIT 1) AS last_status,
            (SELECT MAX(a.appointment_date) FROM appointments a WHERE a.patient_id = p.patient_id) AS last_date
       FROM patients p
      WHERE $where
   ORDER BY p.full_name
      LIMIT $per OFFSET " . (($page - 1) * $per)
);
$st->execute($args);
$rows = $st->fetchAll();

$page_title   = 'Patients';
$page_sub     = $found . ' on the ' . e((string)$me['facility']) . ' register';
$page_actions = '<a class="btn" href="' . e(url('/health/add_patient.php')) . '">' . icon('userplus') . 'Register patient</a>';
require __DIR__ . '/../includes/header.php';
?>
<div class="grid grid-main">
<section class="card">
  <form method="get" class="toolbar">
    <div class="search grow"><?= icon('search') ?>
      <input name="q" value="<?= e($q) ?>" placeholder="Search name, patient number, phone or village" aria-label="Search patients" maxlength="60" autofocus>
    </div>
    <button type="submit" class="btn--ghost">Search</button>
<?php if ($q !== ''): ?><a class="btn btn--link" href="<?= e(url('/health/patients.php')) ?>">Clear</a><?php endif; ?>
  </form>

<?php if (!$rows): ?>
  <?= empty_state($q !== '' ? 'Nobody on this register matches “' . $q . '”.' : 'No patients on this register yet.',
        '<a class="btn btn--sm" href="' . e(url('/health/add_patient.php')) . '">' . icon('userplus') . 'Register patient</a>', 'users') ?>
<?php else: ?>
  <div class="scroll">
  <table>
    <thead><tr><th>Patient</th><th class="hide-sm">Sex / age</th><th class="hide-sm">Village</th><th>Last request</th><th class="act"></th></tr></thead>
    <tbody>
<?php foreach ($rows as $r): $age = age_from($r['dob']); ?>
    <tr>
      <td><div class="who"><?= avatar($r['full_name']) ?><div>
        <a class="rowlink" href="<?= e(url('/health/patient.php?id=' . (int)$r['patient_id'])) ?>"><?= e($r['full_name']) ?></a>
        <small class="mono"><?= e($r['patient_number']) ?><?= $r['phone'] ? ' · ' . e($r['phone']) : '' ?></small></div></div></td>
      <td class="hide-sm"><?= e($r['sex'] ?? '—') ?><?= $age !== null ? ' · ' . $age . ' y' : '' ?></td>
      <td class="hide-sm"><?= e($r['village'] ?: '—') ?></td>
      <td><?= $r['last_status'] ? badge((string)$r['last_status']) . '<small>' . e(fmt_date($r['last_date'])) . ' · ' . (int)$r['visits'] . ' total</small>' : '<span class="muted">None yet</span>' ?></td>
      <td class="act"><a class="btn btn--ghost btn--sm" href="<?= e(url('/health/new_appointment.php?patient_id=' . (int)$r['patient_id'])) ?>"><?= icon('send') ?>Request</a></td>
    </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= pager($page, $pages, array_filter(['q' => $q])) ?>
<?php endif; ?>
</section>

<aside>
  <form method="post" class="card">
    <?= csrf_field() ?>
    <header class="card__h"><div><h2>Patient registered on their own?</h2><p>Add them using the number they received.</p></div></header>
    <div class="card__b">
      <?php if ($err): ?><div class="msg err" role="alert"><?= icon('alert') ?><span><?= e($err) ?></span></div><?php endif; ?>
      <div class="field">
        <label for="lookup">Patient number or phone</label>
        <input id="lookup" name="lookup" value="<?= e($lookup) ?>" placeholder="LUF-26-01234" maxlength="30" required>
      </div>
    </div>
    <div class="card__f"><button type="submit" class="btn--ghost">Add to register</button></div>
  </form>
</aside>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
