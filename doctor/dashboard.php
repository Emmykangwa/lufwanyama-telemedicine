<?php
/**
 * doctor/dashboard.php -- the review queue for the doctor's own centre.
 *
 * The doctor sees requests at their centre addressed to them OR to "any
 * doctor" (doctor_id IS NULL). The list is compact; each case opens on
 * doctor/case.php, where the reply is written (FR4).
 */
require_once __DIR__ . '/../includes/init.php';
$me  = require_role(['doctor']);
$fid = (int)$me['facility_id'];
$uid = (int)$me['user_id'];

$scope = 'a.facility_id = ? AND (a.doctor_id IS NULL OR a.doctor_id = ?)';

$view = clean_get('view', 20);
if (!in_array($view, ['review', 'upcoming', 'completed', 'declined', 'all'], true)) {
    $view = 'review';
}

// Counts for the tabs and the figures above them.
$st = $pdo->prepare(
    "SELECT SUM(a.status = 'pending') AS review,
            SUM(a.status IN ('confirmed','rescheduled') AND a.appointment_date >= CURRENT_DATE) AS upcoming,
            SUM(a.status = 'completed') AS completed,
            SUM(a.status = 'declined')  AS declined,
            COUNT(*) AS total,
            SUM(a.status IN ('confirmed','rescheduled') AND a.appointment_date = CURRENT_DATE) AS today,
            MIN(CASE WHEN a.status = 'pending' THEN a.created_at END) AS oldest
       FROM appointments a WHERE $scope"
);
$st->execute([$fid, $uid]);
$c = $st->fetch();

$filter = match ($view) {
    'review'    => " AND a.status = 'pending'",
    'upcoming'  => " AND a.status IN ('confirmed','rescheduled') AND a.appointment_date >= CURRENT_DATE",
    'completed' => " AND a.status = 'completed'",
    'declined'  => " AND a.status = 'declined'",
    default     => '',
};
$order = match ($view) {
    'review', 'upcoming' => 'a.appointment_date, a.created_at',
    default              => 'a.appointment_date DESC, a.appointment_id DESC',
};
$found = (int)($view === 'all' ? $c['total'] : $c[$view]);
// Paged so the heaviest list in the system stays inside the page-weight budget.
$per   = 20;
$pages = max(1, (int)ceil($found / $per));
$page  = min(max(1, (int)($_GET['p'] ?? 1)), $pages);

$st = $pdo->prepare(
    "SELECT c.consultation_id, c.clinical_notes, c.doctor_response,
            a.appointment_date, a.department, a.status, a.reason, a.created_at, a.doctor_id,
            p.full_name, p.patient_number, p.dob, p.sex,
            bk.full_name AS booked_by_name, bk.role AS booked_by_role
       FROM consultations c
       JOIN appointments a ON a.appointment_id = c.appointment_id
       JOIN patients     p ON p.patient_id     = a.patient_id
       JOIN users        bk ON bk.user_id      = a.booked_by
      WHERE $scope $filter
   ORDER BY $order
      LIMIT $per OFFSET " . (($page - 1) * $per)
);
$st->execute([$fid, $uid]);
$rows = $st->fetchAll();

// Today's clinic list for the side panel.
$st = $pdo->prepare(
    "SELECT c.consultation_id, a.department, p.full_name
       FROM appointments a JOIN patients p ON p.patient_id = a.patient_id
       JOIN consultations c ON c.appointment_id = a.appointment_id
      WHERE $scope AND a.status IN ('confirmed','rescheduled') AND a.appointment_date = CURRENT_DATE
   ORDER BY a.department, p.full_name LIMIT 10"
);
$st->execute([$fid, $uid]);
$todayList = $st->fetchAll();

// Weekly series and month markers.
$st = $pdo->prepare("SELECT a.appointment_date AS d, a.status AS s FROM appointments a WHERE $scope");
$st->execute([$fid, $uid]);
$weeks = [];
for ($i = 15; $i >= 0; $i--) {
    $weeks[date('o-W', strtotime("-{$i} week"))] = 0;
}
$marked = [];
$prio   = ['pending' => 5, 'rescheduled' => 4, 'confirmed' => 3, 'completed' => 2, 'declined' => 1];
foreach ($st->fetchAll() as $r) {
    $w = date('o-W', strtotime((string)$r['d']));
    if (isset($weeks[$w])) {
        $weeks[$w]++;
    }
    $d = (string)$r['d'];
    if (!isset($marked[$d]) || ($prio[$r['s']] ?? 0) > ($prio[$marked[$d]] ?? 0)) {
        $marked[$d] = (string)$r['s'];
    }
}
$trend = array_values($weeks);

