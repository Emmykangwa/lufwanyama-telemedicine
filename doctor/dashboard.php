<?php
/**
 * doctor/dashboard.php -- appointment requests for the doctor's own health
 * centre, with the doctor's reply (FR4): confirm, reschedule, decline, complete.
 *
 * The doctor works inside the centre. They see requests for their centre that
 * are addressed to them OR to "any doctor" (doctor_id IS NULL). Every read and
 * every write is scoped the same way, so a forged id cannot touch another
 * centre's or another doctor's request.
 */
require_once __DIR__ . '/../includes/init.php';
$me  = require_role(['doctor']);
$fid = (int)$me['facility_id'];
$uid = (int)$me['user_id'];

const ACTIONS = [
    'confirm'    => 'confirmed',
    'reschedule' => 'rescheduled',
    'decline'    => 'declined',
    'complete'   => 'completed',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $cid    = (int)($_POST['consultation_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $newdt  = clean('new_date', 10);
    $reply  = clean('response', 255);

    // The request must be at this doctor's centre and be theirs or "any doctor".
    $st = $pdo->prepare(
        'SELECT a.appointment_id, a.status
           FROM consultations c
           JOIN appointments a ON a.appointment_id = c.appointment_id
          WHERE c.consultation_id = ? AND a.facility_id = ?
            AND (a.doctor_id IS NULL OR a.doctor_id = ?)'
    );
    $st->execute([$cid, $fid, $uid]);
    $appt = $st->fetch();

    if (!$appt || !isset(ACTIONS[$action])) {
        flash('err', 'That request is not yours to answer, or the action was not recognised.');
    } elseif ($appt['status'] === 'completed') {
        flash('err', 'That appointment is already completed and cannot be changed.');
    } elseif ($action === 'reschedule' && (!valid_date($newdt) || $newdt < today())) {
        flash('err', 'To reschedule, pick a new date that is today or later.');
    } else {
        $aid = (int)$appt['appointment_id'];
        try {
            $pdo->beginTransaction();

            if ($action === 'reschedule') {
                $pdo->prepare('UPDATE appointments SET status = ?, appointment_date = ? WHERE appointment_id = ?')
                    ->execute([ACTIONS[$action], $newdt, $aid]);
            } else {
                $pdo->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ?')
                    ->execute([ACTIONS[$action], $aid]);
            }

            // Record the doctor who answered, so "any doctor" requests show who took them.
            $pdo->prepare(
                'UPDATE consultations
                    SET doctor_response = ?, answered_by = ?, responded_at = NOW()
                  WHERE consultation_id = ?'
            )->execute([$reply !== '' ? $reply : null, $uid, $cid]);

            audit($pdo, strtoupper($action), 'appointment', $aid,
                $appt['status'] . ' -> ' . ACTIONS[$action] . ($action === 'reschedule' ? ', new date ' . $newdt : ''));

            $pdo->commit();
            flash('ok', 'Response saved. The health centre and the patient can see it now.');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Doctor response failed: ' . $ex->getMessage());
            flash('err', 'Could not save the response. Please try again.');
        }
    }
    redirect('/doctor/dashboard.php');
}

// ---- the queue -------------------------------------------------------
$view = clean_get('view', 20);
if (!in_array($view, array_merge(['open', 'all'], statuses()), true)) {
    $view = 'open';
}

$sql = "SELECT c.consultation_id, c.clinical_notes, c.doctor_response,
               a.appointment_id, a.department, a.appointment_date, a.status, a.reason, a.created_at,
               a.doctor_id,
               p.full_name, p.patient_number, p.dob, p.sex, p.village, p.phone,
               bk.full_name AS booked_by_name, bk.role AS booked_by_role
          FROM consultations c
          JOIN appointments a ON a.appointment_id = c.appointment_id
          JOIN patients     p ON p.patient_id     = a.patient_id
          JOIN users        bk ON bk.user_id      = a.booked_by
         WHERE a.facility_id = ? AND (a.doctor_id IS NULL OR a.doctor_id = ?)";
$args = [$fid, $uid];

if (in_array($view, statuses(), true)) {
    $sql   .= ' AND a.status = ?';
    $args[] = $view;
} elseif ($view === 'open') {
    $sql .= " AND a.status IN ('pending','confirmed','rescheduled')";
}

// Paged: each row renders as a full case card, so an unbounded list is the
// heaviest page in the system and would pass the project's page-weight
// budget for 2G and 3G links.
$per   = 15;
$page  = max(1, (int)($_GET['p'] ?? 1));
$cntSql = preg_replace('/^SELECT .*? FROM /s', 'SELECT COUNT(*) FROM ', $sql, 1);
$st = $pdo->prepare($cntSql);
$st->execute($args);
$found = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($found / $per));
$page  = min($page, $pages);

$sql .= " ORDER BY FIELD(a.status, 'pending','rescheduled','confirmed','declined','completed'),
                   a.appointment_date, a.appointment_id
          LIMIT " . $per . ' OFFSET ' . (($page - 1) * $per);

$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

// Queue summary across everything this doctor can see, independent of the
// filter above, so the figures do not move when the view is narrowed.
$st = $pdo->prepare(
    "SELECT a.status AS k, COUNT(*) AS c
       FROM appointments a
      WHERE a.facility_id = ? AND (a.doctor_id = ? OR a.doctor_id IS NULL)
      GROUP BY a.status"
);
$st->execute([(int)$me['facility_id'], (int)$me['user_id']]);
$counts = [];
foreach ($st->fetchAll() as $r) {
    $counts[(string)$r['k']] = (int)$r['c'];
}
$queue   = (int)($counts['pending'] ?? 0);
$allSeen = array_sum($counts);

// A week-by-week series, so the graphic shows a trend rather than five
// fat blocks of status totals.
$st = $pdo->prepare(
    "SELECT a.appointment_date AS d FROM appointments a
      WHERE a.facility_id = ? AND (a.doctor_id = ? OR a.doctor_id IS NULL)"
);
$st->execute([(int)$me['facility_id'], (int)$me['user_id']]);
$weeks = [];
for ($i = 15; $i >= 0; $i--) {
    $weeks[date('o-W', strtotime("-{$i} week"))] = 0;
}
foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) {
    $w = date('o-W', strtotime((string)$d));
    if (isset($weeks[$w])) {
        $weeks[$w]++;
    }
}
$trend = array_values($weeks);

