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

// The next visit that is still ahead and has not been declined.
$today = today();
$upcoming = array_values(array_filter($rows, static fn(array $r): bool =>
    $r['appointment_date'] >= $today && $r['status'] !== 'declined' && $r['status'] !== 'completed'));
usort($upcoming, static fn(array $a, array $b): int => strcmp($a['appointment_date'], $b['appointment_date']));
$next = $upcoming[0] ?? null;

// Day markers for the month grid, and the month worth showing.
$marked = [];
foreach ($rows as $r) {
    $marked[(string)$r['appointment_date']] = (string)$r['status'];
}
$calMonth = $next ? substr((string)$next['appointment_date'], 0, 7) . '-01' : date('Y-m-01');

$statusRows = [];
foreach (statuses() as $s) {
    if ($tally($s) > 0) {
        $statusRows[] = ['k' => $s, 'c' => $tally($s)];
    }
}

// A real series over time reads as a trend; a handful of status totals just
// renders as a few fat blocks.
$months = [];
for ($i = 11; $i >= 0; $i--) {
    $months[date('Y-m', strtotime("-{$i} month"))] = 0;
}
$weeks = [];
for ($i = 15; $i >= 0; $i--) {
    $weeks[date('o-W', strtotime("-{$i} week"))] = 0;
}
foreach ($rows as $r) {
    $m = substr((string)$r['appointment_date'], 0, 7);
    if (isset($months[$m])) {
        $months[$m]++;
    }
    $w = date('o-W', strtotime((string)$r['appointment_date']));
    if (isset($weeks[$w])) {
        $weeks[$w]++;
    }
}
$mSeries = array_values($months);
$wSeries = array_values($weeks);

$page_title = t('my_appointments');
require __DIR__ . '/../includes/header.php';
?>
<?php if ($next): ?>
<div class="next">
  <div class="next__d">
    <b><?= e(date('j', strtotime((string)$next['appointment_date']))) ?></b>
    <span><?= e(date('M Y', strtotime((string)$next['appointment_date']))) ?></span>
  </div>
  <div class="next__b">
    <h2>Your next visit <?= badge((string)$next['status']) ?></h2>
    <p><?= e((string)$next['department']) ?> at <?= e((string)$next['centre']) ?>
       &middot; <?= e((string)($next['doctor'] ?? t('any_doctor'))) ?></p>
    <p class="next__note"><?= icon(in_array($next['status'], ['confirmed', 'rescheduled'], true) ? 'check' : 'alert') ?>
       <?= in_array($next['status'], ['confirmed', 'rescheduled'], true)
          ? 'You may travel for this visit.'
          : 'Do not travel yet — wait until this reads Confirmed.' ?></p>
  </div>
</div>
<?php endif; ?>

<div class="metrics">
<?= metric('Total appointments', (string)count($rows), count($rows) === 1 ? 'visit' : 'visits',
      spark_bars($wSeries),
      $rows ? 'Across all your health centre requests' : 'You have not booked a visit yet') ?>

<?= metric('Awaiting a reply', (string)$tally('pending'), 'pending',
      spark_line($mSeries),
      $tally('pending') > 0
        ? 'A doctor still has to review these'
        : 'Nothing is waiting on a doctor') ?>

<?= metric('Ready to travel', (string)($tally('confirmed') + $tally('rescheduled')), 'confirmed',
      spark_bars($mSeries),
      '<b>Only travel</b> once a visit reads Confirmed') ?>

<?= metric('Seen', (string)$tally('completed'), 'completed',
      spark_line($wSeries),
      'Visits already carried out') ?>
</div>

<div class="cols">
  <div>
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
  </div>
  <div>
    <?= panel_open('Your appointment days') ?>
      <?= mini_cal($calMonth, $marked) ?>
    <?= panel_close() ?>
<?php if ($statusRows): ?>
    <?= panel_open('Where your requests stand') ?>
      <?= status_split($statusRows) ?>
    <?= panel_close() ?>
<?php endif; ?>
  </div>
</div>

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
