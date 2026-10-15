<?php
/**
 * includes/init.php
 *
 * Every page requires this file first. It works out the base URL, forces
 * HTTPS off localhost, sends security headers, starts a hardened session,
 * then loads the database connection, helpers and language table.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// 1. BASE_URL -- the URL prefix the project is served under.
//    Works out of the box whether the project sits at the document root
//    (php -S localhost:8000) or in a sub-folder (htdocs/telemed).
//    Override with the TELEMED_BASE_URL environment variable if needed.
// ---------------------------------------------------------------------
if (!defined('BASE_URL')) {
    $base = getenv('TELEMED_BASE_URL');
    if ($base === false || $base === '') {
        $base = '';
        $root   = realpath(__DIR__ . '/..');
        $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
        $name   = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($root && $script && str_starts_with($script, $root)) {
            $rel = str_replace('\\', '/', substr($script, strlen($root)));
            if ($rel !== '' && str_ends_with($name, $rel)) {
                $base = substr($name, 0, strlen($name) - strlen($rel));
            }
        }
    }
    define('BASE_URL', rtrim($base, '/'));
}

// ---------------------------------------------------------------------
// 2. HTTPS (NFR1). Localhost is exempt so development needs no certificate.
// ---------------------------------------------------------------------
$serverName = $_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? '');
$serverName = strtolower(explode(':', $serverName)[0]);
$isLocal = in_array($serverName, ['localhost', '127.0.0.1', '::1', ''], true);

$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        // behind a reverse proxy / load balancer that terminates TLS
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (!$isLocal && !$isHttps && PHP_SAPI !== 'cli') {
    header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? $serverName) . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}

// ---------------------------------------------------------------------
// 3. Security headers. The Content-Security-Policy allows no inline
//    script or style at all: every rule lives in style.css and every
//    behaviour in app.js.
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header(
        "Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
        . "style-src 'self'; script-src 'self'; form-action 'self'; "
        . "base-uri 'none'; frame-ancestors 'none'"
    );
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

// ---------------------------------------------------------------------
// 4. Hardened session
// ---------------------------------------------------------------------
const SESSION_IDLE_LIMIT     = 1800;   // 30 minutes of inactivity
const SESSION_ABSOLUTE_LIMIT = 43200;  // 12 hours in total

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)SESSION_ABSOLUTE_LIMIT);
    session_name('TELEMEDSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_URL === '' ? '/' : BASE_URL . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $isHttps,
    ]);
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/ui.php';

// Expire an idle or very old session before any page can use it.
if (!empty($_SESSION['user'])) {
    $now   = time();
    $idle  = $now - (int)($_SESSION['last_seen'] ?? $now);
    $age   = $now - (int)($_SESSION['started_at'] ?? $now);
    $sameUa = hash_equals(
        (string)($_SESSION['ua'] ?? ''),
        substr(hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 32)
    );

    if ($idle > SESSION_IDLE_LIMIT || $age > SESSION_ABSOLUTE_LIMIT || !$sameUa) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('err', t('session_expired'));
        redirect('/login.php');
    }
    $_SESSION['last_seen'] = $now;
}
