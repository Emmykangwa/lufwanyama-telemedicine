# Lufwanyama District Telemedicine System

Appointment booking and consultation for a rural district. Health workers (or
patients themselves) request an appointment at a health centre and send the
preliminary clinical notes ahead; the **doctor works inside that health centre**
and answers the request; the patient follows the status before spending money on
travel. There is no separate referral hospital: every facility is a health
centre with its own health workers and doctors.

**Stack:** PHP 8.1+ (no framework) &middot; MySQL / MariaDB (XAMPP) &middot; plain HTML, CSS and JavaScript.

| | |
|---|---|
| Roles | patient, health worker, doctor, administrator |
| Database | MySQL / MariaDB, six entities, PDO prepared statements throughout |
| Passwords | Argon2id (bcrypt fallback) |
| Page weight | 13-23 KB per page including CSS and JS, against a 250 KB budget |
| Offline | form drafts and queued submissions in `localStorage` |
| Interface | English, mobile-first, modern responsive medical UI |

---

## 1. Requirements

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.1 or later | with `pdo_mysql`, `mbstring`, `session`, `openssl` (XAMPP has all of these) |
| MySQL / MariaDB | MySQL 5.7+ / MariaDB 10.4+ | XAMPP ships MariaDB |
| A browser | any modern one | Chrome/Edge DevTools are used for the throttling tests |
| Node.js | 18 or later | **optional**, only to run `tools/offline_test.mjs` |

Check PHP has what it needs:

```
php -v
php -m
```

`pdo_mysql` must appear in the module list. Under XAMPP it is enabled by
default; on a plain PHP build, uncomment `extension=pdo_mysql` in `php.ini`.

---

## 2. Setup

The system starts **fresh**: no demo patients or appointments. `tools/install.php`
creates the database, the schema and a single administrator account; everything
else is created from inside the app.

### Plain PHP + a MySQL/MariaDB server (recommended)

```
copy config\config.sample.php config\config.local.php      REM edit if your login differs
php tools/install.php        REM creates the database, schema and first admin
php tools/demo_accounts.php  REM one account per role (development only)
php -S localhost:8000 router.php
```

`php tools/install.php` prints the first administrator's login the first time it
runs. `php tools/install.php --reset` drops the database and rebuilds it (and
recreates the admin). Both refuse to run from a browser.

On Windows you can instead double-click **`start.bat`**, which starts MariaDB and
the web server together and opens the browser.

### Setup under XAMPP

1. Copy the project to `C:\xampp\htdocs\telemed`.
2. Start **Apache** and **MySQL** from the XAMPP Control Panel.
3. Import the schema: open <http://localhost/phpmyadmin>, click **Import**,
   choose `database.sql`, and run it. You should see a `telemedicine` database
   with six tables.
4. Create the first administrator from the command line (the schema ships with
   no accounts): `php tools/install.php` detects the existing database and only
   adds the admin.
5. Open <http://localhost/telemed/>.

The default `config/config.local.php` already matches XAMPP (user `root`, no
password, port 3306), so nothing else needs editing on a standard install.

Settings are read from `config/config.local.php`, overridden by the
`TELEMED_DB_HOST`, `TELEMED_DB_PORT`, `TELEMED_DB_NAME`, `TELEMED_DB_USER`,
`TELEMED_DB_PASS` and `TELEMED_ENV` environment variables (preferred on a
server). `config.local.php` is in `.gitignore`, so the password never reaches a
repository.

---

## 3. Login credentials

`php tools/demo_accounts.php` creates a health centre and one working account
for every role:

| Role | Log in with | Password | Sees |
|---|---|---|---|
| Administrator | `0970000001` | `Admin@2026` | Reports, staff accounts, health centres, audit log |
| Health worker | `0970000002` | `Health@2026` | Their centre's patients and requests |
| Doctor | `0970000003` | `Doctor@2026` | Requests at their centre addressed to them or to "any doctor" |
| Patient | `0970000004` | `Patient@2026` | Their own appointments |

The health worker and the doctor both belong to **Lufwanyama Rural Health
Centre**, and the patient is on that centre's register, so the full
request-and-respond flow works straight away.

