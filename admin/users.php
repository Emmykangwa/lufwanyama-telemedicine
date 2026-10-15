<?php
/**
 * admin/users.php -- staff accounts and health centres (FR1).
 *
 * Only an administrator can create health worker, doctor and admin accounts.
 * Patients register themselves. Every change here is written to the audit log.
 */
require_once __DIR__ . '/../includes/init.php';
$me = require_role(['admin']);

const STAFF_ROLES = ['health_worker', 'doctor', 'admin'];

$err = '';
$old = ['full_name' => '', 'phone' => '', 'email' => '', 'role' => 'health_worker', 'facility_id' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');

    // ---- activate / deactivate ---------------------------------------
    if ($do === 'toggle') {
        $uid = (int)($_POST['user_id'] ?? 0);

        // Never leave the system without a way in.
        $st = $pdo->prepare('SELECT role, is_active FROM users WHERE user_id = ?');
        $st->execute([$uid]);
        $target = $st->fetch();
        $lastAdmin = false;
        if ($target && $target['role'] === 'admin' && (int)$target['is_active'] === 1) {
            $lastAdmin = (int)$pdo->query(
                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1"
            )->fetchColumn() <= 1;
        }

        if (!$target) {
            flash('err', 'No such account.');
        } elseif ($uid === (int)$me['user_id']) {
            flash('err', 'You cannot deactivate your own account.');
        } elseif ($lastAdmin) {
            flash('err', 'That is the only active administrator. Create another one first.');
        } else {
            $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE user_id = ?')->execute([$uid]);
            $now = (int)$target['is_active'] === 1 ? 0 : 1;
            audit($pdo, 'TOGGLE_ACTIVE', 'user', $uid, $now ? 'activated' : 'deactivated');
            flash('ok', 'Account ' . ($now ? 'activated.' : 'deactivated.'));
        }
        redirect('/admin/users.php');
    }

    // ---- reset a password --------------------------------------------
    if ($do === 'reset') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pw  = (string)($_POST['password'] ?? '');
        if ($problem = password_problem($pw)) {
            flash('err', $problem);
        } else {
            $st = $pdo->prepare('SELECT full_name FROM users WHERE user_id = ?');
            $st->execute([$uid]);
            $who = $st->fetchColumn();
            if ($who === false) {
                flash('err', 'No such account.');
            } else {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                    ->execute([hash_password($pw), $uid]);
                audit($pdo, 'RESET_PASSWORD', 'user', $uid, 'By administrator');
                flash('ok', 'New password set for ' . $who . '. Tell them to change it after logging in.');
            }
        }
        redirect('/admin/users.php');
    }

    // ---- add a health centre -----------------------------------------
    if ($do === 'facility') {
        $name = clean('facility_name', 120);
        $loc  = clean('facility_location', 120);
        if (mb_strlen($name) < 3) {
            flash('err', 'Give the health centre a name.');
        } else {
            try {
                $pdo->prepare('INSERT INTO facilities (name, location) VALUES (?, ?)')
                    ->execute([$name, $loc !== '' ? $loc : null]);
                audit($pdo, 'CREATE', 'facility', (int)$pdo->lastInsertId(), $name);
                flash('ok', 'Health centre added.');
            } catch (PDOException $ex) {
                flash('err', $ex->getCode() === SQLSTATE_UNIQUE_VIOLATION
                    ? 'A health centre with that name already exists.'
                    : 'Could not add the health centre.');
            }
        }
        redirect('/admin/users.php');
    }

    // ---- create a staff account --------------------------------------
    $old['full_name']   = clean('full_name', 120);
    $old['phone']       = str_replace(' ', '', clean('phone', 20));
    $old['email']       = strtolower(clean('email', 120));
    $old['role']        = clean('role', 20);
    $old['facility_id'] = clean('facility_id', 10);
    $pw  = (string)($_POST['password'] ?? '');
    $fac = (int)$old['facility_id'];

    if (mb_strlen($old['full_name']) < 3) {
        $err = 'Enter the full name.';
    } elseif (!valid_phone($old['phone'])) {
        $err = 'Enter a valid phone number (9 to 15 digits).';
    } elseif ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $err = 'That email address is not valid.';
    } elseif (!in_array($old['role'], STAFF_ROLES, true)) {
        $err = 'Choose a role.';
    } elseif ($old['role'] !== 'admin' && $fac === 0) {
        $err = 'Health workers and doctors must belong to a health centre.';
    } elseif ($problem = password_problem($pw)) {
        $err = $problem;
    } else {
        try {
            $st = $pdo->prepare(
                'INSERT INTO users (facility_id, full_name, phone, email, password_hash, role)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $old['role'] === 'admin' ? ($fac ?: null) : $fac,
                $old['full_name'],
                $old['phone'],
                $old['email'] !== '' ? $old['email'] : null,
                hash_password($pw),
                $old['role'],
            ]);
            audit($pdo, 'CREATE', 'user', (int)$pdo->lastInsertId(), 'Role ' . $old['role'] . ', centre ' . ($fac ?: 'none'));
            flash('ok', 'Account created for ' . $old['full_name'] . '.');
            redirect('/admin/users.php');
        } catch (PDOException $ex) {
            if ($ex->getCode() === SQLSTATE_UNIQUE_VIOLATION) {
                $err = 'That phone number or email already belongs to another account.';
            } else {
                error_log('Create staff failed: ' . $ex->getMessage());
                $err = 'Could not create the account.';
            }
        }
    }
}

$facilities = $pdo->query(
    "SELECT f.facility_id, f.name, f.location, f.is_active,
            (SELECT COUNT(*) FROM users u WHERE u.facility_id = f.facility_id) AS staff
       FROM facilities f ORDER BY f.name"
)->fetchAll();

