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
    $old['phone']     = normalize_phone(clean('phone', 20));
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

            flash('ok', 'Account created. Your patient number is ' . $pno . '. Sign in with your phone number.');
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

$page_title = 'Create a patient account';
require __DIR__ . '/includes/header.php';
?>
<div class="auth">
  <aside class="auth__aside">
    <h2>Request an appointment without leaving your village.</h2>
    <ul class="auth__list">
      <li><?= icon('calendar') ?><div><b>Choose a centre and a date</b>A doctor at the health centre answers your request.</div></li>
      <li><?= icon('bell') ?><div><b>Follow the reply</b>Confirmed, a new date, or advice &mdash; before you spend on transport.</div></li>
      <li><?= icon('shield') ?><div><b>Your record stays private</b>Only you and the clinical staff caring for you can see it.</div></li>
    </ul>
    <p class="auth__foot">This form is for patients. Doctors and health workers are registered by the district administrator.</p>
  </aside>

  <div class="auth__main">
    <h1>Create a patient account</h1>
    <p class="muted">Already registered? <a href="<?= e(url('/login.php')) ?>">Sign in</a>.</p>

    <?php if ($err): ?><div class="msg err" role="alert"><?= icon('alert') ?><span><?= e($err) ?></span></div><?php endif; ?>

    <form method="post" data-offline="register" autocomplete="on">
      <?= csrf_field() ?>

      <div class="field">
        <label for="full_name">Full name</label>
        <input id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" maxlength="120" required autocomplete="name">
      </div>

      <div class="fgrid">
        <div class="field">
          <label for="phone">Phone number</label>
          <input id="phone" name="phone" inputmode="tel" value="<?= e($old['phone']) ?>" required autocomplete="tel" placeholder="097 123 4567">
        </div>
        <div class="field">
          <label for="email">Email <span class="opt">(optional)</span></label>
          <input id="email" name="email" type="email" value="<?= e($old['email']) ?>" maxlength="120" autocomplete="email">
        </div>
      </div>

      <div class="fgrid fgrid--3">
        <div class="field">
          <label for="dob">Date of birth</label>
          <input id="dob" name="dob" type="date" max="<?= e(today()) ?>" value="<?= e($old['dob']) ?>">
        </div>
        <div class="field">
          <label for="sex">Sex</label>
          <select id="sex" name="sex">
            <option value="">&mdash;</option>
            <option value="F"<?= $old['sex'] === 'F' ? ' selected' : '' ?>>Female</option>
            <option value="M"<?= $old['sex'] === 'M' ? ' selected' : '' ?>>Male</option>
          </select>
        </div>
        <div class="field">
          <label for="village">Village</label>
          <input id="village" name="village" value="<?= e($old['village']) ?>" maxlength="100">
        </div>
      </div>

      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" minlength="8" required autocomplete="new-password">
        <p class="hint">At least 8 characters. Mix letters, numbers or symbols.</p>
      </div>

      <button type="submit" class="btn--block"><?= icon('check') ?>Create account</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
