<?php
/** logout.php -- end the session and clear its cookie */
require_once __DIR__ . '/includes/init.php';

if ($u = current_user()) {
    audit($pdo, 'LOGOUT', 'user', (int)$u['user_id']);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?? 'Lax',
    ]);
}
session_destroy();

redirect('/login.php');