$users = $pdo->query(
    "SELECT u.user_id, u.full_name, u.phone, u.email, u.role,
            u.is_active, u.last_login_at, f.name AS facility
       FROM users u LEFT JOIN facilities f ON f.facility_id = u.facility_id
   ORDER BY u.role, u.full_name"
)->fetchAll();

$page_title = t('users');
require __DIR__ . '/../includes/header.php';
?>
<div class="card card--form">
  <h2>Create a staff account</h2>
  <?php if ($err): ?><div class="msg err"><?= icon('alert') ?><span><?= e($err) ?></span></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="staff">

    <label for="full_name"><?= e(t('full_name')) ?></label>
    <input id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" maxlength="120" required>

    <div class="fgrid">
      <div class="fld">
        <label for="phone"><?= e(t('phone')) ?></label>
        <input id="phone" name="phone" inputmode="tel" pattern="\+?[0-9]{9,15}" value="<?= e($old['phone']) ?>" required>
      </div>
      <div class="fld">
        <label for="email"><?= e(t('email')) ?></label>
        <input id="email" name="email" type="email" value="<?= e($old['email']) ?>" maxlength="120">
      </div>
    </div>

    <div class="fgrid">
      <div class="fld">
        <label for="role"><?= e(t('role')) ?></label>
        <select id="role" name="role">
          <option value="health_worker"<?= $old['role'] === 'health_worker' ? ' selected' : '' ?>>Health worker</option>
          <option value="doctor"<?= $old['role'] === 'doctor' ? ' selected' : '' ?>>Doctor</option>
          <option value="admin"<?= $old['role'] === 'admin' ? ' selected' : '' ?>>Administrator</option>
        </select>
      </div>
      <div class="fld">
        <label for="facility_id"><?= e(t('centre')) ?></label>
        <select id="facility_id" name="facility_id">
          <option value="0">-- none (administrator only) --</option>
<?php foreach ($facilities as $f): if (!(int)$f['is_active']) { continue; } ?>
          <option value="<?= (int)$f['facility_id'] ?>"<?= (int)$f['facility_id'] === (int)$old['facility_id'] ? ' selected' : '' ?>>
            <?= e($f['name']) ?>
          </option>
<?php endforeach; ?>
        </select>
      </div>
    </div>
    <p class="hint">Health workers and doctors both belong to a health centre.</p>

    <label for="password"><?= e(t('password')) ?> (at least 8 characters)</label>
    <input id="password" name="password" type="password" minlength="8" required autocomplete="new-password">

    <button type="submit"><?= icon('save') ?><?= e(t('save')) ?></button>
  </form>
</div>

<h2>All accounts (<?= count($users) ?>)</h2>
<div class="scroll">
<table>
  <tr><th>Name</th><th><?= e(t('phone')) ?></th><th><?= e(t('role')) ?></th><th><?= e(t('centre')) ?></th>
      <th>Last login</th><th>Active</th><th class="noprint"></th></tr>
<?php foreach ($users as $u): ?>
  <tr>
    <td><?= e($u['full_name']) ?><?= $u['email'] ? '<br><small>' . e($u['email']) . '</small>' : '' ?></td>
    <td><?= e($u['phone']) ?></td>
    <td><?= e(str_replace('_', ' ', $u['role'])) ?></td>
    <td><?= e($u['facility'] ?? '-') ?></td>
    <td><?= e($u['last_login_at'] ? substr((string)$u['last_login_at'], 0, 16) : 'never') ?></td>
    <td><?= (int)$u['is_active']
            ? '<span class="yes" title="Active">' . icon('check') . '</span>'
            : '<span class="no" title="Inactive">' . icon('close') . '</span>' ?></td>
    <td class="noprint">
      <form method="post" class="inline" data-confirm="Change the active state of this account?">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="toggle">
        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
        <button type="submit" class="alt mini"><?= (int)$u['is_active'] ? 'Deactivate' : 'Activate' ?></button>
      </form>
    </td>
  </tr>
<?php endforeach; ?>
</table>
</div>

<div class="card card--form noprint">
  <h2>Reset a password</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="reset">
    <label for="reset_user">Account</label>
    <select id="reset_user" name="user_id" required>
<?php foreach ($users as $u): ?>
      <option value="<?= (int)$u['user_id'] ?>"><?= e($u['full_name']) ?> (<?= e($u['phone']) ?>, <?= e(str_replace('_', ' ', $u['role'])) ?>)</option>
<?php endforeach; ?>
    </select>
    <label for="newpw">New password</label>
    <input id="newpw" name="password" type="password" minlength="8" required autocomplete="new-password">
    <button type="submit" class="alt">Set password</button>
  </form>
</div>

<div class="card">
  <h2><?= e(t('facilities')) ?></h2>
  <div class="scroll">
  <table>
    <tr><th>Name</th><th>Location</th><th>Staff</th><th>Active</th></tr>
<?php foreach ($facilities as $f): ?>
    <tr>
      <td><?= e($f['name']) ?></td>
      <td><?= e($f['location'] ?? '-') ?></td>
      <td><?= (int)$f['staff'] ?></td>
      <td><?= (int)$f['is_active']
              ? '<span class="yes" title="Active">' . icon('check') . '</span>'
              : '<span class="no" title="Inactive">' . icon('close') . '</span>' ?></td>
    </tr>
<?php endforeach; ?>
  </table>
  </div>

  <h3 class="noprint">Add a health centre</h3>
  <form method="post" class="noprint">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="facility">
    <label for="facility_name">Name</label>
    <input id="facility_name" name="facility_name" maxlength="120" required>
    <label for="facility_location">Location</label>
    <input id="facility_location" name="facility_location" maxlength="120">
    <button type="submit" class="alt">Add health centre</button>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
