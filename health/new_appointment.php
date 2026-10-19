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

            flash('ok', 'Request sent to ' . ($doc ? 'the doctor' : 'the doctors at ' . $me['facility']) . '. You will see the reply under Requests.');
            redirect('/health/requests.php');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('New appointment failed: ' . $ex->getMessage());
            $err = 'Could not save the request. Nothing was changed. Please try again.';
        }
    }
}

// Context for the chosen patient: their open requests, so nobody double-books.
$current = null;
$openReq = [];
foreach ($patients as $p) {
    if ((int)$p['patient_id'] === (int)$old['patient_id']) {
        $current = $p;
    }
}
if ($current) {
    $st = $pdo->prepare("SELECT appointment_date, department, status FROM appointments
                          WHERE patient_id = ? AND status IN ('pending','confirmed','rescheduled')
                       ORDER BY appointment_date");
    $st->execute([(int)$current['patient_id']]);
    $openReq = $st->fetchAll();
}

$page_title = 'New request';
$page_sub   = 'To a doctor at ' . e((string)$me['facility']);
require __DIR__ . '/../includes/header.php';
?>
<?php if (!$patients): ?>
<section class="card"><?= empty_state('No patients on this register yet. Register the patient first.',
    '<a class="btn btn--sm" href="' . e(url('/health/add_patient.php')) . '">' . icon('userplus') . 'Register patient</a>', 'users') ?></section>
<?php else: ?>
<div class="grid grid-main">
<form method="post" class="card" data-offline="appointment">
  <?= csrf_field() ?>
  <?php if ($err): ?><div class="card__b"><div class="msg err" role="alert"><?= icon('alert') ?><span><?= e($err) ?></span></div></div><?php endif; ?>

  <div class="fsec">
    <div class="fsec__d"><h3>Patient and doctor</h3><p>Leave the doctor as &ldquo;Any doctor&rdquo; to reach whoever is on duty.</p></div>
    <div class="fsec__f">
      <div class="field">
        <label for="patient_id">Patient</label>
        <select id="patient_id" name="patient_id" required data-reload-param="patient_id">
          <option value="">Select a patient</option>
<?php foreach ($patients as $p): ?>
          <option value="<?= (int)$p['patient_id'] ?>"<?= (int)$p['patient_id'] === (int)$old['patient_id'] ? ' selected' : '' ?>><?= e($p['full_name']) ?> · <?= e($p['patient_number']) ?></option>
<?php endforeach; ?>
        </select>
        <p class="hint">Not listed? <a href="<?= e(url('/health/add_patient.php')) ?>">Register the patient</a>.</p>
      </div>
      <div class="field">
        <label for="doctor_id">Doctor</label>
        <select id="doctor_id" name="doctor_id">
          <option value="0">Any doctor at this centre</option>
<?php foreach ($doctors as $d): ?>
          <option value="<?= (int)$d['user_id'] ?>"<?= (int)$d['user_id'] === (int)$old['doctor_id'] ? ' selected' : '' ?>><?= e($d['full_name']) ?></option>
<?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <div class="fsec">
    <div class="fsec__d"><h3>Visit</h3><p>The doctor confirms this date or proposes another.</p></div>
    <div class="fsec__f">
      <div class="fgrid">
        <div class="field">
          <label for="department">Department</label>
          <select id="department" name="department" required>
            <option value="">Select</option>
<?php foreach (departments() as $d): ?>
            <option value="<?= e($d) ?>"<?= $d === $old['department'] ? ' selected' : '' ?>><?= e($d) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="appointment_date">Preferred date</label>
          <input id="appointment_date" name="appointment_date" type="date" min="<?= e(today()) ?>" value="<?= e($old['appointment_date']) ?>" required>
        </div>
      </div>
      <div class="field">
        <label for="reason">Reason</label>
        <input id="reason" name="reason" maxlength="255" value="<?= e($old['reason']) ?>" placeholder="e.g. Persistent fever, not responding to treatment" required>
      </div>
    </div>
  </div>

  <div class="fsec">
    <div class="fsec__d"><h3>Clinical notes</h3><p>The doctor reads these before deciding.</p></div>
    <div class="fsec__f">
      <div class="field">
        <label for="clinical_notes">Notes for the doctor <span class="opt">(recommended)</span></label>
        <textarea id="clinical_notes" name="clinical_notes" rows="5"
          placeholder="Symptoms and duration, temperature, blood pressure, test results, treatment already given"><?= e($old['clinical_notes']) ?></textarea>
      </div>
    </div>
  </div>

  <div class="card__f">
    <a class="btn btn--ghost" href="<?= e(url('/health/requests.php')) ?>">Cancel</a>
    <button type="submit"><?= icon('send') ?>Send to doctor</button>
  </div>
</form>

<aside>
<?php if ($current): ?>
  <section class="card">
    <div class="case-h"><?= avatar($current['full_name'], 'av av--lg') ?><div><h2><?= e($current['full_name']) ?></h2><p class="mono"><?= e($current['patient_number']) ?></p></div></div>
    <div class="card__b">
<?php if ($openReq): ?>
      <div class="msg warn"><?= icon('alert') ?><span>Already has <?= count($openReq) ?> open request<?= count($openReq) === 1 ? '' : 's' ?>.</span></div>
      <ul class="tl">
<?php foreach ($openReq as $o): ?>
        <li><span class="tl__dot s-<?= e($o['status']) ?>"></span><div><p class="tl__t"><?= e($o['department']) ?></p><p class="tl__m"><?= e(t($o['status'])) ?></p></div><time class="tl__w"><?= e(fmt_date($o['appointment_date'], false)) ?></time></li>
<?php endforeach; ?>
      </ul>
<?php else: ?>
      <p class="muted">No open requests.</p>
<?php endif; ?>
      <p><a href="<?= e(url('/health/patient.php?id=' . (int)$current['patient_id'])) ?>">Full record</a></p>
    </div>
  </section>
<?php endif; ?>
  <section class="card">
    <header class="card__h"><h2>Doctors at this centre</h2></header>
<?php if (!$doctors): ?>
    <div class="card__b"><div class="msg err"><?= icon('alert') ?><span>No active doctor here yet. Requests will wait until the administrator registers one.</span></div></div>
<?php else: ?>
    <ul class="agenda">
<?php foreach ($doctors as $d): ?>
      <li><?= avatar($d['full_name']) ?><div class="agenda__b"><b><?= e($d['full_name']) ?></b></div><span></span></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </section>
</aside>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
