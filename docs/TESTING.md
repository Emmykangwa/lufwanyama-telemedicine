# Test procedure

The system starts fresh with no demo data, so the manual tests below assume you
have created the standard accounts first: log in as the administrator
(`php tools/install.php` prints the login), add a health centre, create a health
worker and a doctor at it, and register a patient. The getting-started table in
the [README](../README.md) walks through this. The phone numbers quoted in the
tests are placeholders for the accounts you create. Each test says what to do and
what counts as a pass; take a screenshot of each result if you need evidence for
a report appendix.

Start the server first:

```
php -S localhost:8000 router.php
```

---

## 0. The automated checks first

```
php tools/selftest.php       # 73 checks: database, security and isolation invariants (MySQL)
node tools/offline_test.mjs  # 37 checks: the offline draft and queue logic in app.js
```

`tools/selftest.php` runs the parts that are tedious to click through: the schema and enum
types, password hashing, patient and facility data isolation, the
facility-scoped `UPDATE` a doctor performs, injection strings as bound
parameters, output escaping, the validators, the database constraints,
transaction rollback, the audit trail and the interface labels. It seeds its own
fixtures inside one transaction that is rolled back at the end, so it needs no
demo data and leaves the database exactly as it was. It exits non-zero if
anything fails.

`tools/offline_test.mjs` runs the real `assets/js/app.js` against stubs for the
browser objects it touches and asserts what it saved and what it posted: draft
contents, that the CSRF token and the password are never stored, queueing while
offline, sending in order on reconnect, keeping a rejected entry, giving up
after five tries, and surviving a browser with storage disabled.

The sections below are the manual tests. The ones that genuinely need a browser
(page weight over the network, DevTools throttling, the visible banner,
responsive layout) are only here.

---

## 1. Page weight, under 250 KB (NFR2)

1. Open any page, press **F12**, go to **Network**.
2. Tick **Disable cache**, press **F5**.
3. Read the transferred total in the bottom bar.

**Pass:** well under 250 KB. Measured on this build against the seeded data,
uncompressed, with the cache disabled so CSS and JS count every time:

| Page | HTML | + CSS and JS |
|---|---|---|
| `login.php` | 4.9 KB | 45.8 KB |
| `admin/staff.php` (staff directory) | 9.2 KB | 50.1 KB |
| `health/new_appointment.php` | 9.0 KB | 49.9 KB |
| `doctor/dashboard.php` (review queue, 20 per page) | 13.0 KB | 53.9 KB |
| `patient/dashboard.php` | 21.3 KB | 62.2 KB |
| `admin/audit.php` (50 rows per page) | 25.9 KB | 66.8 KB |
| `admin/dashboard.php` (full report) | 29.4 KB | 70.3 KB |
| `health/requests.php` (25 per page) | 32.8 KB | 73.7 KB |
| `index.php` (landing page, with `landing.css`) | 36.6 KB | 94.6 KB |

The heaviest application page is **73.7 KB**, under a third of the budget.
`style.css` is 31.6 KB and `app.js` 9.3 KB, both cached after the first page, so
later pages cost only their HTML. With gzip enabled (the `.htaccess` turns it on)
an application page's HTML transfers in 2 to 5 KB. Every list that grows with
clinic activity is paged. There are no web fonts, no images and no framework:
icons and charts are inline SVG.

## 2. 2G and 3G conditions

1. Network tab &rarr; the **No throttling** drop-down &rarr; **Slow 3G**.
2. Reload, log in, open each dashboard.

**Pass:** every page renders and is usable. Repeat with **Fast 3G**.

## 3. Interruption tolerance (NFR3)

**Draft test**

1. Log in as health worker `0970000002`, open **New request**.
2. Fill in half the form, including the clinical notes.
3. Press **F5**.

**Pass:** the typed values come back. DevTools &rarr; **Application** &rarr;
**Local Storage** shows a `tm_draft_appointment` key.

**Queue test**

4. Fill the form in completely.
5. Network tab &rarr; throttling &rarr; **Offline**.
6. Press Submit.

**Pass:** the red banner appears with a count of `(1)`, nothing is lost, and
Local Storage shows `tm_queue`.

7. Set throttling back to **No throttling**.

**Pass:** within a moment the entry is posted, the page reloads, the appointment is
on the dashboard, and both `tm_queue` and the draft key are gone.

**Known limit:** if the session expired while offline, the queued entry is
rejected (HTTP 400 from the CSRF check), retried up to five times and then
dropped. Log in again before reconnecting.

## 4. Responsive layout (NFR4)

