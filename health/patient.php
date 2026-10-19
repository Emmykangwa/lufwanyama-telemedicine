<?php
/**
 * health/patient.php -- one patient's record at this centre: details and
 * every request made for them, with the doctor's replies.
 *
 * Data isolation: the patient must be on this health worker's register.
 */
require_once __DIR__ . '/../includes/init.php';
$me  = require_role(['health_worker']);
$fac = (int)$me['facility_id'];

$st = $pdo->prepare('SELECT p.*, (p.user_id IS NOT NULL) AS has_login FROM patients p WHERE p.patient_id = ? AND p.facility_id = ?');
$st->execute([(int)($_GET['id'] ?? 0), $fac]);
$p = $st->fetch();
if (!$p) {
    flash('err', 'That patient is not on this centre’s register.');
    redirect('/health/patients.php');
}

$st = $pdo->prepare(
    "SELECT a.appointment_id, a.appointment_date, a.department, a.status, a.reason, a.created_at,
            c.clinical_notes, c.doctor_response, c.responded_at,
            COALESCE(ans.full_name, doc.full_name) AS doctor, f.name AS centre
       FROM appointments a
       JOIN facilities f ON f.facility_id = a.facility_id
  LEFT JOIN consultations c ON c.appointment_id = a.appointment_id
  LEFT JOIN users ans ON ans.user_id = c.answered_by
  LEFT JOIN users doc ON doc.user_id = a.doctor_id
      WHERE a.patient_id = ? AND a.facility_id = ?
   ORDER BY a.appointment_date DESC, a.appointment_id DESC"
);
$st->execute([(int)$p['patient_id'], $fac]);
$history = $st->fetchAll();

$open = array_filter($history, static fn($h) => in_array($h['status'], ['pending', 'confirmed', 'rescheduled'], true));
$age  = age_from($p['dob']);

$page_title   = $p['full_name'];
$page_sub     = '<span class="mono">' . e($p['patient_number']) . '</span>'
              . ($p['sex'] ? ' · ' . ($p['sex'] === 'F' ? 'Female' : 'Male') : '')
              . ($age !== null ? ' · ' . $age . ' years' : '');
$crumbs       = [['Patients', '/health/patients.php']];
$nav_active   = '/health/patients.php';
$page_actions = '<a class="btn" href="' . e(url('/health/new_appointment.php?patient_id=' . (int)$p['patient_id'])) . '">' . icon('send') . 'New request</a>';
require __DIR__ . '/../includes/header.php';
?>
<div class="grid grid-main">
<section class="card">
  <header class="card__h"><h2>Requests</h2><span class="muted"><?= count($history) ?> total<?= $open ? ' · ' . count($open) . ' open' : '' ?></span></header>
<?php if (!$history): ?>
  <?= empty_state('No requests yet for this patient.', '<a class="btn btn--sm" href="' . e(url('/health/new_appointment.php?patient_id=' . (int)$p['patient_id'])) . '">' . icon('send') . 'New request</a>', 'calendar') ?>
<?php else: ?>
  <ul class="agenda">
<?php foreach ($history as $h): $ts = strtotime((string)$h['appointment_date']); ?>
    <li>
      <span class="agenda__d"><b><?= date('j', $ts) ?></b><span><?= date('M y', $ts) ?></span></span>
      <div class="agenda__b">
        <b><?= e($h['department']) ?> · <?= e($h['reason']) ?></b>
        <span><?= e($h['doctor'] ?? t('any_doctor')) ?><?= $h['doctor_response'] ? ' — “' . e($h['doctor_response']) . '”' : '' ?></span>
      </div>
      <?= badge($h['status']) ?>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>

<aside>
  <section class="card">
    <header class="card__h"><h2>Details</h2></header>
    <div class="card__b">
      <?= kv([
            'Patient number' => '<span class="mono">' . e($p['patient_number']) . '</span>',
            'Phone'          => $p['phone'] ? e($p['phone']) : null,
            'Date of birth'  => $p['dob'] ? e(fmt_date($p['dob'])) : null,
            'Village'        => $p['village'] ? e($p['village']) : null,
            'Own login'      => $p['has_login'] ? 'Yes — follows requests on their phone' : 'No — tell them about replies',
            'Registered'     => e(fmt_date($p['created_at'])),
          ]) ?>
    </div>
  </section>
</aside>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
