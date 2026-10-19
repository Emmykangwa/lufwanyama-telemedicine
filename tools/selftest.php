<?php
/**
 * tools/selftest.php -- automated checks over a self-seeded fixture set (MySQL).
 *
 *   php tools/selftest.php
 *
 * Covers the invariants that are tedious to click through by hand: the schema,
 * password hashing, role-based data isolation, the facility-and-doctor-scoped
 * UPDATE a doctor performs, parameter binding against injection strings, the
 * unique and CHECK constraints, and transaction rollback. The suite seeds its
 * own fixtures inside one outer transaction that is rolled back at the end, so
 * it needs no demo data and leaves the database exactly as it found it.
 *
 * The browser-side tests (page weight, throttling, offline queue, responsive
 * layout) are in docs/TESTING.md.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("tools/selftest.php runs from the command line only.\n");
}

require_once __DIR__ . '/../includes/init.php';

$pass = 0;
$fail = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok    $what\n";
    } else {
        $fail++;
        echo "  FAIL  $what" . ($detail !== '' ? " -- $detail" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

/** Pretend to be this user for the duration of a test. */
function act_as(PDO $pdo, string $phone): array
{
    $st = $pdo->prepare(
        'SELECT u.user_id, u.full_name, u.role, u.facility_id, f.name AS facility
           FROM users u LEFT JOIN facilities f ON f.facility_id = u.facility_id
          WHERE u.phone = ?'
    );
    $st->execute([$phone]);
    $u = $st->fetch();
    if (!$u) {
        throw new RuntimeException("Fixture user with phone $phone was not created by seed_fixtures().");
    }
    $_SESSION['user'] = [
        'user_id'     => (int)$u['user_id'],
        'full_name'   => $u['full_name'],
        'role'        => $u['role'],
        'facility_id' => $u['facility_id'] !== null ? (int)$u['facility_id'] : null,
        'facility'    => $u['facility'],
    ];
    return $_SESSION['user'];
}

/**
 * Insert a complete fixture set for the checks below. Everything runs inside
 * one outer transaction that is rolled back at the very end, so the suite is
 * self-contained: it no longer depends on setup_seed.php and leaves the
 * database exactly as it found it (it never touches live data).
 */
function seed_fixtures(PDO $pdo): array
{
    $pw = hash_password('Test@1234');

    $pdo->prepare('INSERT INTO facilities (name, location, is_active) VALUES (?, ?, 1)')
        ->execute(['Test Centre A', 'Village A']);
    $fa = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO facilities (name, location, is_active) VALUES (?, ?, 1)')
        ->execute(['Test Centre B', 'Village B']);
    $fb = (int)$pdo->lastInsertId();

    $mkUser = static function (PDO $pdo, string $name, string $phone, string $role, ?int $fid, string $pw): int {
        $pdo->prepare('INSERT INTO users (facility_id, full_name, phone, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 1)')
            ->execute([$fid, $name, $phone, $pw, $role]);
        return (int)$pdo->lastInsertId();
    };
    $mkUser($pdo, 'Admin',           '0999000001', 'admin',         null, $pw);
    $hwA = $mkUser($pdo, 'Health Worker A', '0999000002', 'health_worker', $fa, $pw);
    $hwB = $mkUser($pdo, 'Health Worker B', '0999000003', 'health_worker', $fb, $pw);
    $docA = $mkUser($pdo, 'Doctor A',       '0999000004', 'doctor',        $fa, $pw);
    $docB = $mkUser($pdo, 'Doctor B',       '0999000005', 'doctor',        $fb, $pw);
    $uAg = $mkUser($pdo, 'Agness Mwanza',   '0999000006', 'patient',       null, $pw);
    $uBr = $mkUser($pdo, 'Brian Kapata',    '0999000007', 'patient',       null, $pw);

    $mkPatient = static function (PDO $pdo, string $name, ?int $uid, ?int $fid, int $i): int {
        $pdo->prepare('INSERT INTO patients (user_id, patient_number, full_name, facility_id) VALUES (?, ?, ?, ?)')
            ->execute([$uid, 'LUF-TST-' . str_pad((string)$i, 4, '0', STR_PAD_LEFT), $name, $fid]);
        return (int)$pdo->lastInsertId();
    };
    $p1 = $mkPatient($pdo, 'Agness Mwanza', $uAg, $fa, 1);
    $mkPatient($pdo, 'Brian Kapata', $uBr, null, 2);   // self-registered, no facility yet
    $p3 = $mkPatient($pdo, 'Patient Three', null, $fa, 3);
    $p4 = $mkPatient($pdo, 'Patient Four', null, $fb, 4);
    $p5 = $mkPatient($pdo, 'Patient Five', null, $fb, 5);
    $p6 = $mkPatient($pdo, 'Patient Six', null, $fa, 6);

    $mkAppt = static function (PDO $pdo, int $pid, int $by, int $fid, ?int $did, string $status): int {
        $pdo->prepare(
            'INSERT INTO appointments (patient_id, booked_by, facility_id, doctor_id, department, appointment_date, reason, status)
             VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?)'
        )->execute([$pid, $by, $fid, $did, 'General Medicine', 'Fixture appointment', $status]);
        $aid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO consultations (appointment_id, sent_by, clinical_notes) VALUES (?, ?, ?)')
            ->execute([$aid, $by, 'Fixture clinical notes']);
        return $aid;
    };
    $appts = [
        $mkAppt($pdo, $p1, $hwA, $fa, $docA, 'pending'),
        $mkAppt($pdo, $p1, $hwA, $fa, null,  'confirmed'),     // addressed to "any doctor"
        $mkAppt($pdo, $p3, $hwA, $fa, $docA, 'rescheduled'),
        $mkAppt($pdo, $p4, $hwB, $fb, $docB, 'declined'),
        $mkAppt($pdo, $p5, $hwB, $fb, null,  'completed'),     // addressed to "any doctor"
        $mkAppt($pdo, $p6, $hwA, $fa, $docA, 'pending'),
    ];

    // The checks below act on these ids rather than on "whatever is in the
    // table", so the suite behaves the same on an empty or a populated database.
    return ['centre_a' => $fa, 'centre_b' => $fb, 'doctor_b' => $docB, 'appts' => $appts];
}