DevTools &rarr; **Ctrl+Shift+M**, then check a small phone (360&times;844), a
tablet (768) and a desktop width.

**Pass:** no horizontal page scrolling, buttons are comfortably tappable, and
wide tables scroll inside their own `.scroll` box instead of stretching the
page.

Measured on this build with Chrome device emulation at 360&times;844, and again
at 320, 768, 1024 and 1280 CSS pixels:

| Check | Result |
|---|---|
| `document.scrollWidth` vs `clientWidth` on every page | equal at every width, so the page never scrolls sideways |
| Elements wider than the viewport outside a `.scroll` box | none |
| Links, buttons, inputs, selects and summaries under 32 px tall | none |
| Body font size on a phone | 17 px |

Date and status are the first two columns of every appointment list, so the
thing a patient actually opens the page for is readable on a 360 px screen
without scrolling the table at all. The remaining columns scroll inside the
card.

Two links are deliberately smaller than 32 px: the site title in the header and
inline links inside a sentence. Neither is a control a user has to hit.

## 5. Role-based access control

Demo logins (`php tools/demo_accounts.php`; `--reset` restores these passwords):
administrator `0970000001` / `Admin@2026`, health worker `0970000002` / `Health@2026`,
doctor `0970000003` / `Doctor@2026`, patient `0970000004` / `Patient@2026`.
In development the login page lists every demo account whose password still works.

| Test | Do | Pass |
|---|---|---|
| Patient cannot reach admin | Log in as `0970000004`, open `/admin/dashboard.php` | `403 Access denied` |
| Patient cannot reach the doctor queue | open `/doctor/dashboard.php` | `403 Access denied` |
| Health worker cannot register staff | log in as `0970000002`, open `/admin/staff_new.php` | `403 Access denied` |
| Doctor cannot reach the staff directory | log in as `0970000003`, open `/admin/staff.php` | `403 Access denied` |
| Public registration creates patients only | open `/register.php` | no role choice on the form; the account is a patient |
| Logged out, nothing is reachable | open `/patient/dashboard.php` in a private window | redirected to the login page |

## 6. Staff registration by the administrator (FR1)

1. Log in as `0970000001`, open **Staff** &rarr; **Add staff member**.
2. Choose **Doctor**, a name, phone `097 999 0001` and the health centre **Clinic**;
   leave **Generate a temporary password** selected and save.

**Pass:** the account page opens with a one-time panel showing the phone number and
temporary password; reloading the page no longer shows the password; the new doctor
can sign in with it and lands on the review queue for Clinic.

3. Try to add another account with `+260 97 999 0001`.

**Pass:** refused as a duplicate (numbers are stored in one canonical form).

4. Try to add a health worker with no health centre.

**Pass:** refused; only administrators may have no centre.

## 7. Data isolation

Do step 6 first and also add a health worker at **Clinic**, so a second centre
has its own staff.

| Test | Do | Pass |
|---|---|---|
| One patient cannot see another's rows | Log in as `0970000004`; the dashboard lists only her own appointments | no other patient's name appears |
| Health workers are separated | Log in as the Clinic health worker: **Patients** and **Requests** show Clinic only | no Lufwanyama RHC patient or request |
| A doctor cannot open another centre's case | As the Clinic doctor, open `/doctor/case.php?id=` with a consultation id from Lufwanyama RHC | *That request is not in your queue* and no row changes |
| A doctor cannot answer another doctor's named request | Open a case at the same centre addressed to a different named doctor | same refusal; the queue never listed it either |
| Patient list is centre-scoped | As `0970000002`, **New request**: the patient drop-down holds this centre's patients only | other centres' patients absent |
| Doctor list is centre-scoped | As `0970000004`, **Request appointment**: pick a centre | only doctors who work there can be chosen |

The doctor cases are the important ones: the `UPDATE` itself carries
`AND facility_id = ? AND (doctor_id IS NULL OR doctor_id = ?)`, so a forged form
id cannot change another centre's or another doctor's appointment even if the
read-side check were removed. `tools/selftest.php` section 6 proves this
directly against the database.

## 8. CSRF

1. Log in as a health worker and open **New appointment**.
2. In DevTools &rarr; Elements, delete the hidden `csrf` input (or change one
   character of its value).
3. Submit.

**Pass:** `Invalid or expired request.` and nothing is written.

## 9. SQL injection

