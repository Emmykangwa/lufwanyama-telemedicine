<?php
/**
 * health/dashboard.php -- the health worker's overview of their centre.
 *
 * Leads with work: doctor replies to pass on to patients (many have no phone
 * of their own), visits coming up, and requests still waiting on a doctor.
 *
 * Data isolation: every query is filtered on this worker's facility_id.
 */
require_once __DIR__ . '/../includes/init.php';
$me  = require_role(['health_worker']);
$fac = (int)$me['facility_id'];

$st = $pdo->prepare('SELECT status, COUNT(*) AS c FROM appointments WHERE facility_id = ? GROUP BY status');
$st->execute([$fac]);
$counts = array_fill_keys(statuses(), 0);
foreach ($st->fetchAll() as $row) {
    $counts[$row['status']] = (int)$row['c'];
}
$totalAll = array_sum($counts);

// Replies from a doctor in the last 7 days: the patient may not know yet.
$st = $pdo->prepare(
    "SELECT a.appointment_id, a.status, a.appointment_date, a.department, p.full_name, p.phone, p.patient_id,
            c.doctor_response, c.responded_at, d.full_name AS doctor
       FROM appointments a
       JOIN patients p ON p.patient_id = a.patient_id
       JOIN consultations c ON c.appointment_id = a.appointment_id
  LEFT JOIN users d ON d.user_id = c.answered_by
      WHERE a.facility_id = ? AND c.responded_at >= NOW() - INTERVAL 7 DAY
        AND a.status IN ('confirmed','rescheduled','declined') AND c.answered_by <> a.booked_by
   ORDER BY c.responded_at DESC LIMIT 6"
);
$st->execute([$fac]);
$replies = $st->fetchAll();

// Confirmed visits in the next 14 days.
$st = $pdo->prepare(
    "SELECT a.appointment_date, a.department, a.status, p.full_name, p.patient_id,
            COALESCE(d.full_name, 'Any doctor') AS doctor
       FROM appointments a
       JOIN patients p ON p.patient_id = a.patient_id
  LEFT JOIN users d ON d.user_id = a.doctor_id
      WHERE a.facility_id = ? AND a.status IN ('confirmed','rescheduled')
        AND a.appointment_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL 14 DAY
   ORDER BY a.appointment_date, p.full_name LIMIT 8"
);
$st->execute([$fac]);
$upcoming = $st->fetchAll();

// Oldest request still waiting on a doctor.
$st = $pdo->prepare("SELECT MIN(created_at) FROM appointments WHERE facility_id = ? AND status = 'pending'");
$st->execute([$fac]);
$oldest = $st->fetchColumn();

$st = $pdo->prepare('SELECT appointment_date AS k, COUNT(*) AS c FROM appointments WHERE facility_id = ? GROUP BY k ORDER BY k');
$st->execute([$fac]);
$trend = array_map(static fn(array $r): int => (int)$r['c'], $st->fetchAll()) ?: [0, 0];

$st = $pdo->prepare('SELECT appointment_date AS k, status AS s FROM appointments WHERE facility_id = ?');
$st->execute([$fac]);
$marked = [];
$prio   = ['pending' => 5, 'rescheduled' => 4, 'confirmed' => 3, 'completed' => 2, 'declined' => 1];
foreach ($st->fetchAll() as $r) {
    $d = (string)$r['k'];
    if (!isset($marked[$d]) || ($prio[$r['s']] ?? 0) > ($prio[$marked[$d]] ?? 0)) {
        $marked[$d] = (string)$r['s'];
    }
}
$statusRows = [];
foreach (statuses() as $s) {
    $statusRows[] = ['k' => $s, 'c' => $counts[$s]];
}

$st = $pdo->prepare('SELECT COUNT(*) FROM patients WHERE facility_id = ?');
$st->execute([$fac]);
$patientCount = (int)$st->fetchColumn();