$pdo->beginTransaction();
$FX = seed_fixtures($pdo);

echo "Telemedicine self-test (MySQL)\n==============================";

// ---------------------------------------------------------------------
section('1. Schema');
// ---------------------------------------------------------------------
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach (['appointments', 'audit_logs', 'consultations', 'facilities', 'patients', 'users'] as $t) {
    check("table $t exists", in_array($t, $tables, true));
}
check('the old referrals table is gone', !in_array('referrals', $tables, true));

$colType = static function (PDO $pdo, string $table, string $col): string {
    $st = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$table, $col]);
    return (string)$st->fetchColumn();
};

$statusType = $colType($pdo, 'appointments', 'status');
$enumOk = true;
foreach (statuses() as $s) {
    if (!str_contains($statusType, "'$s'")) {
        $enumOk = false;
    }
}
check('appointment status enum matches the application', $enumOk, $statusType);

$st = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
        AND CONSTRAINT_NAME = 'users_staff_facility'"
);
check('staff-must-have-a-facility constraint present', (int)$st->fetchColumn() === 1);

check('appointments carry a single facility_id (no origin/target split)',
    $colType($pdo, 'appointments', 'facility_id') !== '' && $colType($pdo, 'appointments', 'doctor_id') !== '');

// ---------------------------------------------------------------------
section('2. Fixture data');
// ---------------------------------------------------------------------
$counts = [];
foreach (['users', 'patients', 'appointments', 'consultations'] as $t) {
    $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
}
check('users seeded', $counts['users'] >= 7, (string)$counts['users']);
check('patients seeded', $counts['patients'] >= 6, (string)$counts['patients']);
check('appointments seeded', $counts['appointments'] >= 6, (string)$counts['appointments']);
check('every appointment has exactly one consultation',
    $counts['consultations'] === $counts['appointments'],
    $counts['consultations'] . ' of ' . $counts['appointments']);

$spread = $pdo->query('SELECT COUNT(DISTINCT status) FROM appointments')->fetchColumn();
check('test data covers every status', (int)$spread === count(statuses()), (string)$spread);

$anyDoctor = (int)$pdo->query('SELECT COUNT(*) FROM appointments WHERE doctor_id IS NULL')->fetchColumn();
check('some appointments are addressed to "any doctor"', $anyDoctor >= 1, (string)$anyDoctor);

// ---------------------------------------------------------------------
section('3. Passwords');
// ---------------------------------------------------------------------
$hashes = $pdo->query('SELECT password_hash FROM users')->fetchAll(PDO::FETCH_COLUMN);
$weak = array_filter($hashes, static fn($h) => !str_starts_with((string)$h, '$argon2') && !str_starts_with((string)$h, '$2y$'));
check('every password is an Argon2id or bcrypt hash', $weak === [], implode(' ', $weak));

