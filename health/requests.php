<?php
/**
 * health/requests.php -- every appointment request at this health centre,
 * made by a health worker or by a patient, with the doctor's reply.
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
        flash('ok', 'Request withdrawn. The doctor and the patient can see this.');
    }
    redirect('/health/requests.php?' . http_build_query(array_filter(['status' => $_POST['status'] ?? '', 'q' => $_POST['q'] ?? ''])));
}

$status = clean_get('status', 20);
if (!in_array($status, statuses(), true)) {
    $status = '';
}
$q = clean_get('q', 60);

$st = $pdo->prepare('SELECT status, COUNT(*) AS c FROM appointments WHERE facility_id = ? GROUP BY status');
$st->execute([$fac]);
$counts = array_fill_keys(statuses(), 0);
foreach ($st->fetchAll() as $row) {
    $counts[$row['status']] = (int)$row['c'];
}

$where = ['a.facility_id = ?'];
$args  = [$fac];
if ($status !== '') {
    $where[] = 'a.status = ?';
    $args[]  = $status;
}
if ($q !== '') {
    $where[] = '(p.full_name LIKE ? OR p.patient_number LIKE ?)';
    array_push($args, "%$q%", "%$q%");
}
$w = implode(' AND ', $where);

$st = $pdo->prepare("SELECT COUNT(*) FROM appointments a JOIN patients p ON p.patient_id = a.patient_id WHERE $w");
$st->execute($args);
$found = (int)$st->fetchColumn();
// Paged: an unbounded list would grow past the page-weight budget the
// project sets for 2G and 3G links as the centre accumulates records.
$per   = 25;
$pages = max(1, (int)ceil($found / $per));
$page  = min(max(1, (int)($_GET['p'] ?? 1)), $pages);

$st = $pdo->prepare(
    "SELECT a.appointment_id, a.department, a.appointment_date, a.status, a.reason, a.created_at,
            p.patient_id, p.full_name, p.patient_number,
            COALESCE(ans.full_name, doc.full_name) AS doctor,
            c.clinical_notes, c.doctor_response, c.responded_at,
            bk.full_name AS booked_by_name, bk.role AS booked_by_role
       FROM appointments a
       JOIN patients p    ON p.patient_id = a.patient_id
       JOIN users    bk   ON bk.user_id   = a.booked_by
  LEFT JOIN consultations c ON c.appointment_id = a.appointment_id
  LEFT JOIN users ans       ON ans.user_id = c.answered_by
  LEFT JOIN users doc       ON doc.user_id = a.doctor_id
      WHERE $w
   ORDER BY FIELD(a.status, 'pending','rescheduled','confirmed','completed','declined'), a.appointment_date DESC, a.appointment_id DESC
      LIMIT $per OFFSET " . (($page - 1) * $per)
);
$st->execute($args);
$rows = $st->fetchAll();

$keep = array_filter(['status' => $status, 'q' => $q]);
$page_title   = 'Requests';
$page_sub     = e((string)$me['facility']);
$page_actions = '<a class="btn" href="' . e(url('/health/new_appointment.php')) . '">' . icon('send') . 'New request</a>';
require __DIR__ . '/../includes/header.php';
?>
<section class="card">
  <?= tabs([
        ''            => ['All', array_sum($counts)],
        'pending'     => ['Waiting on doctor', $counts['pending']],
        'confirmed'   => ['Confirmed', $counts['confirmed']],
        'rescheduled' => ['Rescheduled', $counts['rescheduled']],
        'completed'   => ['Completed', $counts['completed']],
        'declined'    => ['Declined', $counts['declined']],
      ], $status, 'status', array_filter(['q' => $q])) ?>

  <form method="get" class="toolbar">
<?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <div class="search grow"><?= icon('search') ?>
      <input name="q" value="<?= e($q) ?>" placeholder="Search by patient name or number" aria-label="Search requests" maxlength="60">
    </div>
    <button type="submit" class="btn--ghost">Search</button>
<?php if ($q !== ''): ?><a class="btn btn--link" href="?<?= e(http_build_query(array_filter(['status' => $status]))) ?>">Clear</a><?php endif; ?>
  </form>

<?php if (!$rows): ?>
  <?= empty_state($q !== '' ? 'No requests match that patient.' : 'No requests here yet.',
        '<a class="btn btn--sm" href="' . e(url('/health/new_appointment.php')) . '">' . icon('send') . 'New request</a>') ?>
<?php else: ?>
  <div class="scroll">
  <table>
    <thead><tr><th>Patient</th><th>Status</th><th>Visit date</th><th class="hide-sm">Department</th><th class="hide-sm">Doctor</th><th>Reply</th><th class="act"></th></tr></thead>
    <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <td><a class="rowlink" href="<?= e(url('/health/patient.php?id=' . (int)$r['patient_id'])) ?>"><?= e($r['full_name']) ?></a><small class="mono"><?= e($r['patient_number']) ?></small></td>
      <td><?= badge($r['status']) ?></td>
      <td class="nowrap"><?= e(fmt_date($r['appointment_date'])) ?><small><?= e(rel_day((string)$r['appointment_date'])) ?></small></td>
      <td class="hide-sm"><?= e($r['department']) ?></td>
      <td class="hide-sm"><?= e($r['doctor'] ?? t('any_doctor')) ?></td>
      <td>
        <?= $r['doctor_response'] !== null ? '<div class="trunc">' . e($r['doctor_response']) . '</div>' : '<span class="muted">Waiting</span>' ?>
        <details>
          <summary>Details</summary>
          <p class="note-h">Reason</p><p class="note"><?= e($r['reason']) ?></p>
<?php if ($r['clinical_notes']): ?>
          <p class="note-h">Clinical notes</p><p class="note"><?= e($r['clinical_notes']) ?></p>
<?php endif; ?>
          <p class="hint">Sent by <?= e($r['booked_by_name']) ?> (<?= e(role_label((string)$r['booked_by_role'])) ?>), <?= e(fmt_datetime($r['created_at'])) ?></p>
        </details>
      </td>
      <td class="act">
<?php if (in_array($r['status'], ['pending', 'confirmed', 'rescheduled'], true)): ?>
        <form method="post" class="inline" data-confirm="Withdraw the request for <?= e($r['full_name']) ?>?">
          <?= csrf_field() ?>
          <input type="hidden" name="appointment_id" value="<?= (int)$r['appointment_id'] ?>">
          <input type="hidden" name="status" value="<?= e($status) ?>">
          <input type="hidden" name="q" value="<?= e($q) ?>">
          <button type="submit" class="btn--danger btn--sm"><?= e(t('withdraw')) ?></button>
        </form>
<?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= pager($page, $pages, $keep) ?>
<?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
