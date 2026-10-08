<?php
/**
 * register.php -- patient self-registration (FR1)
 *
 * Creates a users row (role 'patient') and the matching patients row in one
 * transaction, so a half-registered person can never exist.
 */
require_once __DIR__ . '/includes/init.php';

if (current_user()) {
    redirect(dashboard_path(current_user()['role']));
}

$err = '';
$old = ['full_name' => '', 'phone' => '', 'email' => '', 'dob' => '', 'sex' => '', 'village' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    $old['full_name'] = clean('full_name', 120);
    $old['phone']     = str_replace(' ', '', clean('phone', 20));
    $old['email']     = strtolower(clean('email', 120));
    $old['dob']       = clean('dob', 10);
    $old['sex']       = clean('sex', 1);
    $old['village']   = clean('village', 100);
    $pw               = (string)($_POST['password'] ?? '');

    if (mb_strlen($old['full_name']) < 3) {
        $err = 'Please enter your full name.';
    } elseif (!valid_phone($old['phone'])) {
        $err = 'Enter a valid phone number: 9 to 15 digits, optionally starting with +.';
    } elseif ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $err = 'That email address is not valid.';
    } elseif ($old['dob'] !== '' && (!valid_date($old['dob']) || $old['dob'] > today())) {
        $err = 'The date of birth is not a valid past date.';
    } elseif ($problem = password_problem($pw)) {
        $err = $problem;
    } else {
        try {
            $pdo->beginTransaction();

            $st = $pdo->prepare(
                "INSERT INTO users (full_name, phone, email, password_hash, role)
                 VALUES (?, ?, ?, ?, 'patient')"
            );
            $st->execute([
                $old['full_name'],
                $old['phone'],
                $old['email'] !== '' ? $old['email'] : null,
                hash_password($pw),          // one-way hash, never the password
            ]);
            $uid = (int)$pdo->lastInsertId();

            $pno = new_patient_number($pdo);
            $st  = $pdo->prepare(
                'INSERT INTO patients (user_id, patient_number, full_name, dob, sex, phone, village)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $uid,
                $pno,
                $old['full_name'],
                date_or_null($old['dob']),
                in_array($old['sex'], ['F', 'M'], true) ? $old['sex'] : null,
                $old['phone'],
                $old['village'] !== '' ? $old['village'] : null,
            ]);
            $pid = (int)$pdo->lastInsertId();

            audit($pdo, 'REGISTER', 'patient', $pid, 'Self-registration, ' . $pno, $uid);

            $pdo->commit();

            flash('ok', 'Account created. Your patient number is ' . $pno . '. Please log in.');
            redirect('/login.php');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($ex->getCode() === SQLSTATE_UNIQUE_VIOLATION) {
                $err = 'That phone number or email is already registered. Try logging in instead.';
            } else {
                error_log('Registration failed: ' . $ex->getMessage());
                $err = 'Could not save your details. Please try again.';
            }
        }
    }
}

$page_title = t('register');
require __DIR__ . '/includes/header.php';
?>
<div class="auth">
  <div class="auth__head">
    <span class="brand__mark"><?= icon('cross') ?></span>
    <h1><?= e(t('register')) ?></h1>
    <p class="hint">Patients register themselves here. Staff accounts are created by the administrator.</p>
  </div>
  <div class="card">
  <?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>

  <form method="post" data-offline="register" autocomplete="on">
    <?= csrf_field() ?>

    <label for="full_name"><?= e(t('full_name')) ?></label>
    <input id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" maxlength="120" required autocomplete="name">

    <label for="phone"><?= e(t('phone')) ?></label>
    <input id="phone" name="phone" inputmode="tel" pattern="\+?[0-9]{9,15}" value="<?= e($old['phone']) ?>" required autocomplete="tel">

    <label for="email"><?= e(t('email')) ?></label>
    <input id="email" name="email" type="email" value="<?= e($old['email']) ?>" maxlength="120" autocomplete="email">

    <label for="dob"><?= e(t('dob')) ?></label>
    <input id="dob" name="dob" type="date" max="<?= e(today()) ?>" value="<?= e($old['dob']) ?>">

    <label for="sex"><?= e(t('sex')) ?></label>
    <select id="sex" name="sex">
      <option value="">--</option>
      <option value="F"<?= $old['sex'] === 'F' ? ' selected' : '' ?>><?= e(t('female')) ?></option>
      <option value="M"<?= $old['sex'] === 'M' ? ' selected' : '' ?>><?= e(t('male')) ?></option>
    </select>

    <label for="village"><?= e(t('village')) ?></label>
    <input id="village" name="village" value="<?= e($old['village']) ?>" maxlength="100">

    <label for="password"><?= e(t('password')) ?> (at least 8 characters)</label>
    <input id="password" name="password" type="password" minlength="8" required autocomplete="new-password">

    <button type="submit"><?= icon('check') ?><?= e(t('register')) ?></button>
  </form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
