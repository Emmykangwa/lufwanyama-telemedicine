# Database reference

MySQL 5.7+ / MariaDB 10.4+ (XAMPP ships MariaDB). The whole schema is in
[`../database.sql`](../database.sql) and is created by importing that file in
phpMyAdmin, or by `php tools/install.php`.

## Design in one sentence

The doctor works **inside** the health centre, so an appointment names the
centre that will see the patient (`facility_id`) and, optionally, a specific
doctor at that centre (`doctor_id`); there is no separate referral hospital.

## Enumerated types and strict mode

Statuses, roles and sex are `ENUM` columns, and `config/db.php` sets
`sql_mode = STRICT_TRANS_TABLES`, so an invalid value is rejected by the
database rather than silently coerced.

| Column | Allowed values |
|---|---|
| `users.role` | `patient`, `health_worker`, `doctor`, `admin` |
| `appointments.status` | `pending`, `confirmed`, `rescheduled`, `declined`, `completed` |
| `patients.sex` | `F`, `M` |

## Relationships

```
facilities 1 ──< users            (a centre employs health workers AND doctors)
facilities 1 ──< patients         (a patient is on one centre's register)
facilities 1 ──< appointments     (the centre that will see the patient)
users      1 ──1 patients         (optional: a patient may have no login)
users      1 ──< appointments     (as doctor_id: the chosen doctor, optional)
patients   1 ──< appointments
appointments 1 ──1 consultations  (each appointment has exactly one)
users      1 ──< audit_logs
```

---

## 1. `facilities`

The health centres. Every facility is a health centre (no `type` column).

| Column | Type | Notes |
|---|---|---|
| `facility_id` | `int` auto-increment | primary key |
| `name` | `varchar(120)` | unique |
| `location` | `varchar(120)` | nullable |
| `is_active` | `tinyint(1)` | inactive centres disappear from the drop-downs |
| `created_at` | `timestamp` | defaults to now |

## 2. `users`

Everyone who can log in. The password itself is never stored.

| Column | Type | Notes |
|---|---|---|
| `user_id` | `int` auto-increment | primary key |
| `facility_id` | `int` | FK &rarr; `facilities`, `ON DELETE SET NULL`; null for patients and most admins |
| `full_name` | `varchar(120)` | |
| `phone` | `varchar(20)` | unique, and the usual login identifier |
| `email` | `varchar(120)` | optional, unique, forced lower-case by a `CHECK` so login is case-insensitive |
| `password_hash` | `varchar(255)` | Argon2id, bcrypt fallback |
| `role` | `enum` | |
| `is_active` | `tinyint(1)` | a deactivated account cannot log in |
| `last_login_at` | `timestamp` | shown on the admin account list |
| `created_at` | `timestamp` | |

Constraint `users_staff_facility`: a `health_worker` or `doctor` must have a
`facility_id`. Both roles now work inside a centre, so both are scoped to one.

## 3. `patients`

| Column | Type | Notes |
|---|---|---|
| `patient_id` | `int` auto-increment | primary key |
| `user_id` | `int` | FK &rarr; `users`, unique, **nullable**: a health worker can register a resident who has no phone |
| `patient_number` | `varchar(20)` | unique, e.g. `LUF-26-04821`, what the patient quotes at the clinic |
| `full_name` | `varchar(120)` | |
| `dob` | `date` | not-in-future is validated in PHP (MariaDB forbids `CURRENT_DATE` in a `CHECK`) |
| `sex` | `enum('F','M')` | nullable |
| `phone`, `village` | `varchar` | nullable |
| `facility_id` | `int` | the centre register the patient is on; null until a worker attaches them |
| `created_at` | `timestamp` | |

`full_name` is indexed; the default `utf8mb4_unicode_ci` collation makes the
health worker's `LIKE` search case-insensitive without extra work.

## 4. `appointments`

| Column | Type | Notes |
|---|---|---|
| `appointment_id` | `int` auto-increment | primary key |
| `patient_id` | `int` | FK &rarr; `patients`, `ON DELETE CASCADE` |
| `booked_by` | `int` | FK &rarr; `users`: the health worker, or the patient when self-booking |
| `facility_id` | `int` | **the health centre that will see the patient**; its doctors answer the request |
| `doctor_id` | `int` | FK &rarr; `users`, nullable: the chosen doctor, or `NULL` for "any doctor at the centre" |
| `department` | `varchar(60)` | from `departments()` in `includes/functions.php` |
| `appointment_date` | `date` | |
| `reason` | `varchar(255)` | |
| `status` | `enum` | defaults to `pending` |
| `created_at` | `timestamp` | |

