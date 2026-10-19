<?php
/**
 * health/add_patient.php -- register a resident who has no phone or login.
 * The new patient belongs to the health worker's own facility.
 */
require_once __DIR__ . '/../includes/init.php';
$me = require_role(['health_worker']);

$err = '';
$old = ['full_name' => '', 'phone' => '', 'dob' => '', 'sex' => '', 'village' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    $old['full_name'] = clean('full_name', 120);
    $old['phone']     = normalize_phone(clean('phone', 20));
    $old['dob']       = clean('dob', 10);
    $old['sex']       = clean('sex', 1);
    $old['village']   = clean('village', 100);

    if (mb_strlen($old['full_name']) < 3) {
        $err = 'Please enter the patient name.';
    } elseif ($old['phone'] !== '' && !valid_phone($old['phone'])) {
        $err = 'That phone number is not valid, for example 097 123 4567.';
    } elseif ($old['dob'] !== '' && (!valid_date($old['dob']) || $old['dob'] > today())) {
        $err = 'The date of birth is not a valid past date.';
    } elseif ($dup = find_duplicate($pdo, $old, (int)$me['facility_id'])) {
        $err = $dup;
    } else {
        try {
            $pno = new_patient_number($pdo);
            $st  = $pdo->prepare(
                'INSERT INTO patients (patient_number, full_name, dob, sex, phone, village, facility_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $pno,
                $old['full_name'],
                date_or_null($old['dob']),
                in_array($old['sex'], ['F', 'M'], true) ? $old['sex'] : null,
                $old['phone'] !== '' ? $old['phone'] : null,
                $old['village'] !== '' ? $old['village'] : null,
                $me['facility_id'],
            ]);
            $pid = (int)$pdo->lastInsertId();

            audit($pdo, 'CREATE', 'patient', $pid, 'Registered at facility ' . $me['facility_id'] . ', ' . $pno);
            flash('ok', $old['full_name'] . ' registered as ' . $pno . '. Now complete the request.');
            redirect('/health/new_appointment.php?patient_id=' . $pid);
        } catch (PDOException $ex) {
            error_log('Add patient failed: ' . $ex->getMessage());
            $err = 'Could not save the patient. Please try again.';
        }
    }
}

/** A likely existing record for the same person, described for the worker, or null. */
function find_duplicate(PDO $pdo, array $old, int $fac): ?string
{
    if ($old['phone'] !== '') {
        $st = $pdo->prepare('SELECT full_name, patient_number, facility_id FROM patients WHERE phone = ? LIMIT 1');
        $st->execute([$old['phone']]);
        if ($d = $st->fetch()) {
            return (int)$d['facility_id'] === $fac
                ? 'This phone number already belongs to ' . $d['full_name'] . ' (' . $d['patient_number'] . ') on your register.'
                : 'A patient with this phone number already exists. Add them from the Patients page with their patient number instead.';
        }
    }
    if ($old['dob'] !== '') {
        $st = $pdo->prepare('SELECT patient_number FROM patients WHERE facility_id = ? AND full_name = ? AND dob = ? LIMIT 1');
        $st->execute([$fac, $old['full_name'], $old['dob']]);
        if ($n = $st->fetchColumn()) {
            return $old['full_name'] . ' with this date of birth is already registered (' . $n . ').';
        }
    }
    return null;
}

$page_title = 'Register patient';
$crumbs     = [['Patients', '/health/patients.php']];
$nav_active = '/health/add_patient.php';
require __DIR__ . '/../includes/header.php';
?>
<form method="post" class="card" data-offline="add-patient">
  <?= csrf_field() ?>
  <?php if ($err): ?><div class="card__b"><div class="msg err" role="alert"><?= icon('alert') ?><span><?= e($err) ?></span></div></div><?php endif; ?>
  <div class="fsec">
    <div class="fsec__d"><h3>Patient</h3><p>A patient number is issued on saving.</p></div>
    <div class="fsec__f">
      <div class="field">
        <label for="full_name">Full name</label>
        <input id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" maxlength="120" required autofocus>
      </div>
      <div class="fgrid fgrid--3">
        <div class="field">
          <label for="sex">Sex</label>
          <select id="sex" name="sex">
            <option value="">&mdash;</option>
            <option value="F"<?= $old['sex'] === 'F' ? ' selected' : '' ?>>Female</option>
            <option value="M"<?= $old['sex'] === 'M' ? ' selected' : '' ?>>Male</option>
          </select>
        </div>
        <div class="field">
          <label for="dob">Date of birth</label>
          <input id="dob" name="dob" type="date" max="<?= e(today()) ?>" value="<?= e($old['dob']) ?>">
        </div>
        <div class="field">
          <label for="village">Village</label>
          <input id="village" name="village" value="<?= e($old['village']) ?>" maxlength="100">
        </div>
      </div>
    </div>
  </div>
  <div class="fsec">
    <div class="fsec__d"><h3>Contact</h3><p>Leave empty if the patient has no phone.</p></div>
    <div class="fsec__f">
      <div class="field">
        <label for="phone">Phone number <span class="opt">(optional)</span></label>
        <input id="phone" name="phone" inputmode="tel" value="<?= e($old['phone']) ?>" placeholder="097 123 4567">
      </div>
    </div>
  </div>
  <div class="card__f">
    <a class="btn btn--ghost" href="<?= e(url('/health/patients.php')) ?>">Cancel</a>
    <button type="submit"><?= icon('save') ?>Save and create request</button>
  </div>
</form>
<?php require __DIR__ . '/../includes/footer.php'; ?>
