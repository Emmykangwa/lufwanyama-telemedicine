<?php
/**
 * includes/functions.php
 *
 * Shared helpers: escaping, redirects, flash messages, CSRF, password
 * hashing, role-based access control, audit logging and small validators.
 */

declare(strict_types=1);

// =====================================================================
// Output
// =====================================================================

/** Escape anything before printing it. Guards against XSS. */
function e(mixed $s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Absolute URL inside this application. */
function url(string $path): string
{
    return BASE_URL . $path;
}

/**
 * Inline SVG icon, drawn in the current text colour. Used instead of emoji so
 * the interface stays consistent across devices and prints cleanly. The markup
 * is fixed in this file, never built from user input.
 */
function icon(string $name, string $class = 'ic'): string
{
    $paths = [
        'grid'      => '<rect x="3" y="3" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="2"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="2"/>',
        'calendar'  => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'users'     => '<path d="M16 20v-1.5a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V20"/><circle cx="9" cy="7" r="3.5"/><path d="M22 20v-1.5a4 4 0 0 0-3-3.85"/><path d="M16 3.6a4 4 0 0 1 0 7"/>',
        'user'      => '<path d="M19 20v-1.5a5 5 0 0 0-5-5h-4a5 5 0 0 0-5 5V20"/><circle cx="12" cy="7" r="4"/>',
        'userplus'  => '<path d="M15 20v-1.5a5 5 0 0 0-5-5H7a5 5 0 0 0-5 5V20"/><circle cx="8.5" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
        'inbox'     => '<path d="M3 13h5l1.5 2.5h5L16 13h5"/><path d="M5.5 5h13l2.5 8v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-5z"/>',
        'send'      => '<path d="M21 3 10.5 13.5"/><path d="M21 3 14.5 21l-4-7.5L3 9.5z"/>',
        'search'    => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'chart'     => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'clipboard' => '<rect x="4" y="4" width="16" height="17" rx="2.5"/><path d="M9 4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5v1h-6z"/><path d="M8.5 11h7M8.5 15h4"/>',
        'building'  => '<path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/><path d="M12 7v5M9.5 9.5h5"/>',
        'pulse'     => '<path d="M2 12h4l2.5-7 5 14L16 12h6"/>',
        'signal'    => '<path d="M2 9a15 15 0 0 1 20 0"/><path d="M5.5 12.5a10 10 0 0 1 13 0"/><path d="M9 16a5 5 0 0 1 6 0"/><circle cx="12" cy="19.5" r="1"/>',
        'shield'    => '<path d="M12 22s8-3.5 8-9.5V5.5L12 2 4 5.5V12.5C4 18.5 12 22 12 22z"/><path d="m9 12 2 2 4-4"/>',
        'logout'    => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 17l-5-5 5-5"/><path d="M5 12h12"/>',
        'login'     => '<path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"/><path d="m14 17 5-5-5-5"/><path d="M19 12H7"/>',
        'home'      => '<path d="m3 10 9-7 9 7"/><path d="M5 9v11h14V9"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'check'     => '<path d="m4 12.5 5 5L20 6.5"/>',
        'close'     => '<path d="M6 6l12 12M18 6 6 18"/>',
        'edit'      => '<path d="M4 20h4L19 9a2.5 2.5 0 0 0-3.5-3.5L4 16.5z"/><path d="m14.5 6.5 3.5 3.5"/>',
        'printer'   => '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
        'download'  => '<path d="M12 3v12"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M4 20h16"/>',
        'link'      => '<path d="M10.5 13.5a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1.4 1.4"/><path d="M13.5 10.5a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1.4-1.4"/>',
        'save'      => '<path d="M5 3h11l3 3v15H5z"/><path d="M8 3v6h7V3"/><path d="M8 14h8"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.5l3.5 2"/>',
        'alert'     => '<path d="M12 3 2 20h20z"/><path d="M12 9v5"/><circle cx="12" cy="17.2" r="1"/>',
        'bell'      => '<path d="M18 9a6 6 0 1 0-12 0c0 5-2 6-2 6h16s-2-1-2-6"/><path d="M10.5 20a2 2 0 0 0 3 0"/>',
        'mail'      => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="m3.5 7 8.5 6 8.5-6"/>',
        'arrow'     => '<path d="M5 12h13"/><path d="m12.5 6 6 6-6 6"/>',
        'settings'  => '<circle cx="12" cy="12" r="3.2"/><path d="M19.5 14.5a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V22a2 2 0 1 1-4 0v-.2a1.6 1.6 0 0 0-2.8-1.1l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3.5 15H3a2 2 0 1 1 0-4h.2a1.6 1.6 0 0 0 1.1-2.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.6 1.6 0 0 0 10 4.5V4a2 2 0 1 1 4 0v.2a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0 1.1 2.7h.4a2 2 0 1 1 0 4H21a1.6 1.6 0 0 0-1.5 1z"/>',
        'cross'     => '<path d="M9.5 3h5v6.5H21v5h-6.5V21h-5v-6.5H3v-5h6.5z"/>',
        // --- medical / landing-page icons (additive) ---
        'heart'     => '<path d="M12 20.5 4.6 13a4.8 4.8 0 0 1 6.8-6.8l.6.6.6-.6a4.8 4.8 0 0 1 6.8 6.8z"/>',
        'stetho'    => '<path d="M5 3v6a3.5 3.5 0 0 0 7 0V3"/><path d="M8.5 12.5V15a5.5 5.5 0 0 0 11 0v-1.8"/><circle cx="19.5" cy="10.5" r="2.1"/>',
        'child'     => '<circle cx="12" cy="12" r="9"/><path d="M9 10h.01M15 10h.01"/><path d="M9.5 15a3.5 3.5 0 0 0 5 0"/>',
        'scissors'  => '<circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><path d="M8 7.6 20 16M8 16.4 20 8"/>',
        'eye'       => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
        'tooth'     => '<path d="M12 3C8.5 3 7.5 5 6 5.3 4.3 5.7 4.3 9 5.2 12.8c.7 3 1 7.2 2.3 7.2s1.3-4 2.5-4 1.2 4 2.5 4 1.6-4.2 2.3-7.2C17.7 9 17.7 5.7 16 5.3 14.5 5 13.5 3 12 3z"/>',
        'brain'     => '<path d="M9.5 4a2.6 2.6 0 0 0-2.6 2.6A3 3 0 0 0 5 12a3 3 0 0 0 2 5 2.6 2.6 0 0 0 5 .3V5.6A2.6 2.6 0 0 0 9.5 4z"/><path d="M14.5 4a2.6 2.6 0 0 1 2.6 2.6A3 3 0 0 1 19 12a3 3 0 0 1-2 5 2.6 2.6 0 0 1-5 .3"/>',
        'flask'     => '<path d="M9 3h6M10 3v6l-5.2 9A2 2 0 0 0 6.6 21h10.8a2 2 0 0 0 1.8-3L14 9V3"/><path d="M7.5 15h9"/>',
        'phone'     => '<path d="M6.5 3h3l1.5 5-2 1.5a12 12 0 0 0 5.5 5.5l1.5-2 5 1.5v3a2 2 0 0 1-2 2A16 16 0 0 1 4.5 5a2 2 0 0 1 2-2z"/>',
        'mappin'    => '<path d="M12 21s7-6 7-11a7 7 0 1 0-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'star'      => '<path d="m12 3 2.6 5.8 6.4.6-4.8 4.3 1.4 6.3L12 17l-5.6 3 1.4-6.3L3 9.4l6.4-.6z"/>',
        'book'      => '<path d="M5 4a2 2 0 0 1 2-2h12v16H7a2 2 0 0 0-2 2z"/><path d="M5 18a2 2 0 0 1 2-2h12"/>',
        'chat'      => '<path d="M21 12a8 8 0 0 1-11.5 7.2L4 21l1.8-5.5A8 8 0 1 1 21 12z"/>',
    ];
    $d = $paths[$name] ?? $paths['grid'];
    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
         . $d . '</svg>';
}

/** Up to two initials for an avatar, e.g. "Grace Banda" -> "GB". */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts));
    if (!$parts) {
        return '?';
    }
    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $last  = count($parts) > 1 ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) : '';
    return $first . $last;
}

