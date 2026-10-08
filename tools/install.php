<?php
/**
 * tools/install.php -- command-line installer (no mysql client needed).
 *
 *   php tools/install.php            create the database and the schema
 *   php tools/install.php --reset    DROP the database first, then recreate it
 *
 * Reads the same settings as config/db.php: the defaults, then
 * config/config.local.php, then TELEMED_DB_* environment variables.
 *
 * Under XAMPP you can instead import database.sql through phpMyAdmin.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("tools/install.php runs from the command line only.\n");
}

$DB_HOST = '127.0.0.1';
$DB_PORT = '3306';
$DB_NAME = 'telemedicine';
$DB_USER = 'root';
$DB_PASS = '';
$APP_ENV = 'development';

$local = __DIR__ . '/../config/config.local.php';
if (is_file($local)) {
    require $local;
}
foreach ([
    'TELEMED_DB_HOST' => 'DB_HOST', 'TELEMED_DB_PORT' => 'DB_PORT',
    'TELEMED_DB_NAME' => 'DB_NAME', 'TELEMED_DB_USER' => 'DB_USER',
    'TELEMED_DB_PASS' => 'DB_PASS',
] as $envKey => $var) {
    $v = getenv($envKey);
    if ($v !== false && $v !== '') {
        $$var = $v;
    }
}

$reset  = in_array('--reset', $argv, true);
$schema = __DIR__ . '/../database.sql';

if (!is_file($schema)) {
    exit("Cannot find database.sql next to this project.\n");
}

$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

try {
    // Connect to the server (no database selected yet) to create ours.
    $server = new PDO("mysql:host={$DB_HOST};port={$DB_PORT};charset=utf8mb4", $DB_USER, $DB_PASS, $opts);

    if ($reset) {
        echo "Dropping database {$DB_NAME} ...\n";
        $server->exec('DROP DATABASE IF EXISTS ' . quoteIdent($DB_NAME));
    }

    // database.sql already contains CREATE DATABASE IF NOT EXISTS + USE.
    echo "Running database.sql ...\n";
    $sql = (string)file_get_contents($schema);
    try {
        $server->exec($sql);
        echo "Schema created.\n";
    } catch (PDOException $ex) {
        // 42S01 = a table already exists: the schema is already in place.
        if ($ex->getCode() === '42S01') {
            echo "Schema already present -- leaving it as it is.\n";
        } else {
            throw $ex;
        }
    }

    $db = new PDO("mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS, $opts);
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo 'Tables: ' . implode(', ', $tables) . "\n";

    // Fresh start: if there are no accounts yet, create the first administrator
    // so the system is usable. Everything else is created from inside the app.
    $hasUsers = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    if ($hasUsers) {
        echo "\nUsers already present -- leaving existing accounts untouched.\n";
    } else {
        $phone = '0970000001';
        $plain = 'Admin@2026';
        $hash  = defined('PASSWORD_ARGON2ID')
            ? password_hash($plain, PASSWORD_ARGON2ID)
            : password_hash($plain, PASSWORD_BCRYPT);
        $db->prepare(
            "INSERT INTO users (full_name, phone, password_hash, role, is_active)
             VALUES ('District Administrator', ?, ?, 'admin', 1)"
        )->execute([$phone, $hash]);
        echo "\nFirst administrator created:\n";
        echo "    login:    {$phone}\n";
        echo "    password: {$plain}\n";
        echo "Log in, then create health centres, health workers and doctors from Staff accounts.\n";
        echo "Change this password after the first login.\n";
        echo "\nFor one ready-made account per role, run: php tools/demo_accounts.php\n";
    }
} catch (PDOException $e) {
    echo 'FAILED: ' . $e->getMessage() . "\n\n";
    echo "Check the host, port, user and password in config/config.local.php.\n";
    exit(1);
}

function quoteIdent(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}
