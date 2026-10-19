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

$st = $pdo->prepare('SELECT p.patient_id, p.patient_number, p.full_name, f.name AS centre FROM patients p LEFT JOIN facilities f ON f.facility_id = p.facility_id WHERE p.user_id = ?');
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

$open = $tally('pending') + $tally('confirmed') + $tally('rescheduled');

$page_title   = greeting() . ', ' . first_name($me['full_name']);
$page_sub     = $profile ? 'Patient number <span class="mono">' . e($profile['patient_number']) . '</span>' . ($profile['centre'] ? ' &middot; ' . e($profile['centre']) : '') : '';
$page_actions = '<a class="btn" href="' . e(url('/patient/book.php')) . '">' . icon('calendar') . 'Request appointment</a>';
require __DIR__ . '/../includes/header.php';
?>
<?php if (!$profile): ?>
<div class="msg err"><?= icon('alert') ?><span>Your login is not linked to a patient record yet. Ask a health worker at your clinic to link it.</span></div>
<?php endif; ?>

<?php if ($next): $go = in_array($next['status'], ['confirmed', 'rescheduled'], true); ?>
<div class="next <?= $go ? 'next--ok' : 'next--wait' ?>">
  <div class="next__d">
    <b><?= e(date('j', strtotime((string)$next['appointment_date']))) ?></b>
    <span><?= e(date('M', strtotime((string)$next['appointment_date']))) ?></span>
  </div>
  <div class="next__b">
    <h2>Next visit &middot; <?= e(rel_day((string)$next['appointment_date'])) ?> <?= badge((string)$next['status']) ?></h2>
    <p><?= e((string)$next['department']) ?> at <?= e((string)$next['centre']) ?> &middot; <?= e((string)($next['doctor'] ?? t('any_doctor'))) ?></p>
    <p class="next__note"><?= icon($go ? 'check' : 'clock') ?><?= $go ? 'Confirmed: you may travel for this visit.' : 'Waiting for the doctor. Do not travel yet.' ?></p>
<?php if ($next['doctor_response']): ?>
    <p class="note note--reply">&ldquo;<?= e($next['doctor_response']) ?>&rdquo;</p>
<?php endif; ?>
  </div>
</div>
<?php elseif ($profile && !$rows): ?>
<section class="card"><?= empty_state('You have no appointments yet. Request one and a doctor at the health centre will reply.',
    '<a class="btn btn--sm" href="' . e(url('/patient/book.php')) . '">' . icon('calendar') . 'Request appointment</a>', 'calendar') ?></section>
<?php endif; ?>

<?php if ($rows): ?>
<div class="grid grid-main">
<section class="card">
  <header class="card__h"><h2>My requests</h2><span class="muted"><?= count($rows) ?> total<?= $open ? ' &middot; ' . $open . ' open' : '' ?></span></header>
  <ul class="agenda">
<?php foreach ($rows as $r): $ts = strtotime((string)$r['appointment_date']); ?>
    <li>
      <span class="agenda__d"><b><?= date('j', $ts) ?></b><span><?= date('M y', $ts) ?></span></span>
      <div class="agenda__b">
        <b><?= e($r['department']) ?> &middot; <?= e($r['centre']) ?></b>
        <span><?= $r['doctor_response'] !== null ? e($r['doctor'] ?? 'Doctor') . ': &ldquo;' . e($r['doctor_response']) . '&rdquo;' : 'Waiting for a doctor&rsquo;s reply' ?></span>
      </div>
      <?= badge($r['status']) ?>
    </li>
<?php endforeach; ?>
  </ul>
</section>
<aside>
  <?= panel_open('My appointment days') ?>
    <?= mini_cal($calMonth, $marked) ?>
  <?= panel_close() ?>
  <?= panel_open('Summary') ?>
    <?= status_split($statusRows) ?>
  <?= panel_close() ?>
</aside>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
