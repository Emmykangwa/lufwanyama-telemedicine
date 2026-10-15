<?php
/**
 * config/config.sample.php
 *
 * Copy this file to config/config.local.php and edit it:
 *     copy config\config.sample.php config\config.local.php
 *
 * config.local.php holds the real database password and is listed in
 * .gitignore, so it never reaches a public repository.
 */

$DB_HOST = '127.0.0.1';
$DB_PORT = '3306';
$DB_NAME = 'telemedicine';

// XAMPP's default MySQL login is user 'root' with no password.
// On a real server create a dedicated account instead; never use root:
//   CREATE USER 'telemed_app'@'localhost' IDENTIFIED BY 'a-long-random-password';
//   GRANT SELECT, INSERT, UPDATE ON telemedicine.* TO 'telemed_app'@'localhost';
//   FLUSH PRIVILEGES;
$DB_USER = 'root';
$DB_PASS = '';

// 'development' shows PHP errors on screen. Use 'production' on a server.
$APP_ENV = 'development';
