<?php
/**
 * account.php -- the signed-in person's own account: details and password.
 *
 * Any role. Changing the password needs the current one, so a session left
 * open on a shared clinic computer cannot be used to take the account over.
 */
require_once __DIR__ . '/includes/init.php';
$me = require_role(['patient', 'health_worker', 'doctor', 'admin']);

$st = $pdo->prepare('SELECT u.*, f.name AS facility FROM users u
                  LEFT JOIN facilities f ON f.facility_id = u.facility_id WHERE u.user_id = ?');
$st->execute([$me['user_id']]);
$acct = $st->fetch();

$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $cur  = (string)($_POST['current'] ?? '');
    $new  = (string)($_POST['new'] ?? '');
    $new2 = (string)($_POST['new2'] ?? '');

    if (!password_verify($cur, (string)$acct['password_hash'])) {
        $err = 'Your current password is not correct.';
    } elseif ($problem = password_problem($new)) {
        $err = $problem;
    } elseif ($new !== $new2) {
        $err = 'The two new passwords do not match.';
    } elseif ($new === $cur) {
        $err = 'Choose a password different from the current one.';
    } else {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
            ->execute([hash_password($new), $me['user_id']]);
        audit($pdo, 'CHANGE_PASSWORD', 'user', (int)$me['user_id'], 'By the account holder');
        flash('ok', 'Password changed. Use the new one next time you sign in.');
        redirect('/account.php');
    }
}

$patientNo = null;
if ($acct['role'] === 'patient') {
    $st = $pdo->prepare('SELECT patient_number FROM patients WHERE user_id = ?');
    $st->execute([$me['user_id']]);
    $patientNo = $st->fetchColumn() ?: null;
}

$page_title = 'My account';
require __DIR__ . '/includes/header.php';
?>
<div class="grid grid-main">
<form method="post" class="card">
  <?= csrf_field() ?>
  <header class="card__h"><h2>Change password</h2></header>
  <?php if ($err): ?><div class="card__b"><div class="msg err" role="alert"><?= icon('alert') ?><span><?= e($err) ?></span></div></div><?php endif; ?>
  <div class="fsec">
    <div class="fsec__d"><h3>Password</h3><p>At least 8 characters. Replace a temporary password from the administrator here.</p></div>
    <div class="fsec__f">
      <div class="field">
        <label for="current">Current password</label>
        <input id="current" name="current" type="password" required autocomplete="current-password">
      </div>
      <div class="fgrid">
        <div class="field">
          <label for="new">New password</label>
          <input id="new" name="new" type="password" minlength="8" required autocomplete="new-password">
        </div>
        <div class="field">
          <label for="new2">Repeat new password</label>
          <input id="new2" name="new2" type="password" minlength="8" required autocomplete="new-password">
        </div>
      </div>
    </div>
  </div>
  <div class="card__f"><button type="submit"><?= icon('shield') ?>Change password</button></div>
</form>

<aside>
  <section class="card">
    <div class="case-h"><?= avatar($acct['full_name'], 'av av--lg') ?><div><h2><?= e($acct['full_name']) ?></h2><p><?= e(role_label($acct['role'])) ?></p></div></div>
    <div class="card__b">
      <?= kv(array_filter([
            'Sign-in phone' => '<span class="mono">' . e($acct['phone']) . '</span>',
            'Email'         => $acct['email'] ? e($acct['email']) : null,
            'Patient number'=> $patientNo ? '<span class="mono">' . e($patientNo) . '</span>' : null,
            'Health centre' => $acct['facility'] ? e($acct['facility']) : null,
            'Member since'  => e(fmt_date($acct['created_at'])),
          ], static fn($v) => $v !== null) + ['Email' => $acct['email'] ? e($acct['email']) : null]) ?>
      <p class="hint">To change your name or phone number, ask the district administrator.</p>
    </div>
  </section>
</aside>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