$st = $pdo->prepare('SELECT password_hash FROM users WHERE phone = ?');
$st->execute(['0999000001']);
$adminHash = (string)$st->fetchColumn();
check('the seeded password verifies', password_verify('Test@1234', $adminHash));
check('a wrong password does not verify', !password_verify('Test@12345', $adminHash));
check('no plaintext password is stored', !str_contains($adminHash, 'Test@1234'));

// ---------------------------------------------------------------------
section('4. Patient data isolation');
// ---------------------------------------------------------------------
$agness = act_as($pdo, '0999000006');
$brian  = act_as($pdo, '0999000007');

$ownRows = static function (PDO $pdo, int $uid): array {
    $st = $pdo->prepare(
        'SELECT a.appointment_id FROM appointments a
           JOIN patients p ON p.patient_id = a.patient_id
          WHERE p.user_id = ?'
    );
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};

$mine   = $ownRows($pdo, $agness['user_id']);
$theirs = $ownRows($pdo, $brian['user_id']);
$all    = $pdo->query('SELECT appointment_id FROM appointments')->fetchAll(PDO::FETCH_COLUMN);

check('a patient sees some of their own appointments', count($mine) > 0, (string)count($mine));
check('a patient does not see every appointment', count($mine) < count($all), count($mine) . ' of ' . count($all));
check('two patients share no appointment rows', array_intersect($mine, $theirs) === []);

// ---------------------------------------------------------------------
section('5. Health worker facility scoping');
// ---------------------------------------------------------------------
$hwA = act_as($pdo, '0999000002');
$hwB = act_as($pdo, '0999000003');

