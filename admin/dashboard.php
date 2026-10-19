<?php
/**
 * admin/dashboard.php -- district overview and summary reports (FR6).
 *
 * Opens on what needs action (overdue replies, centres missing staff,
 * patients on no register), then the figures for a chosen window. Print
 * gives a clean report. All aggregates are read-only, parameterised queries.
 */
require_once __DIR__ . '/../includes/init.php';
$me = require_role(['admin']);

// Preset windows instead of a bare date form; 'custom' keeps from/to.
$preset = clean_get('range', 10);
$from   = clean_get('from', 10);
$to     = clean_get('to', 10);
if (valid_date($from) || valid_date($to)) {
    $preset = 'custom';
} elseif (!in_array($preset, ['30', '90', '365'], true)) {
    $preset = 'all';
}
$fromSql = match ($preset) {
    '30', '90', '365' => date('Y-m-d', strtotime('-' . $preset . ' days')),
    'custom'          => valid_date($from) ? $from : '2000-01-01',
    default           => '2000-01-01',
};
$toSql = $preset === 'custom' && valid_date($to) ? $to : '2100-12-31';
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
$staff    = q($pdo, "SELECT role AS k, COUNT(*) AS c FROM users WHERE is_active = 1
                     GROUP BY role ORDER BY FIELD(role, 'doctor', 'health_worker', 'admin', 'patient')");

$byStatus = q($pdo, "SELECT a.status AS k, COUNT(*) AS c FROM appointments a $WHERE
                     GROUP BY a.status ORDER BY FIELD(a.status, 'pending','confirmed','rescheduled','completed','declined')", $range);
$byDept   = q($pdo, "SELECT a.department AS k, COUNT(*) AS c FROM appointments a $WHERE
                     GROUP BY a.department ORDER BY c DESC, k", $range);
$byCentre = q($pdo, "SELECT f.name AS k, COUNT(*) AS c
                       FROM appointments a JOIN facilities f ON f.facility_id = a.facility_id
                       $WHERE GROUP BY f.name ORDER BY c DESC, k", $range);
$byDoctor = q($pdo, "SELECT COALESCE(u.full_name, 'Any doctor (unassigned)') AS k, COUNT(*) AS c
                       FROM appointments a LEFT JOIN users u ON u.user_id = a.doctor_id
                       $WHERE GROUP BY k ORDER BY c DESC, k", $range);
$bySource = q($pdo, "SELECT CASE WHEN bk.role = 'patient' THEN 'Booked by the patient'
                                 ELSE 'Sent by a health worker' END AS k, COUNT(*) AS c
                       FROM appointments a JOIN users bk ON bk.user_id = a.booked_by
                       $WHERE GROUP BY k ORDER BY c DESC", $range);

$byDay = q($pdo, "SELECT a.appointment_date AS k, COUNT(*) AS c FROM appointments a $WHERE
                  GROUP BY a.appointment_date ORDER BY a.appointment_date", $range);

$speed = q($pdo, "SELECT
      SUM(c.responded_at IS NOT NULL) AS answered,
      SUM(c.responded_at IS NULL)     AS waiting,
      ROUND(AVG(TIMESTAMPDIFF(MINUTE, a.created_at, c.responded_at)) / 60.0, 1) AS avg_hours
    FROM appointments a JOIN consultations c ON c.appointment_id = a.appointment_id $WHERE", $range)[0];

$recent = q($pdo, "SELECT l.created_at, l.action, l.entity, l.details,
                          COALESCE(u.full_name, 'system') AS who
                     FROM audit_logs l LEFT JOIN users u ON u.user_id = l.user_id
                    ORDER BY l.log_id DESC LIMIT 8");

/* ---- shapes for the charts ---------------------------------------- */
$dayMap = [];
foreach ($byDay as $r) {
    $dayMap[(string)$r['k']] = (int)$r['c'];
}
// A continuous run of days (at most 30), anchored on the last day with data,
// so a quiet day reads as a gap rather than being skipped.
$chart = [];
if ($dayMap) {
    $end   = min(strtotime($toSql), strtotime((string)array_key_last($dayMap)));
    $start = max(strtotime($fromSql), strtotime((string)array_key_first($dayMap)));
    $start = max($start, strtotime('-29 day', $end));
    for ($d = $start; $d <= $end; $d = strtotime('+1 day', $d)) {
        $chart[date('j M', $d)] = $dayMap[date('Y-m-d', $d)] ?? 0;
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
$staffCount = 0;
foreach ($staff as $s) {
    if ($s['k'] !== 'patient') {
        $staffCount += (int)$s['c'];
    }
}

// Days carrying an appointment, coloured by the status most worth acting on.
$dayStatus = q($pdo, "SELECT a.appointment_date AS k, a.status AS s FROM appointments a $WHERE GROUP BY k, s", $range);
$prio   = ['pending' => 5, 'rescheduled' => 4, 'confirmed' => 3, 'completed' => 2, 'declined' => 1];
$marked = [];
foreach ($dayStatus as $r) {
    $d = (string)$r['k'];
    $s = (string)$r['s'];
    if (!isset($marked[$d]) || ($prio[$s] ?? 0) > ($prio[$marked[$d]] ?? 0)) {
        $marked[$d] = $s;
    }
}
// This month if it has appointments, otherwise the next month that does, else the last.
$calMonth = date('Y-m-01');
if ($marked && !array_filter($marked, static fn(string $d): bool => substr($d, 0, 7) === date('Y-m'), ARRAY_FILTER_USE_KEY)) {
    $months = array_values(array_unique(array_map(static fn(string $d): string => substr($d, 0, 7) . '-01', array_keys($marked))));
    sort($months);
    $future   = array_values(array_filter($months, static fn(string $m): bool => $m >= date('Y-m-01')));
    $calMonth = $future ? $future[0] : end($months);
}

// What needs the administrator now, independent of the window.
$overdue = (int)$pdo->query("SELECT COUNT(*) FROM appointments
                              WHERE status = 'pending' AND created_at < NOW() - INTERVAL 48 HOUR")->fetchColumn();
$gaps = $pdo->query(
    "SELECT f.facility_id, f.name,
            COALESCE(SUM(u.role = 'doctor' AND u.is_active = 1), 0)        AS doctors,
            COALESCE(SUM(u.role = 'health_worker' AND u.is_active = 1), 0) AS workers
       FROM facilities f LEFT JOIN users u ON u.facility_id = f.facility_id
      WHERE f.is_active = 1 GROUP BY f.facility_id, f.name HAVING doctors = 0 OR workers = 0"
)->fetchAll();
$unattached = (int)$pdo->query('SELECT COUNT(*) FROM patients WHERE facility_id IS NULL')->fetchColumn();

$attention = [];
if ($overdue) {
    $attention[] = ['clock', $overdue . ' request' . ($overdue === 1 ? ' has' : 's have') . ' waited more than 48 hours for a doctor', null];
}
foreach ($gaps as $g) {
    foreach (['doctor' => 'doctors', 'health_worker' => 'workers'] as $roleKey => $col) {
        if ((int)$g[$col] === 0) {
            $attention[] = ['users', $g['name'] . ' has no active ' . strtolower(role_label($roleKey)),
                ['Add ' . strtolower(role_label($roleKey)), '/admin/staff_new.php?role=' . $roleKey . '&centre=' . (int)$g['facility_id']]];
        }
    }
}
if ($unattached) {
    $attention[] = ['user', $unattached . ' self-registered patient' . ($unattached === 1 ? ' is' : 's are') . ' on no centre register',
        ['Review', '/admin/patients.php?centre=none']];
}

$rangeLabel = match ($preset) {
    '30'     => 'Last 30 days',
    '90'     => 'Last 90 days',
    '365'    => 'Last 12 months',
    'custom' => fmt_date($fromSql) . ' – ' . ($toSql === '2100-12-31' ? 'now' : fmt_date($toSql)),
    default  => 'All time',
};

$page_title   = 'Overview';
$page_sub     = e(greeting() . ', ' . first_name($me['full_name'])) . ' · ' . e(date('l j F Y'));
$page_actions = '<button type="button" class="btn--ghost" data-print="1">' . icon('printer') . 'Print report</button>'
              . '<a class="btn" href="' . e(url('/admin/staff_new.php')) . '">' . icon('userplus') . 'Add staff member</a>';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($attention): ?>
<section class="card card--warn noprint">
  <header class="card__h"><h2>Needs attention</h2><span class="badge pending"><?= count($attention) ?></span></header>
  <ul class="agenda">
<?php foreach ($attention as [$ico, $text, $link]): ?>
    <li><span class="agenda__d agenda__d--ic"><?= icon($ico) ?></span><div class="agenda__b"><b><?= e($text) ?></b></div>
      <?= $link ? '<a class="btn btn--ghost btn--sm" href="' . e(url($link[1])) . '">' . e($link[0]) . '</a>' : '<span></span>' ?></li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="card noprint">
  <?= tabs(['all' => ['All time', null], '30' => ['Last 30 days', null], '90' => ['Last 90 days', null], '365' => ['Last 12 months', null]],
           $preset === 'custom' ? '' : $preset, 'range') ?>
  <form method="get" class="toolbar">
    <input type="date" name="from" value="<?= e($preset === 'custom' ? $fromSql : '') ?>" aria-label="From date">
    <span class="muted">to</span>
    <input type="date" name="to" value="<?= e($preset === 'custom' && $toSql !== '2100-12-31' ? $toSql : '') ?>" aria-label="To date">
    <button type="submit" class="btn--ghost">Apply range</button>
    <span class="count">Showing <b><?= e($rangeLabel) ?></b></span>
  </form>
</section>

<div class="metrics">
<?= metric('Appointments', (string)$total, $total === 1 ? 'request' : 'requests',
      spark_bars($trend),
      $total > 0 ? '<b>' . $confirmedShare . '%</b> confirmed or rescheduled' : 'None in this window') ?>

<?= metric('Answered by a doctor', (string)(int)$speed['answered'], 'replies',
      spark_line($trend),
      ((int)$speed['waiting'] > 0) ? '<b>' . (int)$speed['waiting'] . '</b> still awaiting a reply' : 'Every request has a reply') ?>

<?= metric('Average reply time',
      $speed['avg_hours'] !== null ? (string)$speed['avg_hours'] : '—',
      $speed['avg_hours'] !== null ? 'hours' : '',
      spark_line(array_reverse($trend)),
      'Request to first doctor response') ?>

<?= metric('Patients', (string)$patients, 'registered',
      spark_bars($trend),
      '<b>' . $staffCount . '</b> active staff accounts') ?>
</div>

<div class="cols">
  <div>
    <?= panel_open('Appointments per day', '<div class="legend"><span><i></i>' . e($rangeLabel) . '</span></div>') ?>
      <?= bar_chart($chart, 'Appointments per day, ' . $rangeLabel) ?>
    <?= panel_close() ?>

    <?= panel_open('Status breakdown') ?>
      <?= status_split($byStatus) ?>
    <?= panel_close() ?>

    <div class="cols cols--even">
      <?= panel_open('By department') ?><?= rank_bars($byDept) ?><?= panel_close() ?>
      <?= panel_open('By health centre') ?><?= rank_bars($byCentre) ?><?= panel_close() ?>
    </div>
    <div class="cols cols--even">
      <?= panel_open('By doctor') ?><?= rank_bars($byDoctor) ?><?= panel_close() ?>
      <?= panel_open('How requests were made') ?><?= rank_bars($bySource) ?><?= panel_close() ?>
    </div>
  </div>

  <div>
    <?= panel_open('Appointment days') ?>
      <?= mini_cal($calMonth, $marked) ?>
    <?= panel_close() ?>

    <?= panel_open('Active accounts', '<a class="btn btn--link noprint" href="' . e(url('/admin/staff.php')) . '">Staff</a>') ?>
      <?= rank_bars(array_map(static fn(array $s): array => ['k' => role_label((string)$s['k']), 'c' => $s['c']], $staff)) ?>
    <?= panel_close() ?>

    <?= panel_open('Recent activity', '<a class="btn btn--link noprint" href="' . e(url('/admin/audit.php')) . '">Audit log</a>') ?>
      <?= timeline(array_map(static fn(array $r): array => [
            fmt_date((string)$r['created_at'], false) . ' ' . substr((string)$r['created_at'], 11, 5),
            ucfirst(strtolower(str_replace('_', ' ', (string)$r['action']))) . ' · ' . $r['entity'],
            (string)$r['who'] . ((string)$r['details'] !== '' ? ' — ' . $r['details'] : ''),
            null,
          ], $recent)) ?>
    <?= panel_close() ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