**Change these passwords before the system is used for real** (Staff accounts
&rarr; *Reset a password*). `tools/install.php` on an empty database creates
only the administrator. There are no demo patients or appointments.
**No real patient data is used anywhere.**

---

## 4. Getting started

| # | Who | Do this | Expect |
|---|---|---|---|
| 1 | Health worker `0970000002` | Log in, **New appointment**: pick Agness Mwanza, (optionally) the doctor, department, a future date, reason and clinical notes | Row appears on the dashboard as Pending |
| 2 | Doctor `0970000003` | Log in &rarr; the request is in the queue &rarr; **Respond**: Confirm, Reschedule or Decline with a note | Status changes; the note is recorded against them |
| 3 | Doctor | **My schedule** | Confirmed visits grouped by day, printable, with Completed buttons |
| 4 | Patient `0970000004` | Log in | The same status and response, and no other patient's rows |
| 5 | Patient | **Book appointment** | A second request, booked by the patient themselves |
| 6 | Administrator `0970000001` | **Reports**, then **Audit log** | Counts by status, department, centre and doctor; every action logged with user, IP and time |
| 7 | Administrator | **Staff accounts** | Add more health centres, health workers and doctors; reset a password |

A patient who registers themselves belongs to no centre until a health worker
attaches them: **Patients** &rarr; *Attach a patient who registered themselves*
&rarr; enter their patient number. The move is written to the audit log.

---

## 5. What each role may do

| | Patient | Health worker | Doctor | Admin |
|---|---|---|---|---|
| Register themselves | yes | - | - | - |
| Book an appointment for themselves | yes | - | - | - |
| See own appointments and responses | yes | - | - | - |
| Register a patient | - | yes (own centre) | - | - |
| Attach a self-registered patient to the centre | - | yes | - | - |
| Request an appointment with clinical notes | - | yes | - | - |
| Withdraw a request the centre made | - | yes | - | - |
| See requests for own centre (theirs or "any doctor") | - | - | yes | - |
| Confirm / reschedule / decline / complete | - | - | yes | - |
| Day-by-day clinic schedule | - | - | yes | - |
| Create staff accounts and health centres | - | - | - | yes |
| Reset a password, deactivate an account | - | - | - | yes |
| Reports and audit log | - | - | - | yes |

Isolation is enforced in SQL, not in the interface:

* patients: every query filtered by `patients.user_id = <session user>`
* health workers: `appointments.facility_id = <their centre>` and
  `patients.facility_id = <their centre>`
* doctors: `appointments.facility_id = <their centre> AND (doctor_id IS NULL OR
  doctor_id = <them>)`, on reads **and** on the `UPDATE` that saves a response
* a facility-scoped role whose account has no centre is refused outright

---

## 6. Database

Six entities: `facilities`, `users`, `patients`, `appointments`,
`consultations`, `audit_logs`. Full column-by-column reference and the
relationships are in [docs/DATABASE.md](docs/DATABASE.md).

Appointment statuses: `pending`, `confirmed`, `rescheduled`, `declined`,
`completed` (a MySQL `ENUM`, so with strict mode on an invalid status cannot be
stored).

---

## 7. Security

