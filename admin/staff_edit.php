<?php
/**
 * admin/staff_edit.php -- one account: profile, centre, password, access.
 *
 * Works for staff and for patient logins (a patient's role and centre are
 * not changed here). Guards: an administrator cannot demote or deactivate
 * themselves, and the last active administrator cannot be removed, so the
 * system always keeps a way in. Every change is written to the audit log.
 */
require_once __DIR__ . '/../includes/init.php';
$me = require_role(['admin']);

const STAFF_ROLES = ['doctor', 'health_worker', 'admin'];

$id = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);
$load = static function (PDO $pdo, int $id): ?array {
    $st = $pdo->prepare(
        'SELECT u.*, f.name AS facility FROM users u
      LEFT JOIN facilities f ON f.facility_id = u.facility_id WHERE u.user_id = ?'
    );
    $st->execute([$id]);
    return $st->fetch() ?: null;
};
$acct = $load($pdo, $id);
if (!$acct) {
    flash('err', 'That account does not exist.');
    redirect('/admin/staff.php');
}
$isStaff = in_array($acct['role'], STAFF_ROLES, true);
$isSelf  = (int)$acct['user_id'] === (int)$me['user_id'];
$back    = $isStaff ? '/admin/staff.php' : '/admin/patients.php';

$activeAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
$lastAdmin    = $acct['role'] === 'admin' && (int)$acct['is_active'] === 1 && $activeAdmins <= 1;

$facilities = $pdo->query('SELECT facility_id, name, is_active FROM facilities ORDER BY name')->fetchAll();

$err = '';
$old = [
    'full_name'   => (string)$acct['full_name'],
    'phone'       => (string)$acct['phone'],
    'email'       => (string)($acct['email'] ?? ''),
    'role'        => (string)$acct['role'],
    'facility_id' => (string)($acct['facility_id'] ?? ''),
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');

    if ($do === 'profile') {
        $old['full_name'] = clean('full_name', 120);
        $old['phone']     = normalize_phone(clean('phone', 20));
        $old['email']     = strtolower(clean('email', 120));
        if ($isStaff) {
            $old['role']        = clean('role', 20);
            $old['facility_id'] = clean('facility_id', 10);
        }
        $fac = (int)$old['facility_id'];

        if (mb_strlen($old['full_name']) < 3) {
            $err = 'Enter the full name.';
        } elseif (!valid_phone($old['phone'])) {
            $err = 'Enter a valid phone number, for example 097 123 4567.';
        } elseif ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $err = 'That email address is not valid.';
        } elseif ($isStaff && !in_array($old['role'], STAFF_ROLES, true)) {
            $err = 'Choose a role.';
        } elseif ($isStaff && $old['role'] !== 'admin' && $fac === 0) {
            $err = 'Doctors and health workers must belong to a health centre.';
        } elseif ($acct['role'] === 'admin' && $old['role'] !== 'admin' && ($isSelf || $lastAdmin)) {
            $err = $isSelf ? 'You cannot remove your own administrator role.'
                           : 'This is the only active administrator. Create another one first.';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE users SET full_name = ?, phone = ?, email = ?, role = ?, facility_id = ? WHERE user_id = ?')
                    ->execute([
                        $old['full_name'], $old['phone'], $old['email'] !== '' ? $old['email'] : null,
                        $isStaff ? $old['role'] : $acct['role'],
                        $isStaff ? ($old['role'] === 'admin' ? null : $fac) : $acct['facility_id'],
                        $id,
                    ]);
                if (!$isStaff) {
                    // Keep the patient record's name and phone in step with the login.
                    $pdo->prepare('UPDATE patients SET full_name = ?, phone = ? WHERE user_id = ?')
                        ->execute([$old['full_name'], $old['phone'], $id]);
                }
                $changes = [];
                foreach (['full_name' => 'name', 'phone' => 'phone', 'email' => 'email', 'role' => 'role', 'facility_id' => 'centre'] as $k => $label) {
                    if ((string)($acct[$k] ?? '') !== (string)($k === 'facility_id' && $old['role'] === 'admin' ? '' : $old[$k])) {
                        $changes[] = $label;
                    }
                }
                audit($pdo, 'UPDATE', 'user', $id, $changes ? 'Changed ' . implode(', ', $changes) : 'No change');
                $pdo->commit();
                flash('ok', 'Changes saved.');
                redirect('/admin/staff_edit.php?id=' . $id);
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $err = $ex->getCode() === SQLSTATE_UNIQUE_VIOLATION
                    ? 'That phone number or email already belongs to another account.'
                    : 'Could not save the changes.';
            }
        }
    }

    if ($do === 'password') {
        $pw = clean('pwmode', 10) === 'set' ? (string)($_POST['password'] ?? '') : generate_password();
        if ($problem = password_problem($pw)) {
            flash('err', $problem);
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')->execute([hash_password($pw), $id]);
            audit($pdo, 'RESET_PASSWORD', 'user', $id, 'By administrator');
            $_SESSION['issued'] = ['user_id' => $id, 'phone' => $acct['phone'], 'password' => $pw, 'new' => false];
            flash('ok', 'New password set for ' . $acct['full_name'] . '.');
        }
        redirect('/admin/staff_edit.php?id=' . $id);
    }

    if ($do === 'toggle') {
        if ($isSelf) {
            flash('err', 'You cannot deactivate your own account.');
        } elseif ($lastAdmin) {
            flash('err', 'This is the only active administrator. Create another one first.');
        } else {
            $now = (int)$acct['is_active'] === 1 ? 0 : 1;
            $pdo->prepare('UPDATE users SET is_active = ? WHERE user_id = ?')->execute([$now, $id]);
            audit($pdo, 'TOGGLE_ACTIVE', 'user', $id, $now ? 'activated' : 'deactivated');
            flash('ok', $acct['full_name'] . ($now ? ' can sign in again.' : ' can no longer sign in.'));
        }
        redirect('/admin/staff_edit.php?id=' . $id);
    }
}

