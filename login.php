<?php
/**
 * login.php -- phone number OR email, because many residents have no email.
 *
 * Protections: prepared statements, constant-time password verification, a
 * generic error message that does not say which half was wrong, a two-tier
 * throttle (per account and per address), and a fresh session id on success.
 *
 * Phone numbers are matched in every common spelling ("097 000 0001",
 * "+260970000001", "970000001"), so a correct password is never refused
 * because of how the number was typed.
 */
require_once __DIR__ . '/includes/init.php';

if (current_user()) {
    redirect(dashboard_path(current_user()['role']));
}

// A rural clinic's staff all share one public address, so the per-account
// limit is the tight one and the per-address limit only catches a sweep.
const MAX_FAILURES_ACCOUNT = 8;    // per phone/email per 15 minutes
const MAX_FAILURES_ADDRESS = 40;   // per IP address per 15 minutes

// Development-only demo logins (tools/demo_accounts.php creates them).
const DEMO_ACCOUNTS = [
    ['Administrator', '0970000001', 'Admin@2026'],
    ['Health worker', '0970000002', 'Health@2026'],
    ['Doctor',        '0970000003', 'Doctor@2026'],
    ['Patient',       '0970000004', 'Patient@2026'],
];

$err = '';
$id  = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $id = clean('login_id', 120);
    $pw = (string)($_POST['password'] ?? '');

    $key = str_contains($id, '@') ? strtolower($id) : normalize_phone($id);

    $tooManyForAccount = $key !== '' && recent_login_failures($pdo, null, $key) >= MAX_FAILURES_ACCOUNT;
    $tooManyFromHere   = recent_login_failures($pdo, client_ip()) >= MAX_FAILURES_ADDRESS;

    if ($tooManyForAccount || $tooManyFromHere) {
        $err = 'Too many failed attempts for this account. Please wait 15 minutes and try again.';
        audit($pdo, 'LOGIN_BLOCKED', 'user', null, login_detail($key));
    } elseif ($id === '' || $pw === '') {
        $err = 'Enter your phone number (or email) and your password.';
    } else {
        // Every stored spelling the typed number could correspond to.
        $local = normalize_phone($id);
        $variants = [$local];
        if (preg_match('/^0([0-9]{9})$/', $local, $m)) {
            $variants[] = '+260' . $m[1];
            $variants[] = '260' . $m[1];
        }
        $marks = implode(',', array_fill(0, count($variants), '?'));

        $st = $pdo->prepare(
            "SELECT u.user_id, u.full_name, u.role, u.facility_id, u.password_hash, u.is_active,
                    f.name AS facility
               FROM users u
          LEFT JOIN facilities f ON f.facility_id = u.facility_id
              WHERE u.phone IN ($marks) OR u.email = lower(?)
              LIMIT 1"
        );
        $st->execute([...$variants, $id]);
        $u = $st->fetch();

        if ($u && password_verify($pw, $u['password_hash'])) {
            if (!(int)$u['is_active']) {
                audit($pdo, 'LOGIN_FAILED', 'user', (int)$u['user_id'], login_detail($key));
                $err = 'This account has been deactivated. Contact the district administrator.';
            } else {
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
        } else {
            // Same message whether the account exists or the password was wrong.
            audit($pdo, 'LOGIN_FAILED', 'user', null, login_detail($key));
            $err = 'That phone number / email and password do not match an account.';
        }
    }
}

// In development, list the demo accounts whose documented password really
// works right now, so the credentials on screen can never be stale.
$demo = [];
if (APP_ENV === 'development') {
    $st = $pdo->prepare('SELECT password_hash FROM users WHERE phone = ? AND is_active = 1');
    foreach (DEMO_ACCOUNTS as [$label, $phone, $plain]) {
        $st->execute([$phone]);
        $hash = $st->fetchColumn();
        if ($hash !== false && password_verify($plain, (string)$hash)) {
            $demo[] = [$label, $phone, $plain];
        }
    }
}

$page_title = 'Sign in';
require __DIR__ . '/includes/header.php';
?>
<div class="auth">
  <aside class="auth__aside">
    <h2>Consult a doctor before you make the journey.</h2>
    <ul class="auth__list">
      <li><?= icon('send') ?><div><b>Requests reach the doctor first</b>Health workers send clinical notes ahead of the visit.</div></li>
      <li><?= icon('check') ?><div><b>Travel only when confirmed</b>Patients follow the status of every request.</div></li>
      <li><?= icon('signal') ?><div><b>Built for a weak signal</b>Forms are kept on the phone if the network drops.</div></li>
    </ul>
    <p class="auth__foot">Lufwanyama District Health Office &middot; Copperbelt Province</p>
  </aside>

  <div class="auth__main">
    <h1>Sign in</h1>
    <p class="muted">Use the phone number your account was created with.</p>

    <?php if ($err): ?><div class="msg err" role="alert"><?= icon('alert') ?><span><?= e($err) ?></span></div><?php endif; ?>

    <form method="post" id="login-form">
      <?= csrf_field() ?>
      <div class="field">
        <label for="login_id">Phone number or email</label>
        <input id="login_id" name="login_id" value="<?= e($id) ?>" maxlength="120" required autofocus
               autocomplete="username" placeholder="e.g. 097 123 4567">
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn--block"><?= icon('login') ?>Sign in</button>
    </form>

    <p class="hint">Patients: no account yet? <a href="<?= e(url('/register.php')) ?>">Create one</a>.<br>
       Doctors and health workers: your account is created by the district administrator.</p>

<?php if ($demo): ?>
    <div class="demo noprint">
      <div class="demo__h">Demo accounts <span>development mode only</span></div>
      <div class="demo__grid">
<?php foreach ($demo as [$label, $phone, $plain]): ?>
        <button type="button" data-fill-login="<?= e($phone) ?>" data-fill-pw="<?= e($plain) ?>">
          <b><?= e($label) ?></b><span><?= e($phone) ?> · <?= e($plain) ?></span>
        </button>
<?php endforeach; ?>
      </div>
    </div>
<?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
