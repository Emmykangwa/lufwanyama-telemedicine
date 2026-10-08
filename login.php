<?php
/**
 * login.php -- phone number OR email, because many residents have no email.
 *
 * Protections: prepared statements, constant-time password verification, a
 * generic error message that does not say which half was wrong, a two-tier
 * throttle (per account and per address), and a fresh session id on success.
 */
require_once __DIR__ . '/includes/init.php';

if (current_user()) {
    redirect(dashboard_path(current_user()['role']));
}

// A rural clinic's staff all share one public address, so the per-account
// limit is the tight one and the per-address limit only catches a sweep.
const MAX_FAILURES_ACCOUNT = 8;    // per phone/email per 15 minutes
const MAX_FAILURES_ADDRESS = 40;   // per IP address per 15 minutes

$err = '';
$id  = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $id = clean('login_id', 120);
    $pw = (string)($_POST['password'] ?? '');

    $tooManyForAccount = $id !== '' && recent_login_failures($pdo, null, $id) >= MAX_FAILURES_ACCOUNT;
    $tooManyFromHere   = recent_login_failures($pdo, client_ip()) >= MAX_FAILURES_ADDRESS;

    if ($tooManyForAccount || $tooManyFromHere) {
        $err = 'Too many failed attempts. Please wait 15 minutes and try again.';
        audit($pdo, 'LOGIN_BLOCKED', 'user', null, login_detail($id));
    } elseif ($id === '' || $pw === '') {
        $err = 'Enter your phone number (or email) and your password.';
    } else {
        $st = $pdo->prepare(
            'SELECT u.user_id, u.full_name, u.role, u.facility_id, u.password_hash,
                    f.name AS facility
               FROM users u
          LEFT JOIN facilities f ON f.facility_id = u.facility_id
              WHERE (u.phone = ? OR u.email = lower(?))
                AND u.is_active
              LIMIT 1'
        );
        $st->execute([str_replace(' ', '', $id), $id]);
        $u = $st->fetch();

        if ($u && password_verify($pw, $u['password_hash'])) {
            // Upgrade the stored hash if the server now offers a stronger one.
            if (password_needs_rehash($u['password_hash'], password_algo())) {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                    ->execute([hash_password($pw), $u['user_id']]);
            }
            $pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE user_id = ?')
                ->execute([$u['user_id']]);

            login_user($u);
            audit($pdo, 'LOGIN', 'user', (int)$u['user_id'], 'Role ' . $u['role']);
            redirect(dashboard_path($u['role']));
        }

        // Same message whether the account exists or the password was wrong.
        audit($pdo, 'LOGIN_FAILED', 'user', null, login_detail($id));
        $err = 'Wrong phone number / email or password.';
    }
}

$page_title = t('login');
require __DIR__ . '/includes/header.php';
?>
<div class="auth">
  <div class="auth__head">
    <span class="brand__mark"><?= icon('cross') ?></span>
    <h1><?= e(t('login')) ?></h1>
    <p class="hint">Welcome back. Sign in to follow your appointments.</p>
  </div>
  <div class="card">
    <?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <label for="login_id"><?= e(t('login_id')) ?></label>
      <input id="login_id" name="login_id" value="<?= e($id) ?>" maxlength="120" required autofocus autocomplete="username">

      <label for="password"><?= e(t('password')) ?></label>
      <input id="password" name="password" type="password" required autocomplete="current-password">

      <button type="submit"><?= icon('login') ?><?= e(t('login')) ?></button>
    </form>

    <p class="hint">No account yet? <a href="<?= e(url('/register.php')) ?>"><?= e(t('register')) ?></a>.
       Staff accounts are issued by the administrator.</p>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
