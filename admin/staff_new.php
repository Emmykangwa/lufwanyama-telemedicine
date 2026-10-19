<?php
/**
 * admin/staff_new.php -- register a doctor, health worker or administrator.
 *
 * This is the only way into a staff role. Clinical staff must belong to a
 * health centre. Unless the administrator types one, a temporary password is
 * generated and shown once on the next screen so it can be handed over.
 */
require_once __DIR__ . '/../includes/init.php';
$me = require_role(['admin']);

const STAFF_ROLES = ['doctor', 'health_worker', 'admin'];

$facilities = $pdo->query(
    "SELECT f.facility_id, f.name,
            COALESCE(SUM(u.role = 'doctor' AND u.is_active = 1), 0)        AS doctors,
            COALESCE(SUM(u.role = 'health_worker' AND u.is_active = 1), 0) AS workers
       FROM facilities f LEFT JOIN users u ON u.facility_id = f.facility_id
      WHERE f.is_active = 1
   GROUP BY f.facility_id, f.name ORDER BY f.name"
)->fetchAll();

$err = '';
$old = [
    'role'        => in_array($_GET['role'] ?? '', STAFF_ROLES, true) ? (string)$_GET['role'] : 'doctor',
    'full_name'   => '',
    'phone'       => '',
    'email'       => '',
    'facility_id' => (string)(int)($_GET['centre'] ?? 0),
    'pwmode'      => 'generate',
];
// One active centre: nothing to choose.
if ($old['facility_id'] === '0' && count($facilities) === 1) {
    $old['facility_id'] = (string)$facilities[0]['facility_id'];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $old['role']        = clean('role', 20);
    $old['full_name']   = clean('full_name', 120);
    $old['phone']       = normalize_phone(clean('phone', 20));
    $old['email']       = strtolower(clean('email', 120));
    $old['facility_id'] = clean('facility_id', 10);
    $old['pwmode']      = clean('pwmode', 10) === 'set' ? 'set' : 'generate';
    $fac = (int)$old['facility_id'];
    $pw  = $old['pwmode'] === 'set' ? (string)($_POST['password'] ?? '') : generate_password();

    $facOk = false;
    foreach ($facilities as $f) {
        $facOk = $facOk || (int)$f['facility_id'] === $fac;
    }

    if (!in_array($old['role'], STAFF_ROLES, true)) {
        $err = 'Choose a role.';
    } elseif (mb_strlen($old['full_name']) < 3) {
        $err = 'Enter the full name.';
    } elseif (!valid_phone($old['phone'])) {
        $err = 'Enter a valid phone number, for example 097 123 4567.';
    } elseif ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $err = 'That email address is not valid.';
    } elseif ($old['role'] !== 'admin' && !$facOk) {
        $err = 'Choose the health centre this ' . strtolower(role_label($old['role'])) . ' works at.';
    } elseif ($problem = password_problem($pw)) {
        $err = $problem;
    } else {
        try {
            $pdo->prepare(
                'INSERT INTO users (facility_id, full_name, phone, email, password_hash, role)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $old['role'] === 'admin' ? null : $fac,
                $old['full_name'],
                $old['phone'],
                $old['email'] !== '' ? $old['email'] : null,
                hash_password($pw),
                $old['role'],
            ]);
            $uid = (int)$pdo->lastInsertId();
            audit($pdo, 'CREATE', 'user', $uid, 'Role ' . $old['role'] . ', centre ' . ($old['role'] === 'admin' ? 'none' : $fac));

            // Shown once on the next page, then forgotten.
            $_SESSION['issued'] = ['user_id' => $uid, 'phone' => $old['phone'], 'password' => $pw, 'new' => true];
            flash('ok', role_label($old['role']) . ' account created for ' . $old['full_name'] . '.');
            redirect('/admin/staff_edit.php?id=' . $uid);
        } catch (PDOException $ex) {
            if ($ex->getCode() === SQLSTATE_UNIQUE_VIOLATION) {
                $err = 'That phone number or email already belongs to another account.';
            } else {
                error_log('Create staff failed: ' . $ex->getMessage());
                $err = 'Could not create the account. Nothing was saved.';
            }
        }
    }
}

