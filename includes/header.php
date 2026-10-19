<?php
/**
 * includes/header.php -- the application shell.
 *
 * A page sets these before requiring this file:
 *   $page_title    (required) document title and the page heading
 *   $page_sub      optional subtitle, ready-made HTML
 *   $page_actions  optional HTML for the buttons at the right of the heading
 *   $crumbs        optional [[label, path], ...] shown above the heading
 *   $nav_active    optional path to highlight in the sidebar when the page
 *                  itself is not a sidebar entry (e.g. a case detail page)
 */
$user       = current_user();
$f          = flash();
$page_title = $page_title ?? t('app_name');

$here = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$activePath = $nav_active ?? null;
$on   = static function (string $p) use ($here, $activePath): bool {
    return $activePath !== null ? $activePath === $p : str_ends_with($here, $p);
};

// Sidebar groups for this role: [group label, [[path, label, icon, badge], ...]].
$groups = [];
if ($user) {
    $badge = null;
    if ($user['role'] === 'doctor') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM appointments
                              WHERE facility_id = ? AND status = 'pending'
                                AND (doctor_id IS NULL OR doctor_id = ?)");
        $st->execute([(int)$user['facility_id'], (int)$user['user_id']]);
        $badge = (int)$st->fetchColumn();
    } elseif ($user['role'] === 'health_worker') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE facility_id = ? AND status = 'pending'");
        $st->execute([(int)$user['facility_id']]);
        $badge = (int)$st->fetchColumn();
    }

    $groups = match ($user['role']) {
        'admin' => [
            ['', [['/admin/dashboard.php', 'Overview', 'chart', null]]],
            ['People', [
                ['/admin/staff.php',    'Staff',    'users', null],
                ['/admin/patients.php', 'Patients', 'user',  null],
            ]],
            ['Organisation', [['/admin/centres.php', 'Health centres', 'building', null]]],
            ['Records', [['/admin/audit.php', 'Audit log', 'shield', null]]],
        ],
        'health_worker' => [
            ['', [['/health/dashboard.php', 'Overview', 'grid', null]]],
            ['Clinic', [
                ['/health/requests.php', 'Requests', 'inbox', $badge ?: null],
                ['/health/patients.php', 'Patients', 'users', null],
            ]],
            ['Create', [
                ['/health/new_appointment.php', 'New request',      'send',     null],
                ['/health/add_patient.php',     'Register patient', 'userplus', null],
            ]],
        ],
        'doctor' => [
            ['', [
                ['/doctor/dashboard.php', 'Review queue', 'inbox',    $badge ?: null],
                ['/doctor/schedule.php',  'Schedule',     'calendar', null],
            ]],
        ],
        'patient' => [
            ['', [
                ['/patient/dashboard.php', 'My appointments',     'grid',     null],
                ['/patient/book.php',      'Request appointment', 'calendar', null],
            ]],
        ],
        default => [],
    };
    $groups[] = ['Account', [['/account.php', 'My account', 'settings', null]]];

    $page_sub = $page_sub ?? null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f6e53">
<meta name="description" content="Lufwanyama District Telemedicine — request a health centre appointment and consult a doctor before you travel.">
<title><?= e($page_title) ?> &middot; <?= e(t('app_name')) ?></title>
<link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>">
<link rel="icon" href="data:,">
</head>
<body class="<?= $user ? 'in' : 'out' ?>"<?= ($f && $f['type'] === 'ok') ? ' data-clear="1"' : '' ?>>
<a class="skip" href="#main">Skip to content</a>

<?php if ($user): ?>
<div class="shell">
  <aside class="side" aria-label="Sidebar">
    <a class="side__brand" href="<?= e(url(dashboard_path($user['role']))) ?>">
      <span class="logo"><?= icon('cross') ?></span>
      <span class="side__name">Lufwanyama<small>Telemedicine</small></span>
    </a>

    <nav class="side__nav" aria-label="Sections">
<?php foreach ($groups as [$glabel, $items]): ?>
<?php if ($glabel !== ''): ?>
      <p class="side__group"><?= e($glabel) ?></p>
<?php endif; ?>
<?php foreach ($items as [$path, $label, $ico, $count]): $isOn = $on($path); ?>
      <a class="side__link<?= $isOn ? ' on' : '' ?>" href="<?= e(url($path)) ?>"<?= $isOn ? ' aria-current="page"' : '' ?>><?= icon($ico) ?><span><?= e($label) ?></span><?= $count ? '<b class="side__count" title="Pending">' . (int)$count . '</b>' : '' ?></a>
<?php endforeach; ?>
<?php endforeach; ?>
    </nav>

    <div class="side__me">
      <?= avatar($user['full_name']) ?>
      <div class="side__who">
        <b><?= e($user['full_name']) ?></b>
        <span><?= e(role_label($user['role'])) ?><?= $user['facility'] ? ' · ' . e((string)$user['facility']) : '' ?></span>
      </div>
      <a class="side__out" href="<?= e(url('/logout.php')) ?>" title="Sign out" aria-label="Sign out"><?= icon('logout') ?></a>
    </div>
  </aside>

  <div class="main">
    <header class="phead">
      <div class="phead__t">
<?php if (!empty($crumbs)): ?>
        <nav class="crumbs" aria-label="Breadcrumb">
<?php foreach ($crumbs as [$cl, $cp]): ?>
          <a href="<?= e(url($cp)) ?>"><?= e($cl) ?></a><span aria-hidden="true">/</span>
<?php endforeach; ?>
        </nav>
<?php endif; ?>
        <h1><?= e($page_title) ?></h1>
<?php if ($page_sub): ?>
        <p><?= $page_sub ?></p>
<?php endif; ?>
      </div>
<?php if (!empty($page_actions)): ?>
      <div class="phead__a noprint"><?= $page_actions ?></div>
<?php endif; ?>
    </header>

    <main id="main" class="content">
<?php if ($f): ?>
      <div class="msg <?= $f['type'] === 'ok' ? 'ok' : 'err' ?>" role="status"><?= icon($f['type'] === 'ok' ? 'check' : 'alert') ?><span><?= e($f['msg']) ?></span></div>
<?php endif; ?>

<?php else: ?>
<header class="pubbar">
  <a class="pubbar__brand" href="<?= e(url('/index.php')) ?>">
    <span class="logo"><?= icon('cross') ?></span>
    <span class="side__name">Lufwanyama<small>Telemedicine</small></span>
  </a>
  <nav class="pubbar__nav" aria-label="Main">
    <a href="<?= e(url('/index.php')) ?>">Home</a>
    <a class="btn btn--ghost btn--sm" href="<?= e(url('/login.php')) ?>">Sign in</a>
  </nav>
</header>
<main id="main" class="pubmain">
<?php if ($f): ?>
  <div class="msg <?= $f['type'] === 'ok' ? 'ok' : 'err' ?>" role="status"><?= icon($f['type'] === 'ok' ? 'check' : 'alert') ?><span><?= e($f['msg']) ?></span></div>
<?php endif; ?>
<?php endif; ?>