// Built from the doctor's whole queue, not the page on screen, so the month
// grid stays still while they page through cases.
$st = $pdo->prepare(
    "SELECT a.appointment_date AS k, a.status AS s FROM appointments a
      WHERE a.facility_id = ? AND (a.doctor_id = ? OR a.doctor_id IS NULL)"
);
$st->execute([(int)$me['facility_id'], (int)$me['user_id']]);
$marked = [];
$prio   = ['pending' => 5, 'rescheduled' => 4, 'confirmed' => 3, 'completed' => 2, 'declined' => 1];
foreach ($st->fetchAll() as $r) {
    $d = (string)$r['k'];
    $s = (string)$r['s'];
    if (!isset($marked[$d]) || ($prio[$s] ?? 0) > ($prio[$marked[$d]] ?? 0)) {
        $marked[$d] = $s;
    }
}
$statusRows = [];
foreach (statuses() as $s) {
    if ((int)($counts[$s] ?? 0) > 0) {
        $statusRows[] = ['k' => $s, 'c' => (int)$counts[$s]];
    }
}

$page_title = t('incoming');
require __DIR__ . '/../includes/header.php';
?>
<div class="metrics">
<?= metric('Waiting for you', (string)$queue, 'to review',
      spark_bars($trend),
      $queue > 0 ? 'Review these so patients know whether to travel'
                 : 'Your queue is clear') ?>

<?= metric('Confirmed by you',
      (string)((int)($counts['confirmed'] ?? 0) + (int)($counts['rescheduled'] ?? 0)), 'visits',
      spark_line($trend),
      'Patients cleared to travel to the centre') ?>

<?= metric('Completed', (string)(int)($counts['completed'] ?? 0), 'visits',
      spark_line([0, (int)($counts['completed'] ?? 0)]),
      'Consultations already carried out') ?>

<?= metric('All in your queue', (string)$allSeen, $allSeen === 1 ? 'request' : 'requests',
      spark_bars($trend),
      'Everything at your centre assigned to you or unassigned') ?>
</div>

<div class="cols cols--even">
<?php if ($statusRows): ?>
  <?= panel_open('Queue breakdown') ?>
    <?= status_split($statusRows) ?>
  <?= panel_close() ?>
<?php endif; ?>
  <?= panel_open('Days in view',
        '<a class="btn alt mini noprint" href="' . e(url('/doctor/schedule.php')) . '">'
        . icon('calendar') . e(t('my_schedule')) . '</a>') ?>
    <?= mini_cal(date('Y-m-01'), $marked) ?>
  <?= panel_close() ?>