$review = (int)$c['review'];
$page_title   = greeting() . ', ' . first_name($me['full_name']);
$page_sub     = e((string)$me['facility']) . ' · ' . e(date('l j F Y'));
$page_actions = $review
    ? '<a class="btn" href="' . e(url('/doctor/case.php?next=1')) . '">' . icon('arrow') . 'Start reviewing (' . $review . ')</a>'
    : '';
require __DIR__ . '/../includes/header.php';
?>
<div class="metrics">
<?= metric('Waiting for you', (string)$review, 'to review', spark_bars($trend),
      $review ? 'Oldest sent <b>' . e(since((string)$c['oldest'])) . '</b>' : 'Your queue is clear') ?>
<?= metric('Today’s clinic', (string)(int)$c['today'], 'patients', spark_line($trend),
      'Confirmed for ' . e(date('j M'))) ?>
<?= metric('Upcoming', (string)(int)$c['upcoming'], 'visits', spark_bars($trend),
      'Cleared to travel') ?>
<?= metric('Completed', (string)(int)$c['completed'], 'visits', spark_line([0, (int)$c['completed']]),
      'Of <b>' . (int)$c['total'] . '</b> in your queue') ?>
</div>

<div class="grid grid-main">
<section class="card">
  <?= tabs([
        'review'    => ['Needs review', $review],
        'upcoming'  => ['Upcoming', (int)$c['upcoming']],
        'completed' => ['Completed', (int)$c['completed']],
        'declined'  => ['Declined', (int)$c['declined']],
        'all'       => ['All', (int)$c['total']],
      ], $view, 'view') ?>
<?php if (!$rows): ?>
  <?= empty_state($view === 'review' ? 'Nothing waiting. New requests appear here.' : 'No cases in this view.', '', 'check') ?>
<?php else: ?>
  <div class="scroll">
  <table>
    <thead><tr><th>Patient</th><th>Visit</th><th class="hide-sm">Department</th><th class="hide-sm">Sent by</th><th>Status</th><th class="act"></th></tr></thead>
    <tbody>
<?php foreach ($rows as $r): $age = age_from($r['dob']); ?>
    <tr>
      <td><a class="rowlink" href="<?= e(url('/doctor/case.php?id=' . (int)$r['consultation_id'])) ?>"><?= e($r['full_name']) ?></a>
        <small><?= e($r['sex'] ?? '') ?><?= $age !== null ? ($r['sex'] ? ', ' : '') . $age . ' y' : '' ?><?= $r['doctor_id'] ? ' · addressed to you' : '' ?></small></td>
      <td class="nowrap"><?= e(fmt_date($r['appointment_date'], false)) ?><small><?= e(rel_day((string)$r['appointment_date'])) ?></small></td>
      <td class="hide-sm"><?= e($r['department']) ?><small class="trunc"><?= e($r['reason']) ?></small></td>
      <td class="hide-sm nowrap"><?= e($r['booked_by_name']) ?><small><?= e(role_label((string)$r['booked_by_role'])) ?> · <?= e(since((string)$r['created_at'])) ?></small></td>
      <td><?= badge($r['status']) ?></td>
      <td class="act"><a class="btn btn--sm<?= $r['status'] === 'pending' ? '' : ' btn--ghost' ?>" href="<?= e(url('/doctor/case.php?id=' . (int)$r['consultation_id'])) ?>"><?= $r['status'] === 'pending' ? 'Review' : 'Open' ?></a></td>
    </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= pager($page, $pages, ['view' => $view]) ?>
<?php endif; ?>
</section>

<aside>
  <section class="card">
    <header class="card__h"><h2>Today</h2><a class="btn btn--link" href="<?= e(url('/doctor/schedule.php')) ?>">Schedule</a></header>
<?php if (!$todayList): ?>
    <?= empty_state('No confirmed patients today.', '', 'calendar') ?>
<?php else: ?>
    <ul class="agenda">
<?php foreach ($todayList as $t): ?>
      <li><?= avatar($t['full_name']) ?><div class="agenda__b"><b><a class="rowlink" href="<?= e(url('/doctor/case.php?id=' . (int)$t['consultation_id'])) ?>"><?= e($t['full_name']) ?></a></b><span><?= e($t['department']) ?></span></div><span></span></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </section>
  <?= panel_open('Appointment days') ?>
    <?= mini_cal(date('Y-m-01'), $marked) ?>
  <?= panel_close() ?>
</aside>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