// One-time credentials from the create or reset step.
$issued = null;
if (!empty($_SESSION['issued']) && (int)$_SESSION['issued']['user_id'] === $id) {
    $issued = $_SESSION['issued'];
}
unset($_SESSION['issued']);

// Context for the side panel.
$activity = $pdo->prepare(
    'SELECT l.created_at, l.action, l.entity, l.details, COALESCE(u.full_name, \'system\') AS who
       FROM audit_logs l LEFT JOIN users u ON u.user_id = l.user_id
      WHERE l.user_id = ? OR (l.entity = \'user\' AND l.entity_id = ?)
   ORDER BY l.log_id DESC LIMIT 6'
);
$activity->execute([$id, $id]);
$activity = $activity->fetchAll();

$work = null;
if ($acct['role'] === 'doctor') {
    $st = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE answered_by = ?");
    $st->execute([$id]);
    $answered = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE facility_id = ? AND status = 'pending' AND (doctor_id IS NULL OR doctor_id = ?)");
    $st->execute([(int)$acct['facility_id'], $id]);
    $work = ['Requests answered' => (string)$answered, 'Waiting in queue' => (string)(int)$st->fetchColumn()];
} elseif ($acct['role'] === 'health_worker') {
    $st = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE booked_by = ?");
    $st->execute([$id]);
    $work = ['Requests sent' => (string)(int)$st->fetchColumn()];
} elseif ($acct['role'] === 'patient') {
    $st = $pdo->prepare('SELECT p.patient_number, f.name AS centre, (SELECT COUNT(*) FROM appointments a WHERE a.patient_id = p.patient_id) AS n
                           FROM patients p LEFT JOIN facilities f ON f.facility_id = p.facility_id WHERE p.user_id = ?');
    $st->execute([$id]);
    if ($p = $st->fetch()) {
        $work = ['Patient number' => '<span class="mono">' . e($p['patient_number']) . '</span>',
                 'Registered at'  => $p['centre'] ? e($p['centre']) : '<span class="muted">Not attached</span>',
                 'Appointments'   => (string)(int)$p['n']];
    }
}

$page_title = $acct['full_name'];
$page_sub   = role_tag($acct['role']) . ' &nbsp;' . state_pill((bool)$acct['is_active'])
            . ($acct['facility'] ? ' &nbsp;<span>' . e($acct['facility']) . '</span>' : '');
$crumbs     = [[$isStaff ? 'Staff' : 'Patients', $back]];
$nav_active = $back;
require __DIR__ . '/../includes/header.php';
?>
<?php if ($issued): ?>
<div class="cred" role="status">
  <h2><?= icon('shield') ?><?= $issued['new'] ? 'Account ready: hand these sign-in details over' : 'New sign-in details' ?></h2>
  <p>This password is shown only once. Ask them to change it from <b>My account</b> after signing in.</p>
  <dl><dt>Sign in at</dt><dd><?= e(($_SERVER['HTTP_HOST'] ?? 'localhost') . url('/login.php')) ?></dd>
      <dt>Phone number</dt><dd><?= e($issued['phone']) ?></dd>
      <dt>Password</dt><dd><?= e($issued['password']) ?></dd></dl>
  <p class="actions noprint"><button type="button" class="btn--ghost btn--sm" data-print="1"><?= icon('printer') ?>Print slip</button></p>
</div>
<?php endif; ?>

<div class="grid grid-main">
<div>
  <form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="profile">
    <input type="hidden" name="user_id" value="<?= $id ?>">
    <header class="card__h"><h2>Profile</h2></header>
    <?php if ($err): ?><div class="card__b"><div class="msg err" role="alert"><?= icon('alert') ?><span><?= e($err) ?></span></div></div><?php endif; ?>
    <div class="fsec">
      <div class="fsec__d"><h3>Details</h3><p>The phone number is the sign-in name.</p></div>
      <div class="fsec__f">
        <div class="field">
          <label for="full_name">Full name</label>
          <input id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" maxlength="120" required>
        </div>
        <div class="fgrid">
          <div class="field">
            <label for="phone">Phone number</label>
            <input id="phone" name="phone" inputmode="tel" value="<?= e($old['phone']) ?>" required>
          </div>
          <div class="field">
            <label for="email">Email <span class="opt">(optional)</span></label>
            <input id="email" name="email" type="email" value="<?= e($old['email']) ?>" maxlength="120">
          </div>
        </div>
      </div>
    </div>
<?php if ($isStaff): ?>
    <div class="fsec">
      <div class="fsec__d"><h3>Role and centre</h3><p>Moving a doctor moves their queue to the new centre.</p></div>
      <div class="fsec__f">
        <div class="fgrid">
          <div class="field">
            <label for="role">Role</label>
            <select id="role" name="role"<?= $isSelf ? ' disabled' : '' ?>>
<?php foreach (STAFF_ROLES as $r): ?>
              <option value="<?= $r ?>"<?= $old['role'] === $r ? ' selected' : '' ?>><?= e(role_label($r)) ?></option>
<?php endforeach; ?>
            </select>
<?php if ($isSelf): ?><input type="hidden" name="role" value="<?= e($old['role']) ?>"><?php endif; ?>
          </div>
          <div class="field" data-hide-when="role=admin">
            <label for="facility_id">Health centre</label>
            <select id="facility_id" name="facility_id">
              <option value="">Select a health centre</option>
<?php foreach ($facilities as $f): ?>
              <option value="<?= (int)$f['facility_id'] ?>"<?= (int)$f['facility_id'] === (int)$old['facility_id'] ? ' selected' : '' ?>><?= e($f['name']) ?><?= (int)$f['is_active'] ? '' : ' (inactive)' ?></option>
<?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>
<?php endif; ?>
    <div class="card__f"><button type="submit"><?= icon('save') ?>Save changes</button></div>
  </form>

  <form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="password">
    <input type="hidden" name="user_id" value="<?= $id ?>">
    <header class="card__h"><h2>Password</h2></header>
    <div class="fsec">
      <div class="fsec__d"><h3>Reset password</h3><p>For a forgotten password. The old one stops working at once.</p></div>
      <div class="fsec__f">
        <div class="field">
          <label class="check"><input type="radio" name="pwmode" value="generate" checked> Generate a temporary password</label>
          <label class="check"><input type="radio" name="pwmode" value="set"> Set one myself</label>
        </div>
        <div class="field" data-show-when="pwmode=set">
          <label for="newpw">New password</label>
          <input id="newpw" name="password" type="password" minlength="8" autocomplete="new-password">
        </div>
      </div>
    </div>
    <div class="card__f"><button type="submit" class="btn--ghost"><?= icon('shield') ?>Reset password</button></div>
  </form>

  <section class="card<?= (int)$acct['is_active'] ? ' card--bad' : '' ?>">
    <header class="card__h"><h2>Access</h2></header>
    <div class="fsec">
      <div class="fsec__d"><h3><?= (int)$acct['is_active'] ? 'Deactivate account' : 'Reactivate account' ?></h3>
        <p><?= (int)$acct['is_active'] ? 'They can no longer sign in. Their records and history are kept.' : 'They will be able to sign in again with their current password.' ?></p></div>
      <div class="fsec__f">
<?php if ($isSelf): ?>
        <p class="muted">This is your own account.</p>
<?php elseif ($lastAdmin): ?>
        <p class="muted">This is the only active administrator, so it cannot be deactivated.</p>
<?php else: ?>
        <form method="post" data-confirm="<?= (int)$acct['is_active'] ? 'Deactivate ' . e($acct['full_name']) . '? They will not be able to sign in.' : 'Reactivate ' . e($acct['full_name']) . '?' ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="toggle">
          <input type="hidden" name="user_id" value="<?= $id ?>">
          <button type="submit" class="<?= (int)$acct['is_active'] ? 'btn--danger' : '' ?>"><?= (int)$acct['is_active'] ? 'Deactivate account' : 'Reactivate account' ?></button>
        </form>
<?php endif; ?>
      </div>
    </div>
  </section>
</div>

<aside>
  <section class="card">
    <header class="card__h"><h2>Summary</h2></header>
    <div class="card__b">
      <?= kv(array_merge([
            'Role'         => role_tag($acct['role']),
            'Status'       => state_pill((bool)$acct['is_active']),
            'Health centre'=> $isStaff ? ($acct['facility'] ? e($acct['facility']) : ($acct['role'] === 'admin' ? 'District office' : '')) : null,
            'Last sign-in' => $acct['last_login_at'] ? e(fmt_datetime($acct['last_login_at'])) : '<span class="muted">Never</span>',
            'Created'      => e(fmt_date($acct['created_at'])),
          ], $work ?? [])) ?>
    </div>
  </section>
  <section class="card">
    <header class="card__h"><h2>Recent activity</h2>
      <a class="btn btn--link" href="<?= e(url('/admin/audit.php?user=' . urlencode($acct['full_name']))) ?>">Audit log</a></header>
    <div class="card__b">
      <?= timeline(array_map(static fn(array $r): array => [
            fmt_date($r['created_at'], false),
            ucfirst(strtolower(str_replace('_', ' ', (string)$r['action']))),
            $r['who'] . ($r['details'] ? ' · ' . $r['details'] : ''),
            null,
          ], $activity)) ?>
    </div>
  </section>
</aside>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
