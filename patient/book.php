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
$old = ['facility_id' => '', 'doctor_id' => '0', 'department' => '', 'appointment_date' => '', 'reason' => '', 'clinical_notes' => ''];

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

                flash('ok', 'Request sent. Check this page for the reply before you travel.');
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

$page_title = t('book');
require __DIR__ . '/../includes/header.php';
?>
<div class="card card--form">
  <h2><?= e(t('book')) ?></h2>
  <p class="hint">A doctor at the health centre will confirm, suggest another date, or decline with a reason.</p>
  <?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>

  <form method="post" data-offline="patient-book">
    <?= csrf_field() ?>

    <label for="facility_id"><?= e(t('centre')) ?></label>
    <select id="facility_id" name="facility_id" required>
      <option value=""><?= e(t('select')) ?></option>
<?php foreach ($centres as $c): ?>
      <option value="<?= (int)$c['facility_id'] ?>"<?= (int)$c['facility_id'] === (int)$old['facility_id'] ? ' selected' : '' ?>>
        <?= e($c['name']) ?>
      </option>
<?php endforeach; ?>
    </select>

    <label for="doctor_id"><?= e(t('doctor')) ?> (optional &mdash; must work at the centre above)</label>
    <select id="doctor_id" name="doctor_id">
      <option value="0">-- <?= e(t('any_doctor')) ?> --</option>
<?php foreach ($doctors as $d): ?>
      <option value="<?= (int)$d['user_id'] ?>"<?= (int)$d['user_id'] === (int)$old['doctor_id'] ? ' selected' : '' ?>>
        <?= e($d['full_name']) ?> (<?= e($d['centre']) ?>)
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
        <label for="appointment_date"><?= e(t('date')) ?></label>
        <input id="appointment_date" name="appointment_date" type="date" min="<?= e(today()) ?>"
               value="<?= e($old['appointment_date']) ?>" required>
      </div>
    </div>

    <label for="reason"><?= e(t('reason')) ?></label>
    <input id="reason" name="reason" maxlength="255" value="<?= e($old['reason']) ?>"
           placeholder="e.g. Swollen leg for two weeks" required>

    <label for="clinical_notes">Symptoms or other details for the doctor (optional)</label>
    <textarea id="clinical_notes" name="clinical_notes"><?= e($old['clinical_notes']) ?></textarea>

    <button type="submit"><?= icon('send') ?><?= e(t('submit')) ?></button>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
