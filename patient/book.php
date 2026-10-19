<?php
/**
 * patient/book.php -- a patient requests an appointment for themselves (FR2).
 *
 * The patient picks a health centre and, optionally, a specific doctor at that
 * centre (or leaves it as "any doctor"). The request is saved as Pending, with
 * a consultation row carrying any symptoms note, and the doctor sees it.
 */
require_once __DIR__ . '/../includes/init.php';
$me = require_role(['patient']);

const MAX_OPEN_REQUESTS = 5;

$st = $pdo->prepare('SELECT patient_id, facility_id FROM patients WHERE user_id = ?');
$st->execute([$me['user_id']]);
$profile = $st->fetch();

$centres = $pdo->query(
    'SELECT facility_id, name FROM facilities WHERE is_active = 1 ORDER BY name'
)->fetchAll();

$doctors = $pdo->query(
    "SELECT u.user_id, u.full_name, u.facility_id, f.name AS centre
       FROM users u JOIN facilities f ON f.facility_id = u.facility_id
      WHERE u.role = 'doctor' AND u.is_active = 1
   ORDER BY f.name, u.full_name"
)->fetchAll();

$err = '';
$old = ['facility_id' => (string)($profile['facility_id'] ?? ''), 'doctor_id' => '0', 'department' => '', 'appointment_date' => '', 'reason' => '', 'clinical_notes' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    if (!$profile) {
        $err = 'Your login is not linked to a patient record yet.';
    } else {
        $old['facility_id']      = clean('facility_id', 10);
        $old['doctor_id']        = clean('doctor_id', 10);
        $old['department']       = clean('department', 60);
        $old['appointment_date'] = clean('appointment_date', 10);
        $old['reason']           = clean('reason', 255);
        $old['clinical_notes']   = clean('clinical_notes', 4000);

        $fac = (int)$old['facility_id'];
        $doc = (int)$old['doctor_id'];

        $st = $pdo->prepare('SELECT 1 FROM facilities WHERE facility_id = ? AND is_active = 1');
        $st->execute([$fac]);
        $facilityOk = (bool)$st->fetchColumn();

        // A chosen doctor must work at the chosen centre (re-checked on server).
        $doctorOk = true;
        if ($doc > 0) {
            $q = $pdo->prepare("SELECT 1 FROM users WHERE user_id = ? AND role = 'doctor' AND facility_id = ? AND is_active = 1");
            $q->execute([$doc, $fac]);
            $doctorOk = (bool)$q->fetchColumn();
        }

        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM appointments
              WHERE patient_id = ? AND status IN ('pending','confirmed','rescheduled')"
        );
        $st->execute([$profile['patient_id']]);
        $open = (int)$st->fetchColumn();

        if (!$facilityOk) {
            $err = 'Please choose a health centre.';
        } elseif (!$doctorOk) {
            $err = 'That doctor does not work at the chosen health centre.';
        } elseif (!in_array($old['department'], departments(), true)) {
            $err = 'Please choose a department.';
        } elseif (!valid_date($old['appointment_date']) || $old['appointment_date'] < today()) {
            $err = 'Please choose today or a date in the future.';
        } elseif (mb_strlen($old['reason']) < 5) {
            $err = 'Please say briefly why you need to be seen.';
        } elseif ($open >= MAX_OPEN_REQUESTS) {
            $err = 'You already have ' . $open . ' open appointments. Please wait for a reply first.';
        } else {
            try {
                $pdo->beginTransaction();

                $st = $pdo->prepare(
                    'INSERT INTO appointments (patient_id, booked_by, facility_id, doctor_id, department, appointment_date, reason)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $profile['patient_id'], $me['user_id'], $fac, $doc ?: null,
                    $old['department'], $old['appointment_date'], $old['reason'],
                ]);
                $aid = (int)$pdo->lastInsertId();

                $st = $pdo->prepare(
                    'INSERT INTO consultations (appointment_id, sent_by, clinical_notes) VALUES (?, ?, ?)'
                );
                $st->execute([$aid, $me['user_id'], $old['clinical_notes'] !== '' ? $old['clinical_notes'] : null]);

                audit($pdo, 'BOOK', 'appointment', $aid,
                    'Self-booked, centre ' . $fac . ', ' . $old['department'] . ', ' . $old['appointment_date']);

                $pdo->commit();

                flash('ok', 'Request sent. The doctor\'s reply will appear here.');
                redirect('/patient/dashboard.php');
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Self-booking failed: ' . $ex->getMessage());
                $err = 'Could not save the request. Please try again.';
            }
        }
    }
}

// How many requests are already open, so the limit is visible before it bites.
$openNow = 0;
if ($profile) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND status IN ('pending','confirmed','rescheduled')");
    $st->execute([$profile['patient_id']]);
    $openNow = (int)$st->fetchColumn();
}

$page_title = 'Request appointment';
$crumbs     = [['My appointments', '/patient/dashboard.php']];
require __DIR__ . '/../includes/header.php';
?>
<?php if ($openNow >= MAX_OPEN_REQUESTS): ?>
<div class="msg warn"><?= icon('alert') ?><span>You already have <?= $openNow ?> open requests. Wait for a reply before asking for another.</span></div>
<?php endif; ?>
<form method="post" class="card" data-offline="patient-book">
  <?= csrf_field() ?>
  <?php if ($err): ?><div class="card__b"><div class="msg err" role="alert"><?= icon('alert') ?><span><?= e($err) ?></span></div></div><?php endif; ?>

  <div class="fsec">
    <div class="fsec__d"><h3>Where</h3><p>Choose a doctor, or let any doctor on duty reply.</p></div>
    <div class="fsec__f">
      <div class="field">
        <label for="facility_id">Health centre</label>
        <select id="facility_id" name="facility_id" required>
          <option value="">Select a health centre</option>
<?php foreach ($centres as $c): ?>
          <option value="<?= (int)$c['facility_id'] ?>"<?= (int)$c['facility_id'] === (int)$old['facility_id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
<?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="doctor_id">Doctor</label>
        <select id="doctor_id" name="doctor_id" data-filter-by="facility_id">
          <option value="0">Any doctor</option>
<?php foreach ($doctors as $d): ?>
          <option value="<?= (int)$d['user_id'] ?>" data-group="<?= (int)$d['facility_id'] ?>"<?= (int)$d['user_id'] === (int)$old['doctor_id'] ? ' selected' : '' ?>><?= e($d['full_name']) ?></option>
<?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <div class="fsec">
    <div class="fsec__d"><h3>When and why</h3><p>The doctor confirms this date or suggests another.</p></div>
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
        <input id="reason" name="reason" maxlength="255" value="<?= e($old['reason']) ?>" placeholder="e.g. Swollen leg for two weeks" required>
      </div>
      <div class="field">
        <label for="clinical_notes">Symptoms or other details <span class="opt">(optional)</span></label>
        <textarea id="clinical_notes" name="clinical_notes" rows="4"><?= e($old['clinical_notes']) ?></textarea>
      </div>
    </div>
  </div>

  <div class="card__f">
    <a class="btn btn--ghost" href="<?= e(url('/patient/dashboard.php')) ?>">Cancel</a>
    <button type="submit"<?= $openNow >= MAX_OPEN_REQUESTS ? ' disabled' : '' ?>><?= icon('send') ?>Send request</button>
  </div>
</form>
<?php require __DIR__ . '/../includes/footer.php'; ?>