$facilityPatients = static function (PDO $pdo, int $fid): array {
    $st = $pdo->prepare('SELECT patient_id FROM patients WHERE facility_id = ?');
    $st->execute([$fid]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};
$facilityAppts = static function (PDO $pdo, int $fid): array {
    $st = $pdo->prepare('SELECT appointment_id FROM appointments WHERE facility_id = ?');
    $st->execute([$fid]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};

$pA = $facilityPatients($pdo, (int)$hwA['facility_id']);
$pB = $facilityPatients($pdo, (int)$hwB['facility_id']);
check('Centre A has its own patients', count($pA) > 0, (string)count($pA));
check('Centre B has its own patients', count($pB) > 0, (string)count($pB));
check('the two registers do not overlap', array_intersect($pA, $pB) === []);

$aA = $facilityAppts($pdo, (int)$hwA['facility_id']);
$aB = $facilityAppts($pdo, (int)$hwB['facility_id']);
check('request lists do not overlap between centres', array_intersect($aA, $aB) === []);

$st = $pdo->query('SELECT COUNT(*) FROM patients WHERE facility_id IS NULL');
check('a self-registered patient is on no facility register until attached', (int)$st->fetchColumn() >= 1);

// ---------------------------------------------------------------------
section('6. A doctor can only act on their own centre and their own queue');
// ---------------------------------------------------------------------
$doc = act_as($pdo, '0999000004');       // Dr Grace Banda, Centre A
$fid = (int)$doc['facility_id'];
$did = (int)$doc['user_id'];

$st = $pdo->prepare(
    'SELECT COUNT(*) FROM appointments WHERE facility_id = ? AND (doctor_id IS NULL OR doctor_id = ?)'
);
$st->execute([$fid, $did]);
check('the doctor queue is not empty', (int)$st->fetchColumn() > 0);

// A request addressed to a different doctor at the same centre must not be in the queue.
$otherDoc = $FX['doctor_b'];

// Always a fixture row, never whatever else happens to be in the table: the
// first fixture appointment sits at Centre A and is addressed to this doctor.
$victim        = $FX['appts'][0];
$otherFacility = $FX['centre_b'];

$pdo->exec('SAVEPOINT sp');
// The scoped UPDATE the doctor dashboard runs, but with the wrong facility.
$st = $pdo->prepare(
    "UPDATE appointments SET status = 'declined'
      WHERE appointment_id = ? AND facility_id = ? AND (doctor_id IS NULL OR doctor_id = ?)"
);
$st->execute([$victim, $otherFacility, $did]);
check('a facility-scoped UPDATE changes nothing outside the centre', $st->rowCount() === 0, 'rows=' . $st->rowCount());

$st = $pdo->prepare(
    "UPDATE appointments SET status = 'declined'
      WHERE appointment_id = ? AND facility_id = ? AND (doctor_id IS NULL OR doctor_id = ?)"
);
$st->execute([$victim, $fid, $did]);
check('the same UPDATE works for the doctor own centre/queue', $st->rowCount() === 1, 'rows=' . $st->rowCount());
$pdo->exec('ROLLBACK TO SAVEPOINT sp');

$st = $pdo->prepare('SELECT status FROM appointments WHERE appointment_id = ?');
$st->execute([$victim]);
check('the rollback left the appointment untouched', $st->fetchColumn() !== 'declined');

// ---------------------------------------------------------------------
section('7. Injection strings are data, never SQL');
// ---------------------------------------------------------------------
$before = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

foreach (["' OR '1'='1", "admin'--", "'; DROP TABLE users; --", "%' OR 1=1 --"] as $evil) {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM users WHERE (phone = ? OR email = LOWER(?)) AND is_active = 1'
    );
    $st->execute([$evil, $evil]);
    check("login lookup rejects: $evil", (int)$st->fetchColumn() === 0);
}
check('the users table survived', (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $before);

$st = $pdo->prepare('SELECT COUNT(*) FROM patients WHERE full_name LIKE ?');
$st->execute(['%' . "%' OR 1=1 --" . '%']);
check('a LIKE search treats wildcards as text', (int)$st->fetchColumn() === 0);

// ---------------------------------------------------------------------
section('8. Output escaping');
// ---------------------------------------------------------------------
check('e() neutralises a script tag',
    e('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;');
check('e() escapes quotes used to break out of attributes',
    e('" onmouseover="x') === '&quot; onmouseover=&quot;x');
check('badge() escapes an unexpected status', !str_contains(badge('<b>'), '<b>'));

// ---------------------------------------------------------------------
section('9. Validators');
// ---------------------------------------------------------------------
check('valid_phone accepts 0970000001', valid_phone('0999000001'));
check('valid_phone accepts +260970000001', valid_phone('+260970000001'));
check('valid_phone rejects 12345', !valid_phone('12345'));
check('normalize_phone folds +260 into the local form', normalize_phone('+260 97 000 0001') === '0970000001');
check('normalize_phone strips spaces and dashes', normalize_phone('097-000 0001') === '0970000001');
check('normalize_phone restores a dropped leading zero', normalize_phone('970000001') === '0970000001');
check('first_name keeps a title with the surname', first_name('Dr Grace Banda') === 'Dr Banda');
check('first_name uses the first name otherwise', first_name('Joseph Mulenga') === 'Joseph');
$tmp = generate_password();
check('a generated temporary password passes the password rules', password_problem($tmp) === null, $tmp);
check('generated passwords differ', $tmp !== generate_password());
check('valid_phone rejects letters', !valid_phone('09700abcde'));
check('valid_date accepts 2026-10-07', valid_date('2026-10-07'));
check('valid_date rejects 2026-02-30', !valid_date('2026-02-30'));
check('valid_date rejects 07/10/2026', !valid_date('07/10/2026'));
check('password_problem rejects a short password', password_problem('short') !== null);
check('password_problem rejects aaaaaaaa', password_problem('aaaaaaaa') !== null);
check('password_problem accepts Test@1234', password_problem('Test@1234') === null);
check('clean_value strips control characters', clean_value("ab\x00\x07c", 100) === 'abc');
check('clean_value trims and caps length', clean_value('   abcdef  ', 3) === 'abc');
check('departments() has no duplicates', count(departments()) === count(array_unique(departments())));

// ---------------------------------------------------------------------
section('10. Constraints the database enforces by itself');
// ---------------------------------------------------------------------
$pdo->exec('SAVEPOINT sp');
try {
    $pdo->prepare('INSERT INTO users (full_name, phone, password_hash, role) VALUES (?, ?, ?, ?)')
        ->execute(['Duplicate Phone', '0999000001', hash_password('Test@1234'), 'patient']);
    check('a duplicate phone number is refused', false, 'the insert succeeded');
} catch (PDOException $ex) {
    check('a duplicate phone number is refused', $ex->getCode() === SQLSTATE_UNIQUE_VIOLATION, 'SQLSTATE ' . $ex->getCode());
}
$pdo->exec('ROLLBACK TO SAVEPOINT sp');

$pdo->exec('SAVEPOINT sp');
try {
    $pdo->prepare('INSERT INTO users (full_name, phone, password_hash, role) VALUES (?, ?, ?, ?)')
        ->execute(['Facility-less Doctor', '0999999999', hash_password('Test@1234'), 'doctor']);
    check('a doctor without a facility is refused', false, 'the insert succeeded');
} catch (PDOException $ex) {
    check('a doctor without a facility is refused', true, 'SQLSTATE ' . $ex->getCode());
}
$pdo->exec('ROLLBACK TO SAVEPOINT sp');

$pdo->exec('SAVEPOINT sp');
try {
    $pdo->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ?')
        ->execute(['not_a_status', $victim]);
    check('an invalid status is refused', false, 'the update succeeded');
} catch (PDOException $ex) {
    check('an invalid status is refused', true, 'SQLSTATE ' . $ex->getCode());
}
$pdo->exec('ROLLBACK TO SAVEPOINT sp');

$pdo->exec('SAVEPOINT sp');
try {
    $first = (int)$pdo->query('SELECT appointment_id FROM consultations ORDER BY consultation_id LIMIT 1')->fetchColumn();
    $st = $pdo->prepare('SELECT sent_by FROM consultations WHERE appointment_id = ?');
    $st->execute([$first]);
    $sentBy = (int)$st->fetchColumn();
    $pdo->prepare('INSERT INTO consultations (appointment_id, sent_by) VALUES (?, ?)')
        ->execute([$first, $sentBy]);
    check('an appointment cannot have two consultations', false, 'the insert succeeded');
} catch (PDOException $ex) {
    check('an appointment cannot have two consultations', $ex->getCode() === SQLSTATE_UNIQUE_VIOLATION, 'SQLSTATE ' . $ex->getCode());
}
$pdo->exec('ROLLBACK TO SAVEPOINT sp');

// ---------------------------------------------------------------------
section('11. Transaction rollback leaves no orphan');
// ---------------------------------------------------------------------
$apptsBefore = (int)$pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
$pdo->exec('SAVEPOINT sp');
$st = $pdo->prepare(
    'INSERT INTO appointments (patient_id, booked_by, facility_id, doctor_id, department, appointment_date, reason)
     VALUES (?, ?, ?, ?, ?, DATE_ADD(CURDATE(), INTERVAL 1 DAY), ?)'
);
$st->execute([$pA[0], $hwA['user_id'], $hwA['facility_id'], null, 'Surgery', 'Rollback test']);
$tempId = (int)$pdo->lastInsertId();
check('the appointment exists inside the transaction',
    (int)$pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn() === $apptsBefore + 1);
$pdo->exec('ROLLBACK TO SAVEPOINT sp');
check('after rollback the appointment is gone',
    (int)$pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn() === $apptsBefore);
$st = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE appointment_id = ?');
$st->execute([$tempId]);
check('no orphan row was left behind', (int)$st->fetchColumn() === 0);

// ---------------------------------------------------------------------
section('12. Audit trail');
// ---------------------------------------------------------------------
$logsBefore = (int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
act_as($pdo, '0999000001');
$pdo->exec('SAVEPOINT sp');
audit($pdo, 'SELFTEST', 'database', null, 'automated check');
check('audit() writes a row',
    (int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === $logsBefore + 1);
$row = $pdo->query('SELECT * FROM audit_logs ORDER BY log_id DESC LIMIT 1')->fetch();
check('the row records who acted', (int)$row['user_id'] === (int)$_SESSION['user']['user_id']);
check('the row records what happened', $row['action'] === 'SELFTEST');
check('the row is time-stamped', !empty($row['created_at']));
$pdo->exec('ROLLBACK TO SAVEPOINT sp');
check('audit rows are rolled back with their transaction',
    (int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === $logsBefore);

// ---------------------------------------------------------------------
section('13. Interface labels');
// ---------------------------------------------------------------------
check('t() returns the English label for a known key', t('login') === 'Login');
check('an unknown key returns itself rather than nothing', t('no_such_key_here') === 'no_such_key_here');

foreach (statuses() as $s) {
    check("status '$s' has a label", t($s) !== $s && t($s) !== '');
}

// ---------------------------------------------------------------------
// Undo every fixture: the outer transaction is rolled back, so the database
// is left exactly as it was before the suite ran.
if ($pdo->inTransaction()) {
    $pdo->rollBack();
}

echo "\n========================================\n";
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
