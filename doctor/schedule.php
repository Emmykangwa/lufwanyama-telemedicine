<?php
/**
 * doctor/schedule.php -- the clinic diary for the doctor's own health centre.
 *
 * Shows confirmed and rescheduled appointments day by day for a chosen window
 * (default: today to two weeks ahead) and lets the doctor close a visit off as
 * completed once the patient has been seen. Scoped to this doctor's centre and
 * to requests addressed to them or to "any doctor".
 */
require_once __DIR__ . '/../includes/init.php';
$me  = require_role(['doctor']);
$fid = (int)$me['facility_id'];
$uid = (int)$me['user_id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $aid = (int)($_POST['appointment_id'] ?? 0);

    $st = $pdo->prepare(
        "SELECT 1 FROM appointments
          WHERE appointment_id = ? AND facility_id = ?
            AND (doctor_id IS NULL OR doctor_id = ?)
            AND status IN ('confirmed','rescheduled')"
    );
    $st->execute([$aid, $fid, $uid]);

    if (!$st->fetchColumn()) {
        flash('err', 'Only a confirmed appointment at your centre can be completed.');
    } else {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE appointments SET status = 'completed' WHERE appointment_id = ?")->execute([$aid]);
        $pdo->prepare(
            "UPDATE consultations
                SET answered_by = ?, responded_at = NOW(),
                    doctor_response = COALESCE(doctor_response, 'Patient seen.')
              WHERE appointment_id = ?"
        )->execute([$uid, $aid]);
        audit($pdo, 'COMPLETE', 'appointment', $aid, 'Closed from the schedule');
        $pdo->commit();
        flash('ok', 'Appointment marked completed.');
    }
    redirect('/doctor/schedule.php?' . http_build_query(['from' => $_POST['from'] ?? '', 'to' => $_POST['to'] ?? '']));
}

$from = clean_get('from', 10);
$to   = clean_get('to', 10);
$fromSql = valid_date($from) ? $from : today();
$toSql   = valid_date($to)   ? $to   : date('Y-m-d', strtotime('+14 days'));
if ($toSql < $fromSql) {
    [$fromSql, $toSql] = [$toSql, $fromSql];
}

$st = $pdo->prepare(
    "SELECT a.appointment_id, a.appointment_date, a.department, a.status, a.reason,
            p.full_name, p.patient_number, p.sex, p.dob, p.phone,
            c.clinical_notes
       FROM appointments a
       JOIN patients     p ON p.patient_id = a.patient_id
  LEFT JOIN consultations c ON c.appointment_id = a.appointment_id
      WHERE a.facility_id = ? AND (a.doctor_id IS NULL OR a.doctor_id = ?)
        AND a.status IN ('confirmed','rescheduled')
        AND a.appointment_date BETWEEN ? AND ?
   ORDER BY a.appointment_date, a.department, p.full_name"
);
$st->execute([$fid, $uid, $fromSql, $toSql]);
$rows = $st->fetchAll();

$byDay = [];
foreach ($rows as $r) {
    $byDay[$r['appointment_date']][] = $r;
}

$page_title = t('my_schedule');
require __DIR__ . '/../includes/header.php';
?>
<form method="get" class="card noprint row">
  <div>
    <label for="from"><?= e(t('from')) ?></label>
    <input id="from" name="from" type="date" value="<?= e($fromSql) ?>">
  </div>
  <div>
    <label for="to"><?= e(t('to')) ?></label>
    <input id="to" name="to" type="date" value="<?= e($toSql) ?>">
  </div>
  <div><button type="submit"><?= icon('search') ?><?= e(t('apply')) ?></button></div>
  <div><button type="button" class="alt" data-print="1"><?= icon('printer') ?><?= e(t('print')) ?></button></div>
</form>

<p class="hint"><?= count($rows) ?> appointment(s) between <?= e($fromSql) ?> and <?= e($toSql) ?>.
   Requests still waiting for an answer are on the
   <a href="<?= e(url('/doctor/dashboard.php')) ?>"><?= e(t('incoming')) ?></a> page.</p>

<?php if (!$byDay): ?>
  <div class="card"><?= e(t('no_records')) ?></div>
<?php endif; ?>

<?php foreach ($byDay as $day => $list): ?>
<div class="card">
  <h2><?= e($day) ?> &middot; <?= count($list) ?> patient(s)</h2>
  <div class="scroll">
  <table>
    <tr><th><?= e(t('patient')) ?></th><th><?= e(t('status')) ?></th><th><?= e(t('department')) ?></th>
        <th><?= e(t('reason')) ?></th><th class="noprint"></th></tr>
<?php foreach ($list as $r): ?>
    <tr>
      <td><?= e($r['full_name']) ?><br>
          <small><?= e($r['patient_number']) ?><?= $r['sex'] ? ' &middot; ' . e($r['sex']) : '' ?>
          <?= $r['phone'] ? ' &middot; ' . e($r['phone']) : '' ?></small></td>
      <td><?= badge($r['status']) ?></td>
      <td><?= e($r['department']) ?></td>
      <td><?= e($r['reason']) ?>
        <?php if ($r['clinical_notes']): ?>
          <details><summary><?= e(t('notes')) ?></summary><?= nl2br(e($r['clinical_notes'])) ?></details>
        <?php endif; ?>
      </td>
      <td class="noprint">
        <form method="post" class="inline" data-confirm="Mark this appointment completed?">
          <?= csrf_field() ?>
          <input type="hidden" name="appointment_id" value="<?= (int)$r['appointment_id'] ?>">
          <input type="hidden" name="from" value="<?= e($fromSql) ?>">
          <input type="hidden" name="to" value="<?= e($toSql) ?>">
          <button type="submit" class="alt mini"><?= icon('check') ?><?= e(t('completed')) ?></button>
        </form>
      </td>
    </tr>
<?php endforeach; ?>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
