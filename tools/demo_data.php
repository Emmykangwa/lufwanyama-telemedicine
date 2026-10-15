<?php
/**
 * tools/demo_data.php -- generate realistic demo appointments.
 *
 *   php tools/demo_data.php            create ~3 months of sample activity
 *   php tools/demo_data.php --undo     remove everything this tool created
 *
 * Why this exists: the dashboards draw trends, a status split and a month
 * grid. With only a handful of rows those read as empty, so this fills the
 * system with plausible traffic for a demonstration or for screenshots.
 *
 * Nothing here is real patient data. Every row it creates is tagged in the
 * audit log with the action DEMO_SEED, which is how --undo finds them again.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("tools/demo_data.php runs from the command line only.\n");
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

const TAG = 'DEMO_SEED';

/* ---------------- undo ------------------------------------------------ */
if (in_array('--undo', $argv, true)) {
    $ids = $pdo->query("SELECT entity_id FROM audit_logs WHERE action = '" . TAG . "'
                         AND entity = 'appointment' AND entity_id IS NOT NULL")
               ->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) {
        exit("Nothing to undo: no demo rows are tagged in the audit log.\n");
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $pdo->beginTransaction();
    // consultations cascade from appointments
    $pdo->prepare("DELETE FROM appointments WHERE appointment_id IN ($in)")->execute($ids);
    $pdo->prepare("DELETE FROM audit_logs WHERE action = '" . TAG . "'")->execute();
    $pdo->commit();
    exit('Removed ' . count($ids) . " demo appointments and their consultations.\n");
}

/* ---------------- preconditions --------------------------------------- */
$facilities = $pdo->query('SELECT facility_id FROM facilities ORDER BY facility_id')
                  ->fetchAll(PDO::FETCH_COLUMN);
$patients   = $pdo->query('SELECT patient_id, facility_id FROM patients')->fetchAll();
$workers    = $pdo->query("SELECT user_id, facility_id FROM users WHERE role = 'health_worker'")->fetchAll();
$doctors    = $pdo->query("SELECT user_id, facility_id FROM users WHERE role = 'doctor'")->fetchAll();
$patUsers   = $pdo->query("SELECT u.user_id, p.patient_id, p.facility_id
                             FROM users u JOIN patients p ON p.user_id = u.user_id
                            WHERE u.role = 'patient'")->fetchAll();

if (!$facilities || !$patients || !$workers) {
    exit("Run tools/install.php and tools/demo_accounts.php first:\n"
       . "  php tools/install.php\n  php tools/demo_accounts.php\n");
}

/* ---------------- extra patients so the register is not one name ------ */
$names = [
    ['Mutinta Hamaundu', 'F', 'Kalilo'],      ['Chanda Musonda', 'M', 'Mukutuma'],
    ['Bwalya Chileshe', 'F', 'Shimukunami'],  ['Nchimunya Moonga', 'M', 'Lumpuma'],
    ['Thandiwe Banda', 'F', 'Kafue road'],    ['Mwansa Kabwe', 'M', 'Kalilo'],
    ['Lushomo Siame', 'F', 'Mukutuma'],       ['Kondwani Phiri', 'M', 'Shimukunami'],
    ['Namakau Mubita', 'F', 'Lumpuma'],       ['Taonga Zimba', 'M', 'Kalilo'],
];
$made = 0;
foreach ($names as $i => [$full, $sex, $village]) {
    $st = $pdo->prepare('SELECT patient_id FROM patients WHERE full_name = ?');
    $st->execute([$full]);
    if ($st->fetchColumn()) {
        continue;
    }
    $fid = $facilities[$i % count($facilities)];
    $pdo->prepare(
        'INSERT INTO patients (patient_number, full_name, dob, sex, phone, village, facility_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        new_patient_number($pdo), $full,
        date('Y-m-d', strtotime('-' . (7000 + $i * 430) . ' day')),
        $sex, '097' . str_pad((string)(1100000 + $i), 7, '0', STR_PAD_LEFT), $village, $fid,
    ]);
    $made++;
}
if ($made) {
    echo "Added {$made} demo patients to the register.\n";
    $patients = $pdo->query('SELECT patient_id, facility_id FROM patients')->fetchAll();
}

/* ---------------- appointments ---------------------------------------- */
$depts   = departments();
$reasons = [
    'Persistent cough for five days', 'Fever and headache', 'Antenatal check-up',
    'Child not feeding well', 'Pain in the lower back', 'Follow-up after treatment',
    'Swollen leg after a fall', 'Blurred vision when reading', 'Toothache on the left side',
    'Feeling anxious and not sleeping', 'High blood pressure review', 'Wound not healing',
];
$notes = [
    'Temp 38.1, chest clear on listening.', 'BP 148/92, repeat reading taken after rest.',
    'Foetal heart heard, fundal height consistent with dates.',
    'Weight below the line on the growth chart.', 'No fracture suspected, able to bear weight.',
    'Blood sugar 7.8 mmol/L, fasting.', 'Reports two weeks of poor sleep.',
];
$replies = [
    'Confirmed. Please come fasting and bring your card.',
    'Confirmed. Attend the morning session.',
    'Moved to a day I am at the centre.',
    'Start the treatment given and attend if there is no improvement.',
    'Seen and treated at the centre.',
];

// deterministic so repeated runs look the same
mt_srand(20261015);

// A patient may hold only MAX_OPEN_REQUESTS (5) open requests at once, so the
// seeder keeps well under that per patient. Without this one patient collects
// dozens of open rows and can no longer book, which is not how a real
// register looks.
const MAX_OPEN_PER_PATIENT = 2;
$openPer = [];
$isOpen  = static fn(string $s): bool => in_array($s, ['pending', 'confirmed', 'rescheduled'], true);

$pdo->beginTransaction();
$created = 0;

for ($dayBack = 88; $dayBack >= -21; $dayBack--) {
    $date = date('Y-m-d', strtotime("-{$dayBack} day"));
    $dow  = (int)date('N', strtotime($date));
    if ($dow >= 6 && mt_rand(1, 100) > 20) {
        continue;                       // quiet weekends
    }
    $howMany = mt_rand(0, 4);
    if ($dow === 1) {
        $howMany += 2;                  // Monday rush
    }

    for ($k = 0; $k < $howMany; $k++) {
        $pat = $patients[mt_rand(0, count($patients) - 1)];
        $fid = (int)($pat['facility_id'] ?: $facilities[0]);

        // who raised it: usually a health worker, sometimes the patient
        $byPatient = $patUsers && mt_rand(1, 100) <= 25;
        if ($byPatient) {
            $pu  = $patUsers[mt_rand(0, count($patUsers) - 1)];
            $pat = ['patient_id' => $pu['patient_id'], 'facility_id' => $pu['facility_id']];
            $fid = (int)($pu['facility_id'] ?: $facilities[0]);
            $by  = (int)$pu['user_id'];
        } else {
            $w  = $workers[mt_rand(0, count($workers) - 1)];
            $by = (int)$w['user_id'];
        }

        $docId = null;
        foreach ($doctors as $d) {
            if ((int)$d['facility_id'] === $fid && mt_rand(1, 100) <= 70) {
                $docId = (int)$d['user_id'];
                break;
            }
        }

        // status depends on whether the date has passed
        if ($dayBack > 0) {
            $status = [ 'completed', 'completed', 'completed', 'declined', 'confirmed' ][mt_rand(0, 4)];
        } elseif ($dayBack === 0) {
            $status = ['confirmed', 'confirmed', 'pending'][mt_rand(0, 2)];
        } else {
            $status = ['pending', 'confirmed', 'confirmed', 'rescheduled', 'pending'][mt_rand(0, 4)];
        }

        // Keep each patient under the booking limit the app enforces.
        $pid = (int)$pat['patient_id'];
        if ($isOpen($status)) {
            if (($openPer[$pid] ?? 0) >= MAX_OPEN_PER_PATIENT) {
                continue;               // this patient has enough open already
            }
            $openPer[$pid] = ($openPer[$pid] ?? 0) + 1;
        }

        $createdAt = date('Y-m-d H:i:s', strtotime($date . ' -' . mt_rand(1, 9) . ' day')
                                         + mt_rand(8, 16) * 3600);

        $pdo->prepare(
            'INSERT INTO appointments (patient_id, booked_by, facility_id, doctor_id, department,
                                       appointment_date, reason, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int)$pat['patient_id'], $by, $fid, $docId,
            $depts[mt_rand(0, count($depts) - 1)], $date,
            $reasons[mt_rand(0, count($reasons) - 1)], $status, $createdAt,
        ]);
        $aid = (int)$pdo->lastInsertId();

        $answered = $status !== 'pending';
        $pdo->prepare(
            'INSERT INTO consultations (appointment_id, sent_by, answered_by, clinical_notes,
                                        doctor_response, responded_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $aid, $by,
            $answered ? ($docId ?: ($doctors ? (int)$doctors[0]['user_id'] : null)) : null,
            mt_rand(1, 100) <= 75 ? $notes[mt_rand(0, count($notes) - 1)] : null,
            $answered ? $replies[mt_rand(0, count($replies) - 1)] : null,
            $answered ? date('Y-m-d H:i:s', strtotime($createdAt) + mt_rand(40, 2600) * 60) : null,
            $createdAt,
        ]);

        // the tag that makes --undo possible
        $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity, entity_id, details, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$by, TAG, 'appointment', $aid, 'Seeded demo activity', $createdAt]);

        $created++;
    }
}

$pdo->commit();

echo "Created {$created} demo appointments with consultations, spanning "
   . date('j M Y', strtotime('-88 day')) . ' to ' . date('j M Y', strtotime('+21 day')) . ".\n";
echo "Undo at any time with:  php tools/demo_data.php --undo\n";
