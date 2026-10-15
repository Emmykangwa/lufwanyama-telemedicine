# Setup Guide

How to run the Lufwanyama District Telemedicine System on a new PC.

---

## Prerequisites

| Software | Version | Download |
|---|---|---|
| PHP | 8.1 or later | https://windows.php.net/download/ (Thread Safe zip) |
| MySQL | 8.0 or later (5.7 works) | https://dev.mysql.com/downloads/mysql/ |
| Git | any recent version | https://git-scm.com/downloads |
| Node.js (optional) | 18 or later | https://nodejs.org/ — only needed to run the offline JS tests |

### PHP extensions required

These must be enabled in `php.ini` (remove the leading `;` if commented out):

- `pdo_mysql`
- `mbstring`
- `openssl`

Verify with:

```
php -v
php -m
```

`pdo_mysql` must appear in the module list.

---

## Installation

### 1. Clone the repository

```
git clone https://github.com/Emmykangwa/lufwanyama-telemedicine.git
cd lufwanyama-telemedicine
```

### 2. Start MySQL

Make sure your database server is running on port 3306.

### 3. Configure the database connection

```
copy config\config.sample.php config\config.local.php
```

Edit `config\config.local.php` if your database user or password differs from the defaults (`root` / no password).

### 4. Create the database and first admin account

```
php tools/install.php
```

This creates the `telemedicine` database, all six tables, and the administrator account.

### 5. Create demo accounts (development only)

```
php tools/demo_accounts.php
```

### 6. Start the web server

```
php -S localhost:8000 router.php
```

Open http://localhost:8000 in your browser.

---

## Login Credentials

Created by `php tools/demo_accounts.php`:

| Role | Phone | Password |
|---|---|---|
| Administrator | `0970000001` | `Admin@2026` |
| Health worker | `0970000002` | `Health@2026` |
| Doctor | `0970000003` | `Doctor@2026` |
| Patient | `0970000004` | `Patient@2026` |

---

## Running Tests

```
php tools/selftest.php
node tools/offline_test.mjs
```

73 PHP checks and 37 JavaScript checks — all should pass.

---

## XAMPP Alternative

If using XAMPP instead of standalone PHP + MySQL:

1. Copy the project folder into `C:\xampp\htdocs\telemed`
2. Start **Apache** and **MySQL** from the XAMPP Control Panel
3. Run `php tools/install.php` then `php tools/demo_accounts.php`
4. Open http://localhost/telemed/
