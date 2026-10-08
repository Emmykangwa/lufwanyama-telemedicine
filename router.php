<?php
/**
 * router.php -- front controller for the PHP built-in server.
 *
 *   php -S localhost:8000 router.php
 *
 * It exists only to refuse the paths that Apache refuses through .htaccess:
 * the schema file, the docs, and the config / includes / tools folders. Every
 * other request is served normally.
 */

declare(strict_types=1);

$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

$blocked = preg_match('#(^|/)\.|\.(sql|md|log|ini)$|^/(config|includes|tools)/#i', $path) === 1;

if ($blocked) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden\n";
    return true;
}

return false;   // let the built-in server handle it
