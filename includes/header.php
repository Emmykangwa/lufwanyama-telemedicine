<?php
/**
 * includes/header.php
 *
 * Set $page_title before requiring this file. Optionally set $page_head to
 * override the big heading in the top bar, and $page_sub for its subtitle.
 */
$user       = current_user();
$f          = flash();
$page_title = $page_title ?? t('app_name');

$here  = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$on    = static fn(string $p): bool => str_ends_with($here, $p);

// The navigation for this role: path, label, icon.
$nav = [];
if ($user) {
    $nav = match ($user['role']) {
        'patient' => [
            ['/patient/dashboard.php', t('my_appointments'), 'grid'],
            ['/patient/book.php',      t('book'),            'calendar'],
        ],
        'health_worker' => [
            ['/health/dashboard.php',       t('dashboard'),       'grid'],
            ['/health/patients.php',        t('patients'),        'users'],
            ['/health/new_appointment.php', t('new_appointment'), 'send'],
            ['/health/add_patient.php',     t('add_patient'),     'userplus'],
        ],
        'doctor' => [
            ['/doctor/dashboard.php', t('incoming'),    'inbox'],
            ['/doctor/schedule.php',  t('my_schedule'), 'calendar'],
        ],
        'admin' => [
            ['/admin/dashboard.php', t('reports'), 'chart'],
            ['/admin/users.php',     t('users'),   'users'],
            ['/admin/audit.php',     t('audit'),   'shield'],
        ],
        default => [],
    };

    $isDash = $on('dashboard.php');
    $head   = $page_head ?? ($isDash ? greeting() . ', ' . first_name($user['full_name']) : $page_title);
    // $sub holds ready-made HTML (it carries a &middot; separator), so each
    // part is escaped as it is put together.
    $sub    = $page_sub ?? trim(e(str_replace('_', ' ', $user['role']))
              . ($user['facility'] ? ' &middot; ' . e((string)$user['facility']) : ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#4f46e5">
<meta name="description" content="Lufwanyama District Telemedicine — request a health centre appointment and consult a doctor before you travel.">
<title><?= e($page_title) ?> &middot; <?= e(t('app_name')) ?></title>
<link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>">
<link rel="icon" href="data:,">
</head>
<body<?= ($f && $f['type'] === 'ok') ? ' data-clear="1"' : '' ?>>
<a class="skip" href="#main">Skip to content</a>

<?php if ($user): ?>
<div class="app">
  <aside class="rail">
    <a class="rail__logo" href="<?= e(url(dashboard_path($user['role']))) ?>" aria-label="<?= e(t('app_name')) ?>"><?= icon('cross') ?></a>
    <nav aria-label="Sections">
<?php foreach ($nav as [$path, $label, $ico]): ?>
      <a class="rl<?= $on($path) ? ' on' : '' ?>" href="<?= e(url($path)) ?>" title="<?= e($label) ?>" aria-label="<?= e($label) ?>"<?= $on($path) ? ' aria-current="page"' : '' ?>><?= icon($ico) ?></a>
<?php endforeach; ?>
    </nav>
    <div class="rail__end">
      <a class="rl out" href="<?= e(url('/logout.php')) ?>" title="<?= e(t('logout')) ?>" aria-label="<?= e(t('logout')) ?>"><?= icon('logout') ?></a>
    </div>
  </aside>

  <div class="app__main">
    <header class="topbar">
      <div class="greet">
        <h1><?= e($head) ?></h1>
        <p><?= $sub ?></p>
      </div>
      <nav class="pills" aria-label="Main">
<?php foreach ($nav as [$path, $label, $ico]): ?>
        <a class="<?= $on($path) ? 'on' : '' ?>" href="<?= e(url($path)) ?>"<?= $on($path) ? ' aria-current="page"' : '' ?>><?= icon($ico) ?><?= e($label) ?></a>
<?php endforeach; ?>
      </nav>
      <div class="tools">
        <span class="avatar" title="<?= e($user['full_name']) ?>"><?= e(initials($user['full_name'])) ?></span>
      </div>
    </header>

    <main id="main" class="content">
<?php if ($f): ?>
      <div class="msg <?= $f['type'] === 'ok' ? 'ok' : 'err' ?>" role="status"><?= icon($f['type'] === 'ok' ? 'check' : 'alert') ?><span><?= e($f['msg']) ?></span></div>
<?php endif; ?>

<?php else: ?>
<div class="pub">
  <header class="pub-head">
    <a class="brand" href="<?= e(url('/index.php')) ?>">
      <span class="brand__mark"><?= icon('cross') ?></span>
      <span class="brand__text">
        <span class="brand__name">Lufwanyama</span>
        <span class="brand__sub">Telemedicine</span>
      </span>
    </a>
    <nav class="pub-nav" aria-label="Main">
      <a class="btn alt mini" href="<?= e(url('/login.php')) ?>"><?= icon('login') ?><?= e(t('login')) ?></a>
      <a class="btn mini" href="<?= e(url('/register.php')) ?>"><?= icon('userplus') ?><?= e(t('register')) ?></a>
    </nav>
  </header>
</div>
<main id="main" class="pub-main">
<?php if ($f): ?>
  <div class="msg <?= $f['type'] === 'ok' ? 'ok' : 'err' ?>" role="status"><?= icon($f['type'] === 'ok' ? 'check' : 'alert') ?><span><?= e($f['msg']) ?></span></div>
<?php endif; ?>
<?php endif; ?>
