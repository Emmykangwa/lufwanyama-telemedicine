<?php
/**
 * health/dashboard.php -- every appointment request for this health centre,
 * made by a health worker or by a patient, and the doctor's reply.
 *
 * Data isolation: filtered on a.facility_id, so a worker sees their own
 * centre's requests and nothing from any other centre.
 */
require_once __DIR__ . '/../includes/init.php';
$me  = require_role(['health_worker']);
$fac = (int)$me['facility_id'];

// ---- withdraw a request this centre no longer needs ------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $aid = (int)($_POST['appointment_id'] ?? 0);

    $st = $pdo->prepare(
        "SELECT status FROM appointments
          WHERE appointment_id = ? AND facility_id = ?
            AND status IN ('pending','confirmed','rescheduled')"
    );
    $st->execute([$aid, $fac]);

    if (!$st->fetchColumn()) {
        flash('err', 'That appointment cannot be withdrawn.');
    } else {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE appointments SET status = 'declined' WHERE appointment_id = ?")->execute([$aid]);
        $pdo->prepare(
            "UPDATE consultations
                SET doctor_response = ?, answered_by = ?, responded_at = NOW()
              WHERE appointment_id = ?"
        )->execute(['Withdrawn by the health centre.', $me['user_id'], $aid]);
        audit($pdo, 'WITHDRAW', 'appointment', $aid, 'Withdrawn by facility ' . $fac);
        $pdo->commit();
        flash('ok', 'The request was withdrawn. The doctor and the patient can see this.');
    }
    redirect('/health/dashboard.php');
}

// ---- filter ----------------------------------------------------------
$status = (string)($_GET['status'] ?? '');
$sql = "SELECT a.appointment_id, a.department, a.appointment_date, a.status, a.reason, a.created_at,
               p.full_name, p.patient_number,
               COALESCE(ans.full_name, doc.full_name) AS doctor,
               c.clinical_notes, c.doctor_response,
               bk.full_name AS booked_by_name, bk.role AS booked_by_role
          FROM appointments a
          JOIN patients p    ON p.patient_id = a.patient_id
          JOIN users    bk   ON bk.user_id   = a.booked_by
     LEFT JOIN consultations c ON c.appointment_id = a.appointment_id
     LEFT JOIN users ans       ON ans.user_id = c.answered_by
     LEFT JOIN users doc       ON doc.user_id = a.doctor_id
         WHERE a.facility_id = ?";
$args = [$fac];
if (in_array($status, statuses(), true)) {
    $sql   .= ' AND a.status = ?';
    $args[] = $status;
}
// Paged: an unbounded list would grow past the page-weight budget the
// project sets for 2G and 3G links as the centre accumulates records.
$per   = 25;
$page  = max(1, (int)($_GET['p'] ?? 1));
$cntSql = preg_replace('/^SELECT .*? FROM /s', 'SELECT COUNT(*) FROM ', $sql, 1);
$st = $pdo->prepare($cntSql);
$st->execute($args);
$found = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($found / $per));
$page  = min($page, $pages);

$sql .= ' ORDER BY a.appointment_date DESC, a.appointment_id DESC LIMIT '
      . $per . ' OFFSET ' . (($page - 1) * $per);

$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

// Small counters for the tiles
$st = $pdo->prepare('SELECT status, COUNT(*) AS c FROM appointments WHERE facility_id = ? GROUP BY status');
$st->execute([$fac]);
$counts = [];
foreach ($st->fetchAll() as $row) {
    $counts[$row['status']] = (int)$row['c'];
}