/** "Good morning" / "Good afternoon" / "Good evening" for the current hour. */
function greeting(): string
{
    $h = (int)date('G');
    if ($h < 12) {
        return 'Good morning';
    }
    return $h < 17 ? 'Good afternoon' : 'Good evening';
}

/** The first name only, for a friendly heading. */
function first_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    return $parts[0] ?? $name;
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

// =====================================================================
// One-time (flash) messages
// =====================================================================

/** flash('ok', 'Saved') stores a message; flash() reads and clears it. */
function flash(?string $type = null, ?string $msg = null): ?array
{
    if ($type !== null) {
        $_SESSION['flash'] = ['type' => $type, 'msg' => (string)$msg];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// =====================================================================
// CSRF protection
// =====================================================================

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

/** Call this at the top of every POST handler, before touching the data. */
function csrf_check(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    $sent = (string)($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $sent)) {
        http_response_code(400);
        exit('Invalid or expired request. Please go back, reload the page and try again.');
    }
}

// =====================================================================
// Passwords
// =====================================================================

/** Argon2id when the build supports it, otherwise bcrypt. */
function hash_password(string $plain): string
{
    if (defined('PASSWORD_ARGON2ID')) {
        return password_hash($plain, PASSWORD_ARGON2ID);
    }
    return password_hash($plain, PASSWORD_BCRYPT);
}

function password_algo(): string
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
}

