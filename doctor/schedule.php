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
        flash('ok', 'Visit marked completed.');
    }
    redirect('/doctor/schedule.php?' . http_build_query(['from' => $_POST['from'] ?? '', 'to' => $_POST['to'] ?? '']));
}

$span = clean_get('span', 5);
if (!in_array($span, ['7', '14', '30'], true)) {
    $span = '14';
}
$from = clean_get('from', 10);
$to   = clean_get('to', 10);
$fromSql = valid_date($from) ? $from : today();
$toSql   = valid_date($to)   ? $to   : date('Y-m-d', strtotime('+' . $span . ' days'));
if ($toSql < $fromSql) {
    [$fromSql, $toSql] = [$toSql, $fromSql];
}

$st = $pdo->prepare(
    "SELECT a.appointment_id, a.appointment_date, a.department, a.status, a.reason,
            p.full_name, p.patient_number, p.sex, p.dob, p.phone,
            c.clinical_notes, c.consultation_id
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

$page_title   = 'Schedule';
$page_sub     = count($rows) . ' confirmed ' . (count($rows) === 1 ? 'visit' : 'visits') . ', ' . e(fmt_date($fromSql, false)) . ' – ' . e(fmt_date($toSql));
$page_actions = '<button type="button" class="btn--ghost" data-print="1">' . icon('printer') . 'Print clinic list</button>';
require __DIR__ . '/../includes/header.php';
?>
<section class="card">
  <?= tabs(['7' => ['Next 7 days', null], '14' => ['Next 14 days', null], '30' => ['Next 30 days', null]],
           valid_date($from) || valid_date($to) ? '' : $span, 'span') ?>
  <form method="get" class="toolbar noprint">
    <input type="date" name="from" value="<?= e($fromSql) ?>" aria-label="From date">
    <span class="muted">to</span>
    <input type="date" name="to" value="<?= e($toSql) ?>" aria-label="To date">
    <button type="submit" class="btn--ghost">Show</button>
  </form>

<?php if (!$byDay): ?>
  <?= empty_state('No confirmed visits in this period.', '<a class="btn btn--sm btn--ghost" href="' . e(url('/doctor/dashboard.php')) . '">Open review queue</a>', 'calendar') ?>
<?php endif; ?>

<?php foreach ($byDay as $day => $list): ?>
  <div class="daygroup<?= $day === today() ? ' is-today' : '' ?>"><span><?= e(date('l j F', strtotime($day))) ?> · <?= e(rel_day($day)) ?></span><span><?= count($list) ?> patient<?= count($list) === 1 ? '' : 's' ?></span></div>
  <ul class="agenda">
<?php foreach ($list as $r): $age = age_from($r['dob']); ?>
    <li>
      <?= avatar($r['full_name']) ?>
      <div class="agenda__b">
        <b><a class="rowlink" href="<?= e(url('/doctor/case.php?id=' . (int)$r['consultation_id'])) ?>"><?= e($r['full_name']) ?></a>
          <span class="muted"> · <?= e($r['department']) ?></span></b>
        <span><?= e($r['reason']) ?><?= $r['sex'] ? ' · ' . e($r['sex']) : '' ?><?= $age !== null ? ', ' . $age . ' y' : '' ?><?= $r['phone'] ? ' · ' . e($r['phone']) : '' ?></span>
      </div>
      <form method="post" class="inline noprint" data-confirm="Mark <?= e($r['full_name']) ?> as seen?">
        <?= csrf_field() ?>
        <input type="hidden" name="appointment_id" value="<?= (int)$r['appointment_id'] ?>">
        <input type="hidden" name="from" value="<?= e($fromSql) ?>">
        <input type="hidden" name="to" value="<?= e($toSql) ?>">
        <button type="submit" class="btn--ghost btn--sm"><?= icon('check') ?>Seen</button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
<?php endforeach; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
