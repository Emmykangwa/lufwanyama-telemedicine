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
    $old['phone']     = str_replace(' ', '', clean('phone', 20));
    $old['dob']       = clean('dob', 10);
    $old['sex']       = clean('sex', 1);
    $old['village']   = clean('village', 100);

    if (mb_strlen($old['full_name']) < 3) {
        $err = 'Please enter the patient name.';
    } elseif ($old['phone'] !== '' && !valid_phone($old['phone'])) {
        $err = 'That phone number is not valid (9 to 15 digits).';
    } elseif ($old['dob'] !== '' && (!valid_date($old['dob']) || $old['dob'] > today())) {
        $err = 'The date of birth is not a valid past date.';
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
            flash('ok', 'Patient saved. Patient number: ' . $pno);
            redirect('/health/new_appointment.php?patient_id=' . $pid);
        } catch (PDOException $ex) {
            error_log('Add patient failed: ' . $ex->getMessage());
            $err = 'Could not save the patient. Please try again.';
        }
    }
}

$page_title = t('add_patient');
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <p class="hint">For residents with no phone of their own. A patient number is generated automatically.</p>
  <?php if ($err): ?><div class="msg err"><?= icon('alert') ?><span><?= e($err) ?></span></div><?php endif; ?>

  <form method="post" data-offline="add-patient">
    <?= csrf_field() ?>

    <label for="full_name"><?= e(t('full_name')) ?></label>
    <input id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" maxlength="120" required>

    <label for="phone"><?= e(t('phone')) ?> (optional)</label>
    <input id="phone" name="phone" inputmode="tel" pattern="\+?[0-9]{9,15}" value="<?= e($old['phone']) ?>">

    <label for="dob"><?= e(t('dob')) ?></label>
    <input id="dob" name="dob" type="date" max="<?= e(today()) ?>" value="<?= e($old['dob']) ?>">

    <label for="sex"><?= e(t('sex')) ?></label>
    <select id="sex" name="sex">
      <option value="">--</option>
      <option value="F"<?= $old['sex'] === 'F' ? ' selected' : '' ?>><?= e(t('female')) ?></option>
      <option value="M"<?= $old['sex'] === 'M' ? ' selected' : '' ?>><?= e(t('male')) ?></option>
    </select>

    <label for="village"><?= e(t('village')) ?></label>
    <input id="village" name="village" value="<?= e($old['village']) ?>" maxlength="100">

    <button type="submit"><?= icon('save') ?><?= e(t('save')) ?></button>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
