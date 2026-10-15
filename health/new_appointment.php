<?php
/**
 * health/new_appointment.php -- a health worker requests an appointment for a
 * patient (FR2) and sends the preliminary clinical notes to the doctor (FR3).
 *
 * The doctor works inside the health worker's own centre, so the centre is set
 * automatically and only doctors at that centre can be chosen (or "any doctor").
 *
 * The appointment and its consultation row are written in ONE transaction:
 * either both exist or neither does, so a request can never be left without its
 * clinical notes if the connection drops mid-save.
 */
require_once __DIR__ . '/../includes/init.php';
$me  = require_role(['health_worker']);
$fac = (int)$me['facility_id'];

// Only this facility's patients may be booked (data isolation).
$st = $pdo->prepare(
    'SELECT patient_id, patient_number, full_name FROM patients WHERE facility_id = ? ORDER BY full_name'
);
$st->execute([$fac]);
$patients = $st->fetchAll();

// Only doctors who work at this health centre.
$st = $pdo->prepare(
    "SELECT user_id, full_name FROM users
      WHERE role = 'doctor' AND facility_id = ? AND is_active = 1 ORDER BY full_name"
);
$st->execute([$fac]);
$doctors = $st->fetchAll();

$err = '';
$old = [
    'patient_id'       => (string)($_GET['patient_id'] ?? ''),
    'doctor_id'        => '0',
    'department'       => '',
    'appointment_date' => '',
    'reason'           => '',
    'clinical_notes'   => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    foreach (array_keys($old) as $k) {
        $old[$k] = clean($k, $k === 'clinical_notes' ? 4000 : 255);
    }

    $pid = (int)$old['patient_id'];
    $doc = (int)$old['doctor_id'];

    // Re-check on the server: the browser's select list proves nothing.
    $st = $pdo->prepare('SELECT 1 FROM patients WHERE patient_id = ? AND facility_id = ?');
    $st->execute([$pid, $fac]);
    $patientOk = (bool)$st->fetchColumn();

    $doctorOk = true;
    if ($doc > 0) {
        $q = $pdo->prepare("SELECT 1 FROM users WHERE user_id = ? AND role = 'doctor' AND facility_id = ? AND is_active = 1");
        $q->execute([$doc, $fac]);
        $doctorOk = (bool)$q->fetchColumn();
    }

    if (!$patientOk) {
        $err = 'Please select a patient from this facility register.';
    } elseif (!$doctorOk) {
        $err = 'That doctor does not work at your health centre.';
    } elseif (!in_array($old['department'], departments(), true)) {
        $err = 'Please select a department.';
    } elseif (!valid_date($old['appointment_date']) || $old['appointment_date'] < today()) {
        $err = 'Please choose today or a date in the future.';
    } elseif (mb_strlen($old['reason']) < 5) {
        $err = 'Please give the reason for the appointment.';
    } else {
        try {
            $pdo->beginTransaction();

            $st = $pdo->prepare(
                'INSERT INTO appointments (patient_id, booked_by, facility_id, doctor_id, department, appointment_date, reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $pid, $me['user_id'], $fac, $doc ?: null,
                $old['department'], $old['appointment_date'], $old['reason'],
            ]);
            $aid = (int)$pdo->lastInsertId();

            $st = $pdo->prepare(
                'INSERT INTO consultations (appointment_id, sent_by, clinical_notes) VALUES (?, ?, ?)'
            );
            $st->execute([$aid, $me['user_id'], $old['clinical_notes'] !== '' ? $old['clinical_notes'] : null]);

            audit($pdo, 'CREATE', 'appointment', $aid,
                $old['department'] . ', ' . $old['appointment_date'] . ', doctor ' . ($doc ?: 'any'));

            $pdo->commit();

            flash('ok', 'Appointment request sent to the doctor. The patient can now follow its status.');
            redirect('/health/dashboard.php');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('New appointment failed: ' . $ex->getMessage());
            $err = 'Could not save the request. Nothing was changed. Please try again.';
        }
    }
}

$page_title = t('new_appointment');
require __DIR__ . '/../includes/header.php';
?>
<div class="card card--form">
  <?php if ($err): ?><div class="msg err"><?= icon('alert') ?><span><?= e($err) ?></span></div><?php endif; ?>

<?php if (!$patients): ?>
  <div class="msg err"><?= icon('alert') ?><span>No patients on this facility register yet.
    <a href="<?= e(url('/health/add_patient.php')) ?>"><?= e(t('add_patient')) ?></a> first.</span></div>
<?php endif; ?>

  <form method="post" data-offline="appointment">
    <?= csrf_field() ?>

    <label for="patient_id"><?= e(t('patient')) ?></label>
    <select id="patient_id" name="patient_id" required>
      <option value=""><?= e(t('select')) ?></option>
<?php foreach ($patients as $p): ?>
      <option value="<?= (int)$p['patient_id'] ?>"<?= (int)$p['patient_id'] === (int)$old['patient_id'] ? ' selected' : '' ?>>
        <?= e($p['full_name']) ?> (<?= e($p['patient_number']) ?>)
      </option>
<?php endforeach; ?>
    </select>
    <p class="hint"><a href="<?= e(url('/health/add_patient.php')) ?>">Patient not listed? Add them first.</a></p>

    <label for="doctor_id"><?= e(t('doctor')) ?> (optional)</label>
    <select id="doctor_id" name="doctor_id">
      <option value="0">-- <?= e(t('any_doctor')) ?> at this centre --</option>
<?php foreach ($doctors as $d): ?>
      <option value="<?= (int)$d['user_id'] ?>"<?= (int)$d['user_id'] === (int)$old['doctor_id'] ? ' selected' : '' ?>>
        <?= e($d['full_name']) ?>
      </option>
<?php endforeach; ?>
    </select>

    <div class="fgrid">
      <div class="fld">
        <label for="department"><?= e(t('department')) ?></label>
        <select id="department" name="department" required>
          <option value=""><?= e(t('select')) ?></option>
<?php foreach (departments() as $d): ?>
          <option value="<?= e($d) ?>"<?= $d === $old['department'] ? ' selected' : '' ?>><?= e($d) ?></option>
<?php endforeach; ?>
        </select>
      </div>
      <div class="fld">
        <label for="appointment_date"><?= e(t('date')) ?> requested</label>
        <input id="appointment_date" name="appointment_date" type="date" min="<?= e(today()) ?>"
               value="<?= e($old['appointment_date']) ?>" required>
      </div>
    </div>

    <label for="reason"><?= e(t('reason')) ?></label>
    <input id="reason" name="reason" maxlength="255" value="<?= e($old['reason']) ?>"
           placeholder="e.g. Persistent fever, not responding to treatment" required>

    <label for="clinical_notes"><?= e(t('notes')) ?></label>
    <textarea id="clinical_notes" name="clinical_notes"
      placeholder="Symptoms and duration, temperature, blood pressure, test results, treatment already given"><?= e($old['clinical_notes']) ?></textarea>
    <p class="hint">These notes reach the doctor before the patient arrives, so the visit is not wasted.</p>

    <button type="submit"><?= icon('send') ?><?= e(t('submit')) ?></button>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
