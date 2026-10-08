<?php
/** index.php -- public landing page */
require_once __DIR__ . '/includes/init.php';

if (current_user()) {
    redirect(dashboard_path(current_user()['role']));
}

$page_title = t('home');
require __DIR__ . '/includes/header.php';
?>
<section class="hero">
  <span class="hero__eyebrow">Lufwanyama District Health</span>
  <h1><?= e(t('tagline')) ?></h1>
  <p>Request an appointment at your nearest health centre, send your clinical notes
     ahead, and let the doctor confirm before you spend money on travel.</p>
  <div class="actions">
    <a class="btn alt" href="<?= e(url('/register.php')) ?>"><?= icon('userplus') ?><?= e(t('register')) ?></a>
    <a class="btn ghost" href="<?= e(url('/login.php')) ?>"><?= icon('login') ?><?= e(t('login')) ?></a>
  </div>
</section>

<section class="features">
  <div class="feature">
    <div class="ico"><?= icon('calendar') ?></div>
    <h3>Book without travelling</h3>
    <p>Request a visit to your health centre from your phone, or ask a health worker to do it for you.</p>
  </div>
  <div class="feature">
    <div class="ico"><?= icon('pulse') ?></div>
    <h3>A doctor reviews first</h3>
    <p>The doctor at your centre reads your clinical notes and replies with a confirmed date or advice.</p>
  </div>
  <div class="feature">
    <div class="ico"><?= icon('signal') ?></div>
    <h3>Works on a weak signal</h3>
    <p>Light pages and saved drafts mean a dropped connection never loses your request.</p>
  </div>
</section>

<div class="card">
  <h2>How it works</h2>
  <ol class="steps">
    <li>You, or a health worker at your clinic, request an appointment at a health centre.</li>
    <li>The doctor at that centre reviews your details and clinical notes.</li>
    <li>They confirm, suggest another date, or decline with a reason.</li>
    <li>You follow the status here, and travel only once it reads <?= badge('confirmed') ?>.</li>
  </ol>
  <p class="hint">Already have a patient number from your clinic?
     <a href="<?= e(url('/login.php')) ?>">Log in</a> to follow your appointment.</p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
