# Deployment

For a district office server, a VPS or shared hosting with MySQL. The
development setup in the [README](../README.md) is not suitable for real
patient data: it runs as the database root user with errors on screen.

---

## 1. Server requirements

* PHP 8.1+ (FPM or mod_php) with `pdo_mysql`, `mbstring`, `session`, `openssl`
* MySQL 8.0 or later (5.7 works, without `CHECK` enforcement)
* Apache with `mod_headers` and `mod_deflate`, or nginx
* A domain name and a TLS certificate

## 2. Put the files in place

Upload everything **except**:

* any seed / demo script (`setup_seed.php.bak`, `tools/demo_accounts.php`) &mdash;
  these create accounts with known passwords and are never wanted on a live server
* `config/config.local.php` &mdash; create a fresh one on the server
* `.git/`, `docs/` (optional)

Only the project folder needs to be web-served. `config/`, `includes/` and
`tools/` are blocked by `.htaccess`; on nginx, block them explicitly (section 6).

## 3. Create the database and a least-privilege user

Never let the application connect as `root`.

```sql
CREATE DATABASE telemedicine CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'telemed_app'@'localhost' IDENTIFIED BY 'a-long-random-password';
```

Load the schema as an admin user (the file begins with `CREATE DATABASE IF NOT
EXISTS ... USE telemedicine`):

```
mysql -u root -p < database.sql
```

Then grant the application exactly what it needs. It never deletes a row, so do
not grant `DELETE`:

```sql
GRANT SELECT, INSERT, UPDATE ON telemedicine.* TO 'telemed_app'@'localhost';
FLUSH PRIVILEGES;
```

To keep the audit log append-only even through the application, grant it only
`SELECT, INSERT` on that one table (and the broader grant on the rest):

```sql
REVOKE UPDATE ON telemedicine.audit_logs FROM 'telemed_app'@'localhost';
```

## 4. Configure the application

Environment variables are better than a file, because a mis-served `.php` cannot
leak them. With Apache + mod_php:

```apache
SetEnv TELEMED_DB_HOST 127.0.0.1
SetEnv TELEMED_DB_PORT 3306
SetEnv TELEMED_DB_NAME telemedicine
SetEnv TELEMED_DB_USER telemed_app
SetEnv TELEMED_DB_PASS a-long-random-password
SetEnv TELEMED_ENV production
```

With PHP-FPM, use `env[...]` lines in the pool configuration. Otherwise create
`config/config.local.php` from the sample and `chmod 640` it, owned by the web
user.

`TELEMED_ENV=production` turns off on-screen errors and logs them instead.
Confirm `display_errors = Off` and `log_errors = On` in `php.ini` as well.

## 5. HTTPS

A certificate is required: without one, `includes/init.php` will redirect to
`https://` and the site will not load.

```
sudo certbot --apache -d telemed.example.org
```

Once TLS is live the application sends `Strict-Transport-Security` and marks
the session cookie `Secure` by itself. Behind a load balancer or reverse proxy
that terminates TLS, make sure `X-Forwarded-Proto: https` is passed through,
which `init.php` honours.

## 6. nginx instead of Apache

`.htaccess` is ignored by nginx, so add the equivalents:

```nginx
server {
    server_name telemed.example.org;
    root /var/www/telemed;
    index index.php;

    location ~ ^/(config|includes|tools)/ { deny all; }
    location ~ \.(sql|md|log|ini)$        { deny all; }
    location ~ /\.                        { deny all; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    gzip on;
    gzip_types text/html text/css application/javascript;
}
```

## 7. First administrator

The system ships with no demo data. On the server, create the first account
directly (do **not** reuse the development default password). Generate the hash:

```
php -r "echo password_hash('a-strong-password', PASSWORD_ARGON2ID), PHP_EOL;"
```

```sql
INSERT INTO users (full_name, phone, password_hash, role)
VALUES ('District Administrator', '0970000000', '<paste the hash>', 'admin');
```

(`php tools/install.php` also creates a first administrator when the users table
is empty, but it uses a known default password, so on a live server prefer the
manual INSERT above with your own password.)

Log in as that account, then from **Staff accounts**:

1. Rename the two starter health centres to the real ones, and add the rest.
2. Create the health worker and doctor accounts (both belong to a centre).
3. Change the administrator password.

Patients register themselves, or a health worker registers them.

## 8. Backups

The audit log and the consultations are clinical records; losing them is not an
option.

```
mysqldump -u root -p --single-transaction telemedicine > telemed_$(date +%F).sql
```

Run it nightly from cron, keep copies off the server, and **test a restore**:

```
mysql -u root -p telemedicine_restore_test < telemed_2026-10-07.sql
```

## 9. Go-live checklist

- [ ] No seed / demo script is on the server (`setup_seed.php.bak`, `tools/demo_accounts.php`)
- [ ] None of the development demo passwords (`Admin@2026`, `Health@2026`, `Doctor@2026`, `Patient@2026`) works
- [ ] `TELEMED_ENV=production`, and no PHP error ever appears on screen
- [ ] The application connects as `telemed_app`, not `root`
- [ ] `https://` loads, `http://` redirects to it
- [ ] `https://host/config/db.php` and `/includes/init.php` return 403
- [ ] `https://host/database.sql` returns 403
- [ ] The development default admin password exists nowhere
- [ ] Health centre names are the real ones for the district
- [ ] A nightly `mysqldump` runs, and a restore has been tested

## 10. Keeping it running

| Task | How often |
|---|---|
| Check the audit log for `LOGIN_BLOCKED` and failed logins | weekly |
| Deactivate accounts of staff who have left | as it happens |
| Confirm the nightly backup actually ran | weekly |
| Apply PHP and MySQL security updates | as released |
| Archive audit rows older than the retention period agreed with the district | yearly |
