<?php
/**
 * patient/dashboard.php -- a patient tracks their own appointments (FR5)
 *
 * Data isolation: every query is filtered by p.user_id = the logged-in user,
 * so one patient can never read another patient's rows, whatever they type
 * in the address bar.
 */
require_once __DIR__ . '/../includes/init.php';
$me = require_role(['patient']);

$st = $pdo->prepare('SELECT patient_id, patient_number, full_name FROM patients WHERE user_id = ?');
$st->execute([$me['user_id']]);
$profile = $st->fetch();

$st = $pdo->prepare(
    "SELECT a.appointment_id, a.appointment_date, a.department, a.status, a.reason,
            f.name AS centre,
            c.clinical_notes, c.doctor_response, c.responded_at,
            COALESCE(ans.full_name, doc.full_name) AS doctor
       FROM appointments a
       JOIN patients   p   ON p.patient_id  = a.patient_id
       JOIN facilities f   ON f.facility_id = a.facility_id
  LEFT JOIN consultations c ON c.appointment_id = a.appointment_id
  LEFT JOIN users ans       ON ans.user_id = c.answered_by
  LEFT JOIN users doc       ON doc.user_id = a.doctor_id
      WHERE p.user_id = ?
   ORDER BY a.appointment_date DESC, a.appointment_id DESC"
);
$st->execute([$me['user_id']]);
$rows = $st->fetchAll();

$tally = static fn(string $s): int => count(array_filter($rows, static fn($r) => $r['status'] === $s));

$page_title = t('my_appointments');
require __DIR__ . '/../includes/header.php';
?>
<div class="stats">
  <div class="stat"><span>Total appointments</span><b><?= count($rows) ?></b></div>
  <div class="stat"><span><?= e(t('pending')) ?></span><b><?= $tally('pending') ?></b></div>
  <div class="stat"><span><?= e(t('confirmed')) ?></span><b><?= $tally('confirmed') + $tally('rescheduled') ?></b></div>
  <div class="stat"><span><?= e(t('completed')) ?></span><b><?= $tally('completed') ?></b></div>
</div>

<div class="card">
<?php if ($profile): ?>
  <h2><?= e(t('patient_number')) ?></h2>
  <p><strong class="pnum"><?= e($profile['patient_number']) ?></strong></p>
  <p class="hint">Give this number to the health worker when you visit the clinic.</p>
<?php else: ?>
  <div class="msg err"><?= icon('alert') ?><span>Your login is not linked to a patient record yet. Ask a health worker at your clinic to link it.</span></div>
<?php endif; ?>
  <a class="btn" href="<?= e(url('/patient/book.php')) ?>"><?= icon('calendar') ?><?= e(t('book')) ?></a>
</div>

<div class="warnline noprint"><?= icon('alert') ?><span><?= e(t('travel_warning')) ?></span></div>

<h2><?= e(t('my_appointments')) ?> (<?= count($rows) ?>)</h2>
<div class="scroll">
<table>
  <caption>Newest first. The response column is what the doctor wrote back.</caption>
  <tr>
    <th><?= e(t('date')) ?></th>
    <th><?= e(t('status')) ?></th>
    <th><?= e(t('centre')) ?></th>
    <th><?= e(t('doctor')) ?></th>
    <th><?= e(t('department')) ?></th>
    <th><?= e(t('response')) ?></th>
  </tr>
<?php if (!$rows): ?>
  <tr><td colspan="6"><?= e(t('no_records')) ?>
      <a href="<?= e(url('/patient/book.php')) ?>"><?= e(t('book')) ?></a></td></tr>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
  <tr>
    <td><?= e($r['appointment_date']) ?></td>
    <td><?= badge($r['status']) ?></td>
    <td><?= e($r['centre']) ?></td>
    <td><?= e($r['doctor'] ?? t('any_doctor')) ?></td>
    <td><?= e($r['department']) ?></td>
    <td>
      <?= $r['doctor_response'] !== null ? e($r['doctor_response']) : '<span class="hint">waiting</span>' ?>
      <?php if ($r['reason']): ?>
        <details><summary>Details</summary>
          <p><b><?= e(t('reason')) ?>:</b> <?= e($r['reason']) ?></p>
          <?php if ($r['clinical_notes']): ?>
            <p><b><?= e(t('notes')) ?>:</b> <?= nl2br(e($r['clinical_notes'])) ?></p>
          <?php endif; ?>
        </details>
      <?php endif; ?>
    </td>
  </tr>
<?php endforeach; ?>
</table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
