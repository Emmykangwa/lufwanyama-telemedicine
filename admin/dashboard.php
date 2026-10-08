<?php
/**
 * admin/dashboard.php -- summary reports (FR6).
 *
 * Pick a date window, press Apply, then Print to save a PDF for the appendix
 * of a report. All aggregates are read-only queries with bound parameters.
 */
require_once __DIR__ . '/../includes/init.php';
$me = require_role(['admin']);

$from = clean_get('from', 10);
$to   = clean_get('to', 10);
$fromSql = valid_date($from) ? $from : '2000-01-01';
$toSql   = valid_date($to)   ? $to   : '2100-12-31';
if ($toSql < $fromSql) {
    [$fromSql, $toSql] = [$toSql, $fromSql];
}
$range = [$fromSql, $toSql];

/** Run a read query and return all rows. */
function q(PDO $pdo, string $sql, array $args = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

$WHERE = 'WHERE a.appointment_date BETWEEN ? AND ?';

$total    = (int)q($pdo, "SELECT COUNT(*) AS c FROM appointments a $WHERE", $range)[0]['c'];
$patients = (int)$pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn();
$staff    = q($pdo, 'SELECT role AS k, COUNT(*) AS c FROM users GROUP BY role ORDER BY role');

$byStatus = q($pdo, "SELECT a.status AS k, COUNT(*) AS c FROM appointments a $WHERE
                     GROUP BY a.status ORDER BY a.status", $range);
$byDept   = q($pdo, "SELECT a.department AS k, COUNT(*) AS c FROM appointments a $WHERE
                     GROUP BY a.department ORDER BY c DESC, k", $range);
$byCentre = q($pdo, "SELECT f.name AS k, COUNT(*) AS c
                       FROM appointments a JOIN facilities f ON f.facility_id = a.facility_id
                       $WHERE GROUP BY f.name ORDER BY c DESC, k", $range);
$byDoctor = q($pdo, "SELECT COALESCE(u.full_name, 'Any doctor (unassigned)') AS k, COUNT(*) AS c
                       FROM appointments a LEFT JOIN users u ON u.user_id = a.doctor_id
                       $WHERE GROUP BY k ORDER BY c DESC, k", $range);
$byMonth  = q($pdo, "SELECT DATE_FORMAT(a.appointment_date, '%Y-%m') AS k, COUNT(*) AS c
                       FROM appointments a $WHERE GROUP BY k ORDER BY k DESC LIMIT 12", $range);
$bySource = q($pdo, "SELECT CASE WHEN bk.role = 'patient' THEN 'Booked by the patient'
                                 ELSE 'Requested by a health worker' END AS k, COUNT(*) AS c
                       FROM appointments a JOIN users bk ON bk.user_id = a.booked_by
                       $WHERE GROUP BY k ORDER BY c DESC", $range);

// How quickly the doctor answers, and how many are still unanswered.
$speed = q($pdo, "SELECT
      SUM(c.responded_at IS NOT NULL) AS answered,
      SUM(c.responded_at IS NULL)     AS waiting,
      ROUND(AVG(TIMESTAMPDIFF(MINUTE, a.created_at, c.responded_at)) / 60.0, 1) AS avg_hours
    FROM appointments a JOIN consultations c ON c.appointment_id = a.appointment_id $WHERE", $range)[0];

/** One small two-column table. */
function panel(string $title, array $rows, bool $asBadge = false): void
{
    echo '<div class="card"><h2>' . e($title) . '</h2><table>';
    if (!$rows) {
        echo '<tr><td>' . e(t('no_records')) . '</td></tr>';
    }
    foreach ($rows as $r) {
        echo '<tr><td>' . ($asBadge ? badge((string)$r['k']) : e((string)$r['k']))
           . '</td><td><b>' . (int)$r['c'] . '</b></td></tr>';
    }
    echo '</table></div>';
}

$page_title = t('reports');
require __DIR__ . '/../includes/header.php';
?>
<form method="get" class="card noprint row">
  <div>
    <label for="from"><?= e(t('from')) ?></label>
    <input id="from" name="from" type="date" value="<?= e($from) ?>">
  </div>
  <div>
    <label for="to"><?= e(t('to')) ?></label>
    <input id="to" name="to" type="date" value="<?= e($to) ?>">
  </div>
  <div><button type="submit"><?= icon('search') ?><?= e(t('apply')) ?></button></div>
  <div><button type="button" class="alt" data-print="1"><?= icon('printer') ?><?= e(t('print')) ?></button></div>
</form>

<p class="hint">Appointment dates from <?= e($fromSql) ?> to <?= e($toSql) ?>.
   Leave the dates empty for everything on record.</p>

<div class="stats">
  <div class="stat"><b><?= $total ?></b><span>Appointments</span></div>
  <div class="stat"><b><?= $patients ?></b><span><?= e(t('patients')) ?></span></div>
  <div class="stat"><b><?= (int)$speed['answered'] ?></b><span>Answered</span></div>
  <div class="stat"><b><?= (int)$speed['waiting'] ?></b><span>Awaiting reply</span></div>
  <div class="stat"><b><?= $speed['avg_hours'] !== null ? e((string)$speed['avg_hours']) : '-' ?></b><span>Avg hours to reply</span></div>
<?php foreach ($staff as $s): ?>
  <div class="stat"><b><?= (int)$s['c'] ?></b><span><?= e(str_replace('_', ' ', (string)$s['k'])) ?></span></div>
<?php endforeach; ?>
</div>

<?php
panel('Appointments by status', $byStatus, true);
panel('Appointments by department', $byDept);
panel('Appointments by health centre', $byCentre);
panel('Appointments by doctor', $byDoctor);
panel('Requested by a health worker or booked by the patient', $bySource);
panel('Appointments per month (latest 12)', $byMonth);
?>

<div class="card noprint">
  <h2>Also available</h2>
  <p>
    <a class="btn alt" href="<?= e(url('/admin/users.php')) ?>"><?= icon('users') ?><?= e(t('users')) ?></a>
    <a class="btn alt" href="<?= e(url('/admin/audit.php')) ?>"><?= icon('shield') ?><?= e(t('audit')) ?></a>
  </p>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
