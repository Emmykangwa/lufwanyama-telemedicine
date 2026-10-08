<?php
/**
 * tools/demo_accounts.php -- create one working account for each role.
 *
 *   php tools/demo_accounts.php
 *
 * Creates a health centre and one administrator, health worker, doctor and
 * patient so every part of the system can be opened and demonstrated. It is
 * safe to run twice: an account whose phone number already exists is skipped.
 *
 * These are development logins with known passwords. Do NOT run this on a
 * live server -- see docs/DEPLOYMENT.md for creating the real first account.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("tools/demo_accounts.php runs from the command line only.\n");
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

const CENTRE = 'Lufwanyama Rural Health Centre';

// ---- the health centre both staff accounts belong to --------------------
$st = $pdo->prepare('SELECT facility_id FROM facilities WHERE name = ?');
$st->execute([CENTRE]);
$facilityId = (int)$st->fetchColumn();

if (!$facilityId) {
    $pdo->prepare('INSERT INTO facilities (name, location, is_active) VALUES (?, ?, 1)')
        ->execute([CENTRE, 'Lufwanyama District']);
    $facilityId = (int)$pdo->lastInsertId();
    echo "Created health centre: " . CENTRE . "\n";
} else {
    echo "Health centre already present: " . CENTRE . "\n";
}

// ---- one account per role ----------------------------------------------
$accounts = [
    ['Mary Chanda',     '0970000001', 'Admin@2026',   'admin',         null],
    ['Joseph Mulenga',  '0970000002', 'Health@2026',  'health_worker', $facilityId],
    ['Dr Grace Banda',  '0970000003', 'Doctor@2026',  'doctor',        $facilityId],
    ['Agness Mwanza',   '0970000004', 'Patient@2026', 'patient',       null],
];

echo "\n";
foreach ($accounts as [$name, $phone, $plain, $role, $fid]) {
    $st = $pdo->prepare('SELECT user_id FROM users WHERE phone = ?');
    $st->execute([$phone]);
    $userId = (int)$st->fetchColumn();

    if ($userId) {
        echo sprintf("  %-14s %s  already exists, skipped\n", $role, $phone);
    } else {
        $pdo->prepare(
            'INSERT INTO users (facility_id, full_name, phone, password_hash, role, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$fid, $name, $phone, hash_password($plain), $role]);
        $userId = (int)$pdo->lastInsertId();
        echo sprintf("  %-14s %s  created\n", $role, $phone);
    }

    // The patient also needs a patient record, on the centre's register.
    if ($role === 'patient') {
        $st = $pdo->prepare('SELECT patient_id FROM patients WHERE user_id = ?');
        $st->execute([$userId]);
        if (!$st->fetchColumn()) {
            $pdo->prepare(
                'INSERT INTO patients (user_id, patient_number, full_name, sex, phone, facility_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$userId, new_patient_number($pdo), $name, 'F', $phone, $facilityId]);
            echo "                 patient record created and attached to the centre\n";
        }
    }
}

echo "\nLogin credentials\n";
echo "-------------------------------------------------------------\n";
printf("  %-16s %-14s %s\n", 'ROLE', 'PHONE', 'PASSWORD');
foreach ($accounts as [$name, $phone, $plain, $role]) {
    printf("  %-16s %-14s %s\n", str_replace('_', ' ', $role), $phone, $plain);
}
echo "\nChange these passwords before the system is used for real.\n";
