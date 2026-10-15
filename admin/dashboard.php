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
$bySource = q($pdo, "SELECT CASE WHEN bk.role = 'patient' THEN 'Booked by the patient'
                                 ELSE 'Requested by a health worker' END AS k, COUNT(*) AS c
                       FROM appointments a JOIN users bk ON bk.user_id = a.booked_by
                       $WHERE GROUP BY k ORDER BY c DESC", $range);

// Daily volume across the window, for the column chart.
$byDay = q($pdo, "SELECT a.appointment_date AS k, COUNT(*) AS c FROM appointments a $WHERE
                  GROUP BY a.appointment_date ORDER BY a.appointment_date", $range);

// How quickly the doctor answers, and how many are still unanswered.
$speed = q($pdo, "SELECT
      SUM(c.responded_at IS NOT NULL) AS answered,
      SUM(c.responded_at IS NULL)     AS waiting,
      ROUND(AVG(TIMESTAMPDIFF(MINUTE, a.created_at, c.responded_at)) / 60.0, 1) AS avg_hours
    FROM appointments a JOIN consultations c ON c.appointment_id = a.appointment_id $WHERE", $range)[0];

// Latest activity, read from the audit trail.
$recent = q($pdo, "SELECT l.created_at, l.action, l.entity, l.details,
                          COALESCE(u.full_name, 'system') AS who
                     FROM audit_logs l LEFT JOIN users u ON u.user_id = l.user_id
                    ORDER BY l.log_id DESC LIMIT 8");

/* ---- shapes for the charts ---------------------------------------- */
$dayMap = [];
foreach ($byDay as $r) {
    $dayMap[(string)$r['k']] = (int)$r['c'];
}
// A continuous run of days, so a quiet day reads as a gap rather than being
// skipped. Anchored on the last day that actually carries data (the date
// filter defaults are open-ended, so they cannot anchor the window).
$chart = [];
if ($dayMap) {
    $end   = min(strtotime($toSql), strtotime((string)array_key_last($dayMap)));
    $start = max(strtotime($fromSql), strtotime((string)array_key_first($dayMap)));
    $start = max($start, strtotime('-29 day', $end));   // at most 30 columns
    for ($d = $start; $d <= $end; $d = strtotime('+1 day', $d)) {
        $iso = date('Y-m-d', $d);
        $chart[date('j M', $d)] = $dayMap[$iso] ?? 0;
    }
}
$trend = array_values($chart) ?: [0, 0];

$statusMap = [];
foreach ($byStatus as $r) {
    $statusMap[(string)$r['k']] = (int)$r['c'];
}
$confirmedShare = $total > 0
    ? round((($statusMap['confirmed'] ?? 0) + ($statusMap['rescheduled'] ?? 0)) / $total * 100)
    : 0;
$staffTotal = 0;
foreach ($staff as $s) {
    $staffTotal += (int)$s['c'];
}

// Days that carry an appointment, coloured by the status most worth acting
// on that day, so the grid says what still needs attention.
$dayStatus = q($pdo, "SELECT a.appointment_date AS k, a.status AS s, COUNT(*) AS c
                        FROM appointments a $WHERE GROUP BY k, s", $range);
$prio   = ['pending' => 5, 'rescheduled' => 4, 'confirmed' => 3, 'completed' => 2, 'declined' => 1];
$marked = [];
foreach ($dayStatus as $r) {
    $d = (string)$r['k'];
    $s = (string)$r['s'];
    if (!isset($marked[$d]) || ($prio[$s] ?? 0) > ($prio[$marked[$d]] ?? 0)) {
        $marked[$d] = $s;
    }
}

// Show the month the user most likely cares about: this one if it has any
// appointments, otherwise the next month that does, otherwise the last.
$calMonth = date('Y-m-01');
if ($marked) {
    $months = array_values(array_unique(array_map(
        static fn(string $d): string => substr($d, 0, 7) . '-01', array_keys($marked))));
    sort($months);
    if (!array_filter($marked, static fn(string $d): bool
            => substr($d, 0, 7) === date('Y-m'), ARRAY_FILTER_USE_KEY)) {
        $future   = array_values(array_filter($months, static fn(string $m): bool => $m >= date('Y-m-01')));
        $calMonth = $future ? $future[0] : end($months);
    }
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

<div class="metrics">
<?= metric('Appointments', (string)$total, $total === 1 ? 'request' : 'requests',
      spark_bars($trend),
      $total > 0
        ? '<b>' . $confirmedShare . '%</b> confirmed or rescheduled in this window'
        : 'No appointments fall in this date window') ?>

<?= metric('Answered by a doctor', (string)(int)$speed['answered'], 'replies',
      spark_line($trend),
      ((int)$speed['waiting'] > 0)
        ? '<b>' . (int)$speed['waiting'] . '</b> still awaiting a reply'
        : 'Every request has had a reply') ?>

<?= metric('Average reply time',
      $speed['avg_hours'] !== null ? (string)$speed['avg_hours'] : '—',
      $speed['avg_hours'] !== null ? 'hours' : '',
      spark_line(array_reverse($trend)),
      $speed['avg_hours'] !== null
        ? 'From request to the doctor&rsquo;s first response'
        : 'No replies recorded yet in this window') ?>

<?= metric('People on the system', (string)$patients, 'patients',
      spark_bars($trend),
      '<b>' . $staffTotal . '</b> staff and patient accounts in total') ?>
</div>

<div class="cols">
  <div>
    <?= panel_open('Appointments per day',
          '<div class="legend"><span><i></i>Appointments</span></div>') ?>
      <?= bar_chart($chart, 'Appointments per day from ' . $fromSql . ' to ' . $toSql) ?>
    <?= panel_close() ?>

    <?= panel_open('Status breakdown') ?>
      <?= status_split($byStatus) ?>
    <?= panel_close() ?>

    <div class="cols cols--even">
      <?= panel_open('By department') ?>
        <?= rank_bars($byDept) ?>
      <?= panel_close() ?>

      <?= panel_open('By health centre') ?>
        <?= rank_bars($byCentre) ?>
      <?= panel_close() ?>
    </div>

    <?= panel_open('By doctor') ?>
      <?= rank_bars($byDoctor) ?>
    <?= panel_close() ?>

    <?= panel_open('How the request was made') ?>
      <?= rank_bars($bySource) ?>
    <?= panel_close() ?>
  </div>

  <div>
    <?= panel_open('Appointment days') ?>
      <?= mini_cal($calMonth, $marked) ?>
    <?= panel_close() ?>

    <?= panel_open('Staff and patients') ?>
      <?= rank_bars(array_map(
            static fn(array $s): array => ['k' => ucfirst(str_replace('_', ' ', (string)$s['k'])), 'c' => $s['c']],
            $staff)) ?>
    <?= panel_close() ?>

    <?= panel_open('Recent activity',
          '<a class="btn alt mini noprint" href="' . e(url('/admin/audit.php')) . '">'
          . icon('shield') . e(t('audit')) . '</a>') ?>
      <?= timeline(array_map(static function (array $r): array {
            return [
                substr((string)$r['created_at'], 5, 11),
                ucfirst(str_replace('_', ' ', (string)$r['action'])) . ' &middot; ' . $r['entity'],
                (string)$r['who'] . ((string)$r['details'] !== '' ? ' — ' . $r['details'] : ''),
                null,
            ];
          }, $recent)) ?>
    <?= panel_close() ?>

    <?= panel_open('Also available') ?>
      <p class="actions noprint" style="margin:0">
        <a class="btn alt mini" href="<?= e(url('/admin/users.php')) ?>"><?= icon('users') ?><?= e(t('users')) ?></a>
        <a class="btn alt mini" href="<?= e(url('/admin/audit.php')) ?>"><?= icon('shield') ?><?= e(t('audit')) ?></a>
      </p>
    <?= panel_close() ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