Indexed on `status`, `appointment_date`, `patient_id`, `facility_id` and
`doctor_id` &mdash; the columns the dashboards filter on.

## 5. `consultations`

The clinical record for an appointment: the notes sent to the doctor (FR3) and
the doctor's reply (FR4). Each appointment has exactly one (`UNIQUE
(appointment_id)`), created together with the appointment, so a self-booked
request and a health-worker request carry notes and a reply in the same way.

| Column | Type | Notes |
|---|---|---|
| `consultation_id` | `int` auto-increment | primary key |
| `appointment_id` | `int` | FK &rarr; `appointments`, unique, `ON DELETE CASCADE` |
| `sent_by` | `int` | who created the request (health worker or patient) |
| `answered_by` | `int` | FK &rarr; `users`, nullable: the doctor who replied (useful for "any doctor" requests) |
| `clinical_notes` | `text` | symptoms, observations, treatment already given |
| `doctor_response` | `varchar(255)` | what the doctor wrote back |
| `responded_at` | `datetime` | used for the average reply time in the reports |
| `created_at` | `timestamp` | |

## 6. `audit_logs`

Append-only. Nothing in the application updates or deletes a row here.

| Column | Type | Notes |
|---|---|---|
| `log_id` | `bigint` auto-increment | primary key |
| `user_id` | `int` | FK &rarr; `users`, `ON DELETE SET NULL`; null for a failed login |
| `action` | `varchar(40)` | `LOGIN`, `LOGIN_FAILED`, `LOGIN_BLOCKED`, `LOGOUT`, `REGISTER`, `CREATE`, `LINK`, `BOOK`, `CONFIRM`, `RESCHEDULE`, `DECLINE`, `COMPLETE`, `WITHDRAW`, `TOGGLE_ACTIVE`, `RESET_PASSWORD`, `EXPORT`, `SEED` |
| `entity` | `varchar(40)` | `user`, `patient`, `appointment`, `facility`, `audit_log`, `database` |
| `entity_id` | `int` | the affected row |
| `details` | `varchar(255)` | free text, e.g. `pending -> confirmed, new date 2026-11-01` |
| `ip_address` | `varchar(45)` | IPv6-sized |
| `created_at` | `timestamp` | |

---

## How this maps to the six-entity ERD

| ERD entity | Table |
|---|---|
| Facility | `facilities` |
| User | `users` |
| Patient | `patients` |
| Appointment | `appointments` |
| Consultation | `consultations` |
| AuditLog | `audit_logs` |

The doctor's reply lives on the **consultation** (`doctor_response`,
`answered_by`, `responded_at`), because it answers the consultation request;
the appointment keeps only the scheduling facts (centre, doctor, date, status).

## Notes for anyone porting it to PostgreSQL

| MySQL / MariaDB here | PostgreSQL |
|---|---|
| `AUTO_INCREMENT` | `GENERATED BY DEFAULT AS IDENTITY` |
| inline `ENUM('a','b')` | a named `CREATE TYPE ... AS ENUM` |
| `TINYINT(1)` for booleans | `boolean` |
| `LAST_INSERT_ID()` / `lastInsertId()` | `INSERT ... RETURNING id` |
| `DATE_ADD(CURDATE(), INTERVAL 3 DAY)` | `CURRENT_DATE + 3` |
| `DATE_FORMAT(d, '%Y-%m')` | `to_char(d, 'YYYY-MM')` |
| `FIELD(status, ...)` for ordering | `CASE status WHEN ... THEN 1 ... END` |
| `TIMESTAMPDIFF(MINUTE, a, b)` | `EXTRACT(EPOCH FROM (b - a)) / 60` |
| `NOW() - INTERVAL 15 MINUTE` | `CURRENT_TIMESTAMP - INTERVAL '15 minutes'` |
| duplicate-key SQLSTATE `23000` | `23505` |
| `SUM(cond)` (boolean to 0/1) | `COUNT(*) FILTER (WHERE cond)` |
| DOB-in-past checked in PHP | can be a `CHECK (dob <= CURRENT_DATE)` |

A working PostgreSQL build of this same system exists in the project's history
if you need it.