// Daily volume at this centre, for the trend graphics.
$st = $pdo->prepare("SELECT appointment_date AS k, COUNT(*) AS c FROM appointments
                      WHERE facility_id = ? GROUP BY k ORDER BY k");
$st->execute([$fac]);
$byDay  = $st->fetchAll();
$trend  = array_map(static fn(array $r): int => (int)$r['c'], $byDay) ?: [0, 0];

// Built from every row at this centre, not the page being shown, so the
// month grid does not change as the user pages through the list.
$st = $pdo->prepare('SELECT appointment_date AS k, status AS s FROM appointments
                      WHERE facility_id = ?');
$st->execute([$fac]);
$marked = [];
$prio   = ['pending' => 5, 'rescheduled' => 4, 'confirmed' => 3, 'completed' => 2, 'declined' => 1];
foreach ($st->fetchAll() as $r) {
    $d = (string)$r['k'];
    $s = (string)$r['s'];
    if (!isset($marked[$d]) || ($prio[$s] ?? 0) > ($prio[$marked[$d]] ?? 0)) {
        $marked[$d] = $s;
    }
}

$totalAll = array_sum($counts);
$open     = (int)($counts['pending'] ?? 0);
$ready    = (int)($counts['confirmed'] ?? 0) + (int)($counts['rescheduled'] ?? 0);
$statusRows = [];
foreach (statuses() as $s) {
    if ((int)($counts[$s] ?? 0) > 0) {
        $statusRows[] = ['k' => $s, 'c' => (int)$counts[$s]];
    }
}
// the soonest day that still needs a doctor's reply
$nextPending = null;
foreach (array_reverse($rows) as $r) {
    if ($r['status'] === 'pending' && $r['appointment_date'] >= today()) {
        $nextPending = $r;
        break;
    }
}

$page_title = t('dashboard');
require __DIR__ . '/../includes/header.php';
?>
<div class="metrics">
<?= metric('All requests', (string)$totalAll, $totalAll === 1 ? 'request' : 'requests',
      spark_bars($trend),
      'Everything raised at this health centre') ?>

<?= metric('Awaiting a doctor', (string)$open, 'pending',
      spark_line($trend),
      $open > 0
        ? ($nextPending
            ? 'Soonest is <b>' . e(date('j M', strtotime((string)$nextPending['appointment_date']))) . '</b>'
            : 'Waiting on a doctor&rsquo;s review')
        : 'Every request has had a reply') ?>

<?= metric('Patient may travel', (string)$ready, 'confirmed',
      spark_bars(array_map(static fn(string $s): int => (int)($counts[$s] ?? 0), statuses())),
      'Confirmed or rescheduled by a doctor') ?>

<?= metric('Seen', (string)(int)($counts['completed'] ?? 0), 'completed',
      spark_line([0, (int)($counts['completed'] ?? 0)]),
      'Visits already carried out') ?>
</div>

<p class="noprint actions">
  <a class="btn" href="<?= e(url('/health/new_appointment.php')) ?>"><?= icon('send') ?><?= e(t('new_appointment')) ?></a>
  <a class="btn alt" href="<?= e(url('/health/add_patient.php')) ?>"><?= icon('userplus') ?><?= e(t('add_patient')) ?></a>
  <a class="btn alt" href="<?= e(url('/health/patients.php')) ?>"><?= icon('users') ?><?= e(t('patients')) ?></a>
</p>

<div class="cols cols--even">
<?php if ($statusRows): ?>
  <?= panel_open('Where requests stand') ?>
    <?= status_split($statusRows) ?>
  <?= panel_close() ?>
<?php endif; ?>
  <?= panel_open('Appointment days') ?>
    <?= mini_cal(date('Y-m-01'), $marked) ?>
  <?= panel_close() ?>
</div>

<form method="get" class="card noprint row">
  <div>
    <label for="status"><?= e(t('status')) ?></label>
    <select id="status" name="status">
      <option value="">All</option>
<?php foreach (statuses() as $s): ?>
      <option value="<?= e($s) ?>"<?= $status === $s ? ' selected' : '' ?>><?= e(t($s)) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div><button type="submit"><?= icon('search') ?><?= e(t('apply')) ?></button></div>
</form>

<div class="scroll">
<table>
  <caption>Showing <?= count($rows) ?> of <?= $found ?> request(s) at this health centre<?= $pages > 1 ? ' — page ' . $page . ' of ' . $pages : '' ?>.</caption>
  <tr>
    <th><?= e(t('patient')) ?></th><th><?= e(t('status')) ?></th><th><?= e(t('date')) ?></th>
    <th><?= e(t('doctor')) ?></th><th><?= e(t('department')) ?></th><th><?= e(t('response')) ?></th><th></th>
  </tr>
<?php if (!$rows): ?>
  <tr><td colspan="7"><?= e(t('no_records')) ?></td></tr>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
  <tr>
    <td><?= e($r['full_name']) ?><br><small><?= e($r['patient_number']) ?></small></td>
    <td><?= badge($r['status']) ?></td>
    <td><?= e($r['appointment_date']) ?></td>
    <td><?= e($r['doctor'] ?? t('any_doctor')) ?></td>
    <td><?= e($r['department']) ?></td>
    <td>
      <?= $r['doctor_response'] !== null ? e($r['doctor_response']) : '<span class="hint">waiting</span>' ?>
      <details>
        <summary>Details</summary>
        <p><b><?= e(t('reason')) ?>:</b> <?= e($r['reason']) ?></p>
        <?php if ($r['clinical_notes']): ?>
          <p><b><?= e(t('notes')) ?>:</b> <?= nl2br(e($r['clinical_notes'])) ?></p>
        <?php endif; ?>
        <p class="hint">Requested by <?= e($r['booked_by_name']) ?>
          (<?= e(str_replace('_', ' ', (string)$r['booked_by_role'])) ?>)
          on <?= e(substr((string)$r['created_at'], 0, 16)) ?></p>
      </details>
    </td>
    <td class="noprint">
<?php if (in_array($r['status'], ['pending', 'confirmed', 'rescheduled'], true)): ?>
      <form method="post" class="inline" data-confirm="Withdraw this request?">
        <?= csrf_field() ?>
        <input type="hidden" name="appointment_id" value="<?= (int)$r['appointment_id'] ?>">
        <button type="submit" class="warn mini"><?= e(t('withdraw')) ?></button>
      </form>
<?php endif; ?>
    </td>
  </tr>
<?php endforeach; ?>
</table>
</div>
<?= pager($page, $pages, array_filter(['status' => $status])) ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