| Measure | Where |
|---|---|
| Argon2id password hashing, bcrypt fallback, automatic rehash on login | `includes/functions.php`, `login.php` |
| Prepared statements with bound parameters for every query | all pages, `PDO::ATTR_EMULATE_PREPARES => false` |
| CSRF token on every POST form, compared with `hash_equals` | `csrf_field()`, `csrf_check()` |
| Output escaping on everything printed | `e()` |
| Role-based access control and centre scoping | `require_role()` plus the SQL filters above |
| Session: `HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS, strict mode, id regenerated at login, 30-minute idle and 12-hour absolute limits | `includes/init.php`, `login_user()` |
| HTTPS redirect everywhere except localhost, plus HSTS | `includes/init.php` |
| Content-Security-Policy with **no** inline script or style | `includes/init.php` |
| Login throttle: 8 failures per account, 40 per address, per 15 minutes | `login.php` |
| Strict SQL mode (rejects bad data / invalid ENUM values) | `config/db.php` |
| Audit log of every login, failure, creation, response and export | `audit()` |
| `config/` and `includes/` not reachable over HTTP | `.htaccess`, `router.php` |
| Errors logged, never shown, when `TELEMED_ENV=production` | `config/db.php` |

Server-side validation is repeated on every submission: a patient id, a doctor
id (must work at the chosen centre), the department and the date are all
re-checked against the database, so editing the HTML of a `<select>` achieves
nothing.

---

## 8. Working on a bad connection

`assets/js/app.js` handles the dropped-connection case (no service worker, so
nothing extra to install):

1. Any form with `data-offline="name"` saves a draft to `localStorage` as the
   user types. A reload, a flat battery or a lost page keeps the typing.
2. Pressing Submit with no network queues the submission and shows a red
   banner with the number of entries waiting.
3. When the browser reports `online` the queue is posted in order, each entry
   is dropped only after the server accepts it, and the page reloads.
4. A rejected entry is retried up to five times rather than silently lost.

Known limit, worth stating in a report: if the session has expired while the
phone was offline, the queued entry is rejected and the user must log in again
before it will be accepted.

---

## 9. Interface

The interface is English. `includes/lang.php` keeps every label in one place
(`t('key')`) so wording stays consistent and easy to change. The UI is
mobile-first and responsive: a sticky header with a collapsible menu on small
screens, cards and tables that reflow, large touch targets, keyboard focus
rings, a skip link, and motion that respects `prefers-reduced-motion`. There are
no web fonts, images or frameworks, so pages stay well under the weight budget.

---

## 10. Testing

Two automated suites, 110 checks in total, both green on this build (MariaDB
11.4):

```
php tools/selftest.php       # 73 checks: schema, hashing, RBAC, isolation,
                             # injection, constraints, rollback, audit, labels
node tools/offline_test.mjs  # 37 checks: drafts and the offline queue in app.js
```

`tools/selftest.php` seeds its own fixtures inside one transaction that is
rolled back at the end, so it needs no demo data and leaves the database
exactly as it found it.

The manual procedure, with the expected result for every non-functional
requirement (page weight, Slow 3G, offline drafts and queue, responsiveness,
role isolation, CSRF, SQL injection, hashing, audit trail), is in
[docs/TESTING.md](docs/TESTING.md).

---

## 11. Deployment

Production steps, a dedicated database user with least privilege, HTTPS, backups
and the go-live checklist are in [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

---

## 12. Project layout

```
index.php  login.php  register.php  logout.php   public pages
database.sql                                     MySQL / MariaDB schema
router.php                                       guard for php -S
start.bat  stop.bat                              one-click run / stop on Windows
config/      db.php, config.sample.php           connection and environment
includes/    init.php, functions.php, lang.php,  session, security headers, helpers,
             header.php, footer.php              interface labels, shared layout
assets/      css/style.css, js/app.js            ~5 KB of CSS, ~6 KB of JS
patient/     dashboard.php, book.php             FR5 plus self-booking (FR2)
health/      dashboard.php, patients.php,        FR2, FR3
             add_patient.php, new_appointment.php
doctor/      dashboard.php, schedule.php         FR4
admin/       dashboard.php, users.php, audit.php FR1, FR6
tools/       install.php, demo_accounts.php,     installer; one account per role;
             selftest.php, offline_test.mjs      73 PHP checks; 37 JavaScript checks
docs/        DATABASE.md, DEPLOYMENT.md, TESTING.md
```

---

## 13. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `Database connection failed` | MySQL not started in XAMPP, or wrong credentials in `config/config.local.php`. Test with `php tools/install.php` |
| `could not find driver` | `pdo_mysql` not enabled in `php.ini` |
| Blank white page | Set `$APP_ENV = 'development'` in `config/config.local.php` to see the error |
| Styles missing under Apache | The project must sit directly in a web folder so `BASE_URL` resolves (it is auto-detected) |
| *Invalid or expired request* after submitting | The session timed out (CSRF check). Reload the page and resubmit |
| Login always fails | Run `php tools/install.php` to create the first admin, or the phone number was typed with spaces |
| `Access denied` on a page | You are logged in with the wrong role. That is the access control working |
| No accounts exist | Run `php tools/install.php` to create the first administrator |

---

## 14. Out of scope

Live video consultation, SmartCare integration and SMS notification are
deliberately **not** built. The most useful next addition is SMS of the
confirmed date through a local gateway, since that reaches patients who cannot
open a browser at all.