$page_title = 'Add staff member';
$crumbs     = [['Staff', '/admin/staff.php']];
$nav_active = '/admin/staff.php';
require __DIR__ . '/../includes/header.php';
?>
<div class="grid grid-main">
<form method="post" class="card" id="staff-form">
  <?= csrf_field() ?>
  <?php if ($err): ?><div class="card__b"><div class="msg err" role="alert"><?= icon('alert') ?><span><?= e($err) ?></span></div></div><?php endif; ?>

  <div class="fsec">
    <div class="fsec__d"><h3>Role</h3><p>Decides what the person can see and do.</p></div>
    <div class="fsec__f">
      <div class="choice" role="radiogroup" aria-label="Role">
        <label><input type="radio" name="role" value="doctor"<?= $old['role'] === 'doctor' ? ' checked' : '' ?>>
          <div><b>Doctor</b><span>Reviews and answers requests at one centre</span></div></label>
        <label><input type="radio" name="role" value="health_worker"<?= $old['role'] === 'health_worker' ? ' checked' : '' ?>>
          <div><b>Health worker</b><span>Registers patients and sends requests</span></div></label>
        <label><input type="radio" name="role" value="admin"<?= $old['role'] === 'admin' ? ' checked' : '' ?>>
          <div><b>Administrator</b><span>Manages staff, centres and reports</span></div></label>
      </div>
    </div>
  </div>

  <div class="fsec">
    <div class="fsec__d"><h3>Details</h3><p>The phone number is what they sign in with.</p></div>
    <div class="fsec__f">
      <div class="field">
        <label for="full_name">Full name</label>
        <input id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" maxlength="120" required placeholder="e.g. Dr Chileshe Mwansa">
      </div>
      <div class="fgrid">
        <div class="field">
          <label for="phone">Phone number</label>
          <input id="phone" name="phone" inputmode="tel" value="<?= e($old['phone']) ?>" required placeholder="097 123 4567">
        </div>
        <div class="field">
          <label for="email">Email <span class="opt">(optional)</span></label>
          <input id="email" name="email" type="email" value="<?= e($old['email']) ?>" maxlength="120">
        </div>
      </div>
      <div class="field" id="centre-field" data-hide-when="role=admin">
        <label for="facility_id">Health centre</label>
        <select id="facility_id" name="facility_id">
          <option value="">Select a health centre</option>
<?php foreach ($facilities as $f): ?>
          <option value="<?= (int)$f['facility_id'] ?>"<?= (int)$f['facility_id'] === (int)$old['facility_id'] ? ' selected' : '' ?>><?= e($f['name']) ?></option>
<?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <div class="fsec">
    <div class="fsec__d"><h3>Sign-in password</h3><p>Shown once after saving so you can hand it over.</p></div>
    <div class="fsec__f">
      <div class="field">
        <label class="check"><input type="radio" name="pwmode" value="generate"<?= $old['pwmode'] === 'generate' ? ' checked' : '' ?>> Generate a temporary password</label>
        <label class="check"><input type="radio" name="pwmode" value="set"<?= $old['pwmode'] === 'set' ? ' checked' : '' ?>> Set one myself</label>
      </div>
      <div class="field" id="pw-field" data-show-when="pwmode=set">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" minlength="8" autocomplete="new-password">
      </div>
    </div>
  </div>

  <div class="card__f">
    <a class="btn btn--ghost" href="<?= e(url('/admin/staff.php')) ?>">Cancel</a>
    <button type="submit"><?= icon('userplus') ?>Create account</button>
  </div>
</form>

<aside>
  <section class="card">
    <header class="card__h"><h2>Staffing by centre</h2><a class="btn btn--link" href="<?= e(url('/admin/centres.php')) ?>">Centres</a></header>
<?php if (!$facilities): ?>
    <?= empty_state('No active health centre.', '<a class="btn btn--sm" href="' . e(url('/admin/centres.php')) . '">Add a health centre</a>', 'building') ?>
<?php else: ?>
    <table>
      <thead><tr><th>Centre</th><th class="num">Doctors</th><th class="num">Health workers</th></tr></thead>
      <tbody>
<?php foreach ($facilities as $f): ?>
        <tr>
          <td><?= e($f['name']) ?></td>
          <td class="num"><?= (int)$f['doctors'] ?: '<span class="badge declined">0</span>' ?></td>
          <td class="num"><?= (int)$f['workers'] ?: '<span class="badge declined">0</span>' ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
<?php endif; ?>
  </section>
</aside>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