</div>

<form method="get" class="card noprint row">
  <div>
    <label for="view">Show</label>
    <select id="view" name="view">
      <option value="open"<?= $view === 'open' ? ' selected' : '' ?>>Open requests</option>
      <option value="all"<?= $view === 'all' ? ' selected' : '' ?>>All</option>
<?php foreach (statuses() as $s): ?>
      <option value="<?= e($s) ?>"<?= $view === $s ? ' selected' : '' ?>><?= e(t($s)) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div><button type="submit"><?= icon('search') ?><?= e(t('apply')) ?></button></div>
</form>

<?php if (!$rows): ?>
  <div class="card"><?= e(t('no_records')) ?></div>
<?php else: ?>
  <p class="hint">Showing <?= count($rows) ?> of <?= $found ?> case(s)<?= $pages > 1 ? ' — page ' . $page . ' of ' . $pages : '' ?>.</p>
<?php endif; ?>

<?php foreach ($rows as $r): ?>
<div class="card<?= $r['status'] === 'pending' ? ' pending' : '' ?>">
  <h2><?= e($r['full_name']) ?> <?= badge($r['status']) ?></h2>
  <p class="hint">
    <?= e($r['patient_number']) ?>
    <?= $r['sex'] ? ' &middot; ' . e($r['sex']) : '' ?>
    <?= $r['dob'] ? ' &middot; born ' . e($r['dob']) : '' ?>
    <?= $r['village'] ? ' &middot; ' . e($r['village']) : '' ?>
    <?= $r['phone'] ? ' &middot; ' . e($r['phone']) : '' ?>
  </p>
  <p>
    <b><?= e(t('date')) ?>:</b> <?= e($r['appointment_date']) ?> &middot;
    <b><?= e(t('department')) ?>:</b> <?= e($r['department']) ?> &middot;
    <b><?= e(t('doctor')) ?>:</b> <?= $r['doctor_id'] ? 'you' : e(t('any_doctor')) ?><br>
    <b>Requested by:</b> <?= e((string)$r['booked_by_name']) ?>
      (<?= e(str_replace('_', ' ', (string)$r['booked_by_role'])) ?>)
    <small class="hint">&middot; received <?= e(substr((string)$r['created_at'], 0, 16)) ?></small>
  </p>
  <p><b><?= e(t('reason')) ?>:</b> <?= e($r['reason']) ?></p>
  <p><b><?= e(t('notes')) ?>:</b> <?= $r['clinical_notes'] ? nl2br(e($r['clinical_notes'])) : '<span class="hint">none given</span>' ?></p>
<?php if ($r['doctor_response'] !== null): ?>
  <p><b><?= e(t('response')) ?>:</b> <?= e($r['doctor_response']) ?></p>
<?php endif; ?>

<?php if ($r['status'] !== 'completed'): ?>
  <details class="noprint">
    <summary><?= icon('edit') ?><?= e(t('respond')) ?></summary>
    <form method="post" data-offline="respond-<?= (int)$r['consultation_id'] ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="consultation_id" value="<?= (int)$r['consultation_id'] ?>">

      <label for="a<?= (int)$r['consultation_id'] ?>"><?= e(t('action')) ?></label>
      <select id="a<?= (int)$r['consultation_id'] ?>" name="action"
              data-toggle-date="d<?= (int)$r['consultation_id'] ?>">
        <option value="confirm">Confirm this date</option>
        <option value="reschedule">Reschedule</option>
        <option value="decline">Decline</option>
        <option value="complete">Mark completed</option>
      </select>

      <div id="d<?= (int)$r['consultation_id'] ?>" hidden>
        <label for="nd<?= (int)$r['consultation_id'] ?>"><?= e(t('new_date')) ?></label>
        <input id="nd<?= (int)$r['consultation_id'] ?>" name="new_date" type="date" min="<?= e(today()) ?>">
      </div>

      <label for="r<?= (int)$r['consultation_id'] ?>"><?= e(t('response')) ?></label>
      <input id="r<?= (int)$r['consultation_id'] ?>" name="response" maxlength="255"
             placeholder="e.g. Come fasting, bring the child health card">

      <button type="submit"><?= e(t('save')) ?></button>
    </form>
  </details>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?= pager($page, $pages, array_filter(['view' => $view])) ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