| Field | Input | Pass |
|---|---|---|
| Login id | `' OR '1'='1` | login simply fails; one `LOGIN_FAILED` row in the audit log |
| Login id | `admin'--` | the same |
| Patient search | `%' OR 1=1 --` | zero or literal matches, never the whole table |
| Audit filter | `' UNION SELECT ...` | no error, no extra rows |

Everything is a bound parameter with `PDO::ATTR_EMULATE_PREPARES => false`, so
the input is sent separately from the statement and is never parsed as SQL.

## 10. Output escaping (XSS)

1. As a health worker, register a patient named
   `<script>alert(1)</script>Test`.
2. Open the patient list, the new-appointment form and the doctor dashboard.

**Pass:** the name is shown as literal text, no dialog appears, and
`view-source` shows `&lt;script&gt;`. The Content-Security-Policy would block an
injected inline script even if an escape were missed.

## 11. Password storage

1. In phpMyAdmin run `SELECT phone, password_hash FROM users LIMIT 3;`
   (or `mysql -u root telemedicine -e "SELECT phone, password_hash FROM users LIMIT 3;"`)

**Pass:** every value starts with `$argon2id$` (or `$2y$` on a build without
Argon2). No readable password anywhere.

2. Log in as the administrator, **Reset a password**, set a new one, log in with
   it.

**Pass:** the new password works, the old one does not, and a
`RESET_PASSWORD` row appears in the audit log.

## 12. Session handling

| Test | Do | Pass |
|---|---|---|
| Cookie flags | DevTools &rarr; Application &rarr; Cookies | `TELEMEDSESSID` has `HttpOnly` and `SameSite=Lax` (plus `Secure` over HTTPS) |
| Fixation | note the session id before logging in | the id is different after login |
| Idle timeout | leave a session for 30 minutes, then click a link | returned to the login page with *Your session ended for safety* |
| Logout | log out, then press Back | the protected page is not served |

## 13. Login throttle

The throttle has two tiers, because a whole clinic usually shares one public
address: **8 failures for one phone number or email** and **40 failures from one
address**, both counted over the last 15 minutes.

1. Submit a wrong password for `0970000001` eight times.
2. Try again, this time with the correct password.

**Pass:** the attempt is refused with *Too many failed attempts* even though the
password was right, and `LOGIN_BLOCKED` rows appear in the audit log.

3. Now log in as `0970000002` from the same browser with the correct password.

**Pass:** that account logs in normally. One account being attacked does not
lock out the rest of the clinic.

## 14. Audit trail (FR6)

Log in as the administrator and open **Audit log**.

**Pass:** every login, failed login, registration, appointment, doctor
response, withdrawal, account change and export is listed with the user, their
role, the record, the detail line, the IP address and the time. Filter by
action or by name, then press **CSV** to download the filtered rows.

## 15. Validation

| Field | Input | Expected message |
|---|---|---|
| Registration phone | `12345` | *Enter a valid phone number: 9 to 15 digits* |
| Registration password | `short` | *Password must be at least 8 characters* |
| Registration password | `aaaaaaaa` | *Password is too simple* |
| Appointment date | yesterday | *Please choose today or a date in the future* |
| Appointment department | a value edited in the HTML | *Please select a department* |
| Appointment patient | another centre's patient id | *Please select a patient from this facility register* |
| Reschedule | no new date given | *To reschedule, pick a new date that is today or later* |
| Duplicate phone | an already registered number | *already registered* |

## 16. Transactions

A new appointment writes an `appointments` row and a `consultations` row in one
transaction. To see the rollback, temporarily rename the `consultations` table,
submit a new appointment, and check that **no** orphan appointment was created.

```sql
RENAME TABLE consultations TO consultations_x;   -- break it
-- submit a new appointment: "Could not save the request. Nothing was changed."
SELECT COUNT(*) FROM appointments;               -- unchanged
RENAME TABLE consultations_x TO consultations;   -- put it back
```

`tools/selftest.php` section 11 also proves the rollback automatically, without
touching the live tables.

## 17. HTTPS behaviour (NFR1)

Localhost is exempt so development needs no certificate. On any other host
`includes/init.php` sends a 301 to `https://`, marks the session cookie
`Secure` and adds `Strict-Transport-Security`. To see the redirect logic
without a server, set a host name other than `localhost` in your hosts file and
open the site over `http://`.

## 18. Responsive interface and motion

Resize the window from a phone width (360 px) up to desktop.

**Pass:** the header menu collapses into a **Menu** button on small screens and
expands to a row on wide ones; cards, tables and the stats grid reflow without
sideways scrolling; buttons and tiles lift slightly on hover. With the operating
system set to "reduce motion", the entrance and hover animations are suppressed.
