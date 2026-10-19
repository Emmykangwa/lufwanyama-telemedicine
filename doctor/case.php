<?php
/**
 * doctor/case.php -- review one request and reply (FR4): confirm, propose a
 * new date, decline, or mark the visit completed.
 *
 * Every read and write is scoped to the doctor's centre and to requests
 * addressed to them or to "any doctor", so a forged id cannot touch another
 * centre's or another doctor's case. After a reply the doctor is taken to the
 * next waiting case, so a queue is worked through without returning to the list.
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

/** The next pending case in this doctor's queue, oldest visit date first. */
function next_case(PDO $pdo, int $fid, int $uid, int $exclude = 0): ?int
{
    $st = $pdo->prepare(
        "SELECT c.consultation_id FROM consultations c JOIN appointments a ON a.appointment_id = c.appointment_id
          WHERE a.facility_id = ? AND (a.doctor_id IS NULL OR a.doctor_id = ?) AND a.status = 'pending'
            AND c.consultation_id <> ?
       ORDER BY a.appointment_date, a.created_at LIMIT 1"
    );
    $st->execute([$fid, $uid, $exclude]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

if (isset($_GET['next'])) {
    $n = next_case($pdo, $fid, $uid);
    if ($n === null) {
        flash('ok', 'Your review queue is clear.');
        redirect('/doctor/dashboard.php');
    }
    redirect('/doctor/case.php?id=' . $n);
}

$cid = (int)($_GET['id'] ?? $_POST['consultation_id'] ?? 0);
$st = $pdo->prepare(
    "SELECT c.consultation_id, c.clinical_notes, c.doctor_response, c.responded_at,
            a.appointment_id, a.patient_id, a.department, a.appointment_date, a.status, a.reason, a.created_at, a.doctor_id,
            p.full_name, p.patient_number, p.dob, p.sex, p.village, p.phone,
            bk.full_name AS booked_by_name, bk.role AS booked_by_role,
            ans.full_name AS answered_by_name
       FROM consultations c
       JOIN appointments a ON a.appointment_id = c.appointment_id
       JOIN patients     p ON p.patient_id     = a.patient_id
       JOIN users        bk ON bk.user_id      = a.booked_by
  LEFT JOIN users       ans ON ans.user_id     = c.answered_by
      WHERE c.consultation_id = ? AND a.facility_id = ? AND (a.doctor_id IS NULL OR a.doctor_id = ?)"
);
$st->execute([$cid, $fid, $uid]);
$r = $st->fetch();
if (!$r) {
    flash('err', 'That request is not in your queue.');
    redirect('/doctor/dashboard.php');
}

$err = '';
$old = ['action' => $r['status'] === 'pending' ? 'confirm' : 'complete', 'new_date' => '', 'response' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $old['action']   = (string)($_POST['action'] ?? '');
    $old['new_date'] = clean('new_date', 10);
    $old['response'] = clean('response', 255);

    if (!isset(ACTIONS[$old['action']])) {
        $err = 'Choose what to do with this request.';
    } elseif ($r['status'] === 'completed') {
        $err = 'This visit is already completed and cannot be changed.';
    } elseif ($old['action'] === 'reschedule' && (!valid_date($old['new_date']) || $old['new_date'] < today())) {
        $err = 'Pick the new date (today or later).';
    } elseif ($old['action'] === 'decline' && mb_strlen($old['response']) < 5) {
        $err = 'Say why the request is declined, so the patient knows what to do instead.';
    } else {
        $aid = (int)$r['appointment_id'];
        try {
            $pdo->beginTransaction();
            if ($old['action'] === 'reschedule') {
                $pdo->prepare('UPDATE appointments SET status = ?, appointment_date = ? WHERE appointment_id = ?')
                    ->execute([ACTIONS['reschedule'], $old['new_date'], $aid]);
            } else {
                $pdo->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ?')
                    ->execute([ACTIONS[$old['action']], $aid]);
            }
            // Record the doctor who answered, so "any doctor" requests show who took them.
            $pdo->prepare(
                'UPDATE consultations
                    SET doctor_response = COALESCE(?, doctor_response), answered_by = ?, responded_at = NOW()
                  WHERE consultation_id = ?'
            )->execute([$old['response'] !== '' ? $old['response'] : null, $uid, $cid]);

            audit($pdo, strtoupper($old['action']), 'appointment', $aid,
                $r['status'] . ' -> ' . ACTIONS[$old['action']] . ($old['action'] === 'reschedule' ? ', new date ' . $old['new_date'] : ''));
            $pdo->commit();

            $next = $r['status'] === 'pending' ? next_case($pdo, $fid, $uid, $cid) : null;
            if ($next !== null) {
                flash('ok', 'Reply saved for ' . $r['full_name'] . '. Here is the next request.');
                redirect('/doctor/case.php?id=' . $next);
            }
            flash('ok', 'Reply saved for ' . $r['full_name'] . '.' . ($r['status'] === 'pending' ? ' Your review queue is clear.' : ''));
            redirect('/doctor/dashboard.php');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Doctor response failed: ' . $ex->getMessage());
            $err = 'Could not save the reply. Nothing was changed.';
        }
    }
}

// The patient's earlier requests at this centre: context for the decision.
$st = $pdo->prepare(
    "SELECT a.appointment_date, a.department, a.status, a.reason, c.doctor_response
       FROM appointments a LEFT JOIN consultations c ON c.appointment_id = a.appointment_id
      WHERE a.patient_id = ? AND a.facility_id = ? AND a.appointment_id <> ?
   ORDER BY a.appointment_date DESC LIMIT 6"
);
$st->execute([(int)$r['patient_id'], $fid, (int)$r['appointment_id']]);
$history = $st->fetchAll();

$st = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE facility_id = ? AND (doctor_id IS NULL OR doctor_id = ?) AND status = 'pending'");
$st->execute([$fid, $uid]);
$queueLeft = (int)$st->fetchColumn();

$age = age_from($r['dob']);
$quick = [
    'Come fasting.', 'Bring your health card.', 'Bring all current medicines.',
    'Come in the morning session.', 'Continue the current treatment until the visit.',
];

$page_title = $r['full_name'];
$page_sub   = badge($r['status']) . ' &nbsp;' . e($r['department']) . ' · requested for ' . e(fmt_date($r['appointment_date'])) . ' (' . e(rel_day((string)$r['appointment_date'])) . ')';
$crumbs     = [['Review queue', '/doctor/dashboard.php']];
$nav_active = '/doctor/dashboard.php';
$page_actions = $r['status'] === 'pending' && $queueLeft > 1
    ? '<a class="btn btn--ghost" href="' . e(url('/doctor/case.php?id=' . (int)next_case($pdo, $fid, $uid, $cid))) . '">Skip to next ' . icon('arrow') . '</a>'
    : '';
require __DIR__ . '/../includes/header.php';
?>
<div class="grid grid-main--wide">
<div>
  <section class="card">
    <header class="card__h"><h2>Request</h2><span class="muted">Sent by <?= e($r['booked_by_name']) ?> (<?= e(role_label((string)$r['booked_by_role'])) ?>), <?= e(fmt_datetime($r['created_at'])) ?></span></header>
    <div class="card__b">
      <p class="note-h">Reason</p>
      <p class="note"><?= e($r['reason']) ?></p>
      <p class="note-h">Clinical notes</p>
      <?= $r['clinical_notes'] ? '<p class="note">' . e($r['clinical_notes']) . '</p>' : '<p class="muted">No clinical notes were sent.</p>' ?>
<?php if ($r['doctor_response'] !== null): ?>
      <p class="note-h">Reply<?= $r['answered_by_name'] ? ' from ' . e($r['answered_by_name']) : '' ?><?= $r['responded_at'] ? ', ' . e(fmt_datetime($r['responded_at'])) : '' ?></p>
      <p class="note note--reply"><?= e($r['doctor_response']) ?></p>
<?php endif; ?>
    </div>
  </section>

<?php if ($r['status'] !== 'completed'): ?>
  <form method="post" class="card" data-offline="respond-<?= (int)$r['consultation_id'] ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="consultation_id" value="<?= (int)$r['consultation_id'] ?>">
    <header class="card__h"><h2><?= $r['status'] === 'pending' ? 'Your decision' : 'Update this visit' ?></h2></header>
    <div class="card__b">
      <?php if ($err): ?><div class="msg err" role="alert"><?= icon('alert') ?><span><?= e($err) ?></span></div><?php endif; ?>
      <div class="field">
        <div class="choice" role="radiogroup" aria-label="Decision">
<?php
$choices = $r['status'] === 'pending'
    ? ['confirm' => ['Confirm', 'Patient may travel on ' . fmt_date($r['appointment_date'], false)],
       'reschedule' => ['New date', 'Propose another day'],
       'decline' => ['Decline', 'Not needed, or refer elsewhere']]
    : ['complete' => ['Mark completed', 'The patient has been seen'],
       'reschedule' => ['New date', 'Move the visit'],
       'decline' => ['Cancel visit', 'With a reason for the patient']];
foreach ($choices as $val => [$label, $desc]): ?>
          <label><input type="radio" name="action" value="<?= $val ?>"<?= $old['action'] === $val ? ' checked' : '' ?>><div><b><?= e($label) ?></b><span><?= e($desc) ?></span></div></label>
<?php endforeach; ?>
        </div>
      </div>
      <div class="field" data-show-when="action=reschedule">
        <label for="new_date">New date</label>
        <input id="new_date" name="new_date" type="date" min="<?= e(today()) ?>" value="<?= e($old['new_date']) ?>">
      </div>
      <div class="field">
        <label for="response">Note to the patient and health worker <span class="opt">(required when declining)</span></label>
        <textarea id="response" name="response" maxlength="255" rows="3" placeholder="e.g. Come fasting and bring the child health card"><?= e($old['response']) ?></textarea>
        <div class="quick noprint">
<?php foreach ($quick as $qtext): ?>
          <button type="button" class="chip" data-append="response" data-text="<?= e($qtext) ?>">+ <?= e($qtext) ?></button>
<?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="card__f">
      <span class="muted grow-l"><?= $r['status'] === 'pending' && $queueLeft > 1 ? ($queueLeft - 1) . ' more waiting after this one' : '' ?></span>
      <button type="submit"><?= icon('check') ?>Save reply</button>
    </div>
  </form>
<?php endif; ?>
</div>

<aside>
  <section class="card">
    <div class="case-h"><?= avatar($r['full_name'], 'av av--lg') ?><div><h2><?= e($r['full_name']) ?></h2><p class="mono"><?= e($r['patient_number']) ?></p></div></div>
    <div class="card__b">
      <?= kv([
            'Sex'      => $r['sex'] ? ($r['sex'] === 'F' ? 'Female' : 'Male') : null,
            'Age'      => $age !== null ? $age . ' years' : null,
            'Village'  => $r['village'] ? e($r['village']) : null,
            'Phone'    => $r['phone'] ? e($r['phone']) : null,
            'Addressed'=> $r['doctor_id'] ? 'To you' : 'Any doctor at the centre',
          ]) ?>
    </div>
  </section>
  <section class="card">
    <header class="card__h"><h2>Earlier visits here</h2></header>
    <div class="card__b">
<?php if (!$history): ?>
      <p class="muted">First request at this centre.</p>
<?php else: ?>
      <ol class="tl">
<?php foreach ($history as $h): ?>
        <li><span class="tl__dot s-<?= e($h['status']) ?>"></span>
          <div><p class="tl__t"><?= e($h['department']) ?></p><p class="tl__m" title="<?= e($h['reason']) ?>"><?= e($h['reason']) ?></p></div>
          <time class="tl__w"><?= e(fmt_date($h['appointment_date'], false)) ?></time></li>
<?php endforeach; ?>
      </ol>
<?php endif; ?>
    </div>
  </section>
</aside>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