$page_title   = greeting() . ', ' . first_name($me['full_name']);
$page_sub     = e((string)$me['facility']) . ' · ' . e(date('l j F Y'));
$page_actions = '<a class="btn btn--ghost" href="' . e(url('/health/add_patient.php')) . '">' . icon('userplus') . 'Register patient</a>'
              . '<a class="btn" href="' . e(url('/health/new_appointment.php')) . '">' . icon('send') . 'New request</a>';
require __DIR__ . '/../includes/header.php';
?>
<div class="metrics">
<?= metric('Waiting on a doctor', (string)$counts['pending'], 'pending', spark_line($trend),
      $counts['pending'] ? 'Oldest sent <b>' . e(since((string)$oldest)) . '</b>' : 'Nothing waiting') ?>
<?= metric('Cleared to travel', (string)($counts['confirmed'] + $counts['rescheduled']), 'patients', spark_bars($trend),
      'Confirmed or given a new date') ?>
<?= metric('Seen', (string)$counts['completed'], 'completed', spark_line([0, $counts['completed']]),
      'Of <b>' . $totalAll . '</b> requests in total') ?>
<?= metric('On the register', (string)$patientCount, 'patients', spark_bars($trend),
      '<a href="' . e(url('/health/patients.php')) . '">Open the register</a>') ?>
</div>

<div class="grid grid-main--wide">
<div>
  <section class="card">
    <header class="card__h"><div><h2>Doctor replies to pass on</h2><p>Last 7 days. Tell patients without a phone.</p></div>
      <a class="btn btn--link" href="<?= e(url('/health/requests.php')) ?>">All requests</a></header>
<?php if (!$replies): ?>
    <?= empty_state('No new replies in the last 7 days.', '', 'chat') ?>
<?php else: ?>
    <div class="scroll"><table>
      <thead><tr><th>Patient</th><th>Decision</th><th>Visit</th><th class="hide-sm">Doctor&rsquo;s note</th></tr></thead>
      <tbody>
<?php foreach ($replies as $r): ?>
      <tr>
        <td><a class="rowlink" href="<?= e(url('/health/patient.php?id=' . (int)$r['patient_id'])) ?>"><?= e($r['full_name']) ?></a><small><?= $r['phone'] ? e($r['phone']) : 'No phone' ?></small></td>
        <td><?= badge($r['status']) ?><small><?= e(since((string)$r['responded_at'])) ?></small></td>
        <td class="nowrap"><?= $r['status'] === 'declined' ? '<span class="muted">—</span>' : e(fmt_date($r['appointment_date'], false)) . '<small>' . e($r['department']) . '</small>' ?></td>
        <td class="hide-sm"><div class="trunc"><?= e($r['doctor_response'] ?? '') ?></div><small><?= e($r['doctor'] ?? '') ?></small></td>
      </tr>
<?php endforeach; ?>
      </tbody>
    </table></div>
<?php endif; ?>
  </section>

  <section class="card">
    <header class="card__h"><h2>Coming up</h2><span class="muted">Next 14 days</span></header>
<?php if (!$upcoming): ?>
    <?= empty_state('No confirmed visits in the next two weeks.', '', 'calendar') ?>
<?php else: ?>
    <ul class="agenda">
<?php foreach ($upcoming as $u): $ts = strtotime((string)$u['appointment_date']); ?>
      <li><span class="agenda__d"><b><?= date('j', $ts) ?></b><span><?= date('M', $ts) ?></span></span>
        <div class="agenda__b"><b><a class="rowlink" href="<?= e(url('/health/patient.php?id=' . (int)$u['patient_id'])) ?>"><?= e($u['full_name']) ?></a></b>
          <span><?= e($u['department']) ?> · <?= e($u['doctor']) ?></span></div>
        <span class="muted nowrap"><?= e(rel_day((string)$u['appointment_date'])) ?></span></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </section>
</div>

<aside>
  <?= panel_open('Where requests stand', '<a class="btn btn--link" href="' . e(url('/health/requests.php')) . '">Open</a>') ?>
    <?= status_split($statusRows) ?>
  <?= panel_close() ?>
  <?= panel_open('Appointment days') ?>
    <?= mini_cal(date('Y-m-01'), $marked) ?>
  <?= panel_close() ?>
</aside>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