/** At least 8 characters, and not one single repeated character. */
function password_problem(string $pw): ?string
{
    if (strlen($pw) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (strlen(count_chars($pw, 3)) < 3) {
        return 'Password is too simple. Mix letters, numbers or symbols.';
    }
    return null;
}

// =====================================================================
// Roles and access control (RBAC)
// =====================================================================

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function dashboard_path(string $role): string
{
    return [
        'patient'       => '/patient/dashboard.php',
        'health_worker' => '/health/dashboard.php',
        'doctor'        => '/doctor/dashboard.php',
        'admin'         => '/admin/dashboard.php',
    ][$role] ?? '/login.php';
}

/** Allow only the listed roles; anyone else is sent away or refused. */
function require_role(array $roles): array
{
    $u = current_user();
    if (!$u) {
        flash('err', t('please_login'));
        redirect('/login.php');
    }
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('403 Access denied. Your role may not open this page.');
    }
    // Health workers and doctors are always scoped to one facility.
    if (in_array($u['role'], ['health_worker', 'doctor'], true) && empty($u['facility_id'])) {
        http_response_code(403);
        exit('Your account is not linked to a facility. Ask the administrator to fix this.');
    }
    return $u;
}

/**
 * Start a logged-in session for a user row. Regenerates the session id so
 * a pre-existing id cannot be reused (session fixation).
 */
function login_user(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'user_id'     => (int)$u['user_id'],
        'full_name'   => (string)$u['full_name'],
        'role'        => (string)$u['role'],
        'facility_id' => isset($u['facility_id']) && $u['facility_id'] !== null ? (int)$u['facility_id'] : null,
        'facility'    => $u['facility'] ?? null,
    ];
    $_SESSION['started_at'] = time();
    $_SESSION['last_seen']  = time();
    $_SESSION['ua']         = substr(hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 32);
    unset($_SESSION['csrf']);   // issue a fresh CSRF token for the new session
}

// =====================================================================
// Reference data
// =====================================================================

function departments(): array
{
    return [
        'General Medicine', 'Paediatrics', 'Maternity', 'Surgery',
        'Eye Clinic', 'Dental', 'Mental Health',
    ];
}

function statuses(): array
{
    return ['pending', 'confirmed', 'rescheduled', 'declined', 'completed'];
}

function badge(string $status): string
{
    $class = in_array($status, statuses(), true) ? $status : 'pending';
    return '<span class="badge ' . $class . '">' . e(t($status)) . '</span>';
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

// =====================================================================
// Audit trail -- one row per sensitive action
// =====================================================================

function audit(
    PDO $pdo,
    string $action,
    string $entity,
    ?int $id = null,
    string $details = '',
    ?int $actorId = null
): void {
    $st = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, action, entity, entity_id, details, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $actorId ?? ($_SESSION['user']['user_id'] ?? null),
        substr($action, 0, 40),
        substr($entity, 0, 40),
        $id,
        $details === '' ? null : substr($details, 0, 255),
        client_ip(),
    ]);
}

/**
 * Failed logins in the last 15 minutes.
 *
 * Passing $identifier counts failures against that phone number or email
 * wherever they came from; passing none counts failures from this address.
 * A whole health centre usually shares one public address, so the per-account
 * limit is the tight one and the per-address limit is deliberately loose.
 */
function recent_login_failures(PDO $pdo, ?string $ip, ?string $identifier = null): int
{
    if ($identifier !== null) {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM audit_logs
              WHERE action = 'LOGIN_FAILED' AND details = ?
                AND created_at > NOW() - INTERVAL 15 MINUTE"
        );
        $st->execute([login_detail($identifier)]);
        return (int)$st->fetchColumn();
    }
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_logs
          WHERE action = 'LOGIN_FAILED' AND ip_address = ?
            AND created_at > NOW() - INTERVAL 15 MINUTE"
    );
    $st->execute([(string)$ip]);
    return (int)$st->fetchColumn();
}

/** The exact string a failed login records, so the counts above can match it. */
function login_detail(string $identifier): string
{
    return mb_substr($identifier, 0, 100);
}

// =====================================================================
// Patients
// =====================================================================

/** A unique patient number such as LUF-26-04821. */
function new_patient_number(PDO $pdo): string
{
    for ($i = 0; $i < 50; $i++) {
        $n  = 'LUF-' . date('y') . '-' . str_pad((string)random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        $st = $pdo->prepare('SELECT 1 FROM patients WHERE patient_number = ?');
        $st->execute([$n]);
        if (!$st->fetchColumn()) {
            return $n;
        }
    }
    throw new RuntimeException('Could not allocate a patient number.');
}

// =====================================================================
// Validators
// =====================================================================

function clean_value(mixed $raw, int $max): string
{
    $v = trim(is_scalar($raw) ? (string)$raw : '');
    // strip control characters that have no place in a form field
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr($v, 0, $max);
}

/** Trimmed, length-capped POST field. */
function clean(string $key, int $max = 255): string
{
    return clean_value($_POST[$key] ?? '', $max);
}

/** Trimmed, length-capped GET field, for filters on report pages. */
function clean_get(string $key, int $max = 255): string
{
    return clean_value($_GET[$key] ?? '', $max);
}

function valid_phone(string $phone): bool
{
    return (bool)preg_match('/^\+?[0-9]{9,15}$/', $phone);
}

function valid_date(string $d): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
        return false;
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function date_or_null(string $d): ?string
{
    return valid_date($d) ? $d : null;
}

function today(): string
{
    return date('Y-m-d');
}
