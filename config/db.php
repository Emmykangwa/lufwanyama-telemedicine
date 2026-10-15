<?php
/**
 * config/db.php
 *
 * MySQL connection via PDO. Every query in this application uses
 * prepared statements with bound parameters, so user input is never
 * concatenated into SQL.
 *
 * Settings are read in this order, last one wins:
 *   1. the defaults below (XAMPP: root, no password, port 3306)
 *   2. config/config.local.php  (not in version control -- copy the sample)
 *   3. environment variables    (preferred on a real server)
 */

declare(strict_types=1);

// ---- 1. defaults (XAMPP local development) --------------------------
$DB_HOST = '127.0.0.1';
$DB_PORT = '3306';
$DB_NAME = 'telemedicine';
$DB_USER = 'root';
$DB_PASS = '';
$APP_ENV = 'development';   // 'development' or 'production'

// ---- 2. optional local override file --------------------------------
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require $localConfig;
}

// ---- 3. environment variables ---------------------------------------
$env = static function (string $key, string $fallback): string {
    $v = getenv($key);
    return ($v === false || $v === '') ? $fallback : $v;
};

$DB_HOST = $env('TELEMED_DB_HOST', $DB_HOST);
$DB_PORT = $env('TELEMED_DB_PORT', $DB_PORT);
$DB_NAME = $env('TELEMED_DB_NAME', $DB_NAME);
$DB_USER = $env('TELEMED_DB_USER', $DB_USER);
$DB_PASS = $env('TELEMED_DB_PASS', $DB_PASS);
$APP_ENV = $env('TELEMED_ENV',     $APP_ENV);

define('APP_ENV', $APP_ENV === 'production' ? 'production' : 'development');

// Show errors while developing, never to a visitor on a live server.
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    // Be strict about bad data (reject out-of-range / invalid ENUM values)
    // and pin the time zone to Lusaka (UTC+2, no daylight saving).
    $pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', time_zone = '+02:00'");
} catch (PDOException $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    if (APP_ENV === 'production') {
        exit('The service is temporarily unavailable. Please try again shortly.');
    }
    exit(
        "<h2>Database connection failed</h2><p>Is the MySQL server running, and are the "
        . "credentials in <code>config/config.local.php</code> correct?</p><pre>"
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>'
    );
}

// Duplicate-key SQLSTATE is 23000 in MySQL (driver error 1062).
const SQLSTATE_UNIQUE_VIOLATION = '23000';
