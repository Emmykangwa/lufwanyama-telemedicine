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
| `login.php` | 1.8 KB | 13.0 KB |
| `index.php` | 2.1 KB | 13.3 KB |
| `patient/dashboard.php` | 3.3 KB | 14.5 KB |
| `health/new_appointment.php` | 3.9 KB | 15.1 KB |
| `admin/dashboard.php` (full report) | 4.3 KB | 15.4 KB |
| `doctor/dashboard.php` (5 requests) | 8.5 KB | 19.7 KB |
| `admin/audit.php` (200 log rows) | 9.4 KB | 20.6 KB |
| `admin/users.php` (10 accounts) | 11.6 KB | 22.7 KB |

The heaviest page is **22.7 KB**, about a tenth of the budget. `style.css` is
5.2 KB and `app.js` 5.9 KB, both cached after the first page, so later pages
cost only their HTML. With gzip enabled (the `.htaccess` turns it on) every
page lands between 5 and 8 KB. There are no web fonts, no images and no
framework: the icons are emoji characters in the HTML.

## 2. 2G and 3G conditions

1. Network tab &rarr; the **No throttling** drop-down &rarr; **Slow 3G**.
2. Reload, log in, open each dashboard.

**Pass:** every page renders and is usable. Repeat with **Fast 3G**.

## 3. Interruption tolerance (NFR3)

**Draft test**

1. Log in as health worker `0970000002`, open **New appointment**.
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

| Test | Do | Pass |
|---|---|---|
| Patient cannot reach admin | Log in as `0970000006`, open `/admin/dashboard.php` | `403 Access denied` |
| Patient cannot reach the doctor queue | open `/doctor/dashboard.php` | `403 Access denied` |
| Health worker cannot reach reports | log in as `0970000002`, open `/admin/dashboard.php` | `403 Access denied` |
| Logged out, nothing is reachable | open `/patient/dashboard.php` in a private window | redirected to the login page |

## 6. Data isolation

| Test | Do | Pass |
|---|---|---|
| One patient cannot see another's rows | Log in as `0970000006`; the dashboard lists only her own appointments | no other patient's name appears |
| Health workers are separated | Log in as `0970000003` (Centre B): **Patients** and the dashboard show Centre B only | no Centre A patient or request |
| A doctor cannot answer another centre's request | While logged in as `0970000004` (Centre A), POST a `consultation_id` whose appointment is at Centre B | *That request is not yours to answer* and no row changes |
| A doctor cannot answer another doctor's named request | POST a `consultation_id` for a request at Centre A addressed to a different named doctor | same refusal; the queue never listed it either |
| Patient list is centre-scoped | As `0970000002`, **New appointment**: the patient drop-down holds Centre A patients only | Centre B patients absent |
| Doctor list is centre-scoped | On the same form, only doctors who work at Centre A appear | Centre B doctors absent |

The doctor cases are the important ones: the `UPDATE` itself carries
`AND facility_id = ? AND (doctor_id IS NULL OR doctor_id = ?)`, so a forged form
id cannot change another centre's or another doctor's appointment even if the
read-side check were removed. `tools/selftest.php` section 6 proves this
directly against the database.

## 7. CSRF

1. Log in as a health worker and open **New appointment**.
2. In DevTools &rarr; Elements, delete the hidden `csrf` input (or change one
   character of its value).
3. Submit.

**Pass:** `Invalid or expired request.` and nothing is written.

## 8. SQL injection

| Field | Input | Pass |
|---|---|---|
| Login id | `' OR '1'='1` | login simply fails; one `LOGIN_FAILED` row in the audit log |
| Login id | `admin'--` | the same |
| Patient search | `%' OR 1=1 --` | zero or literal matches, never the whole table |
| Audit filter | `' UNION SELECT ...` | no error, no extra rows |

Everything is a bound parameter with `PDO::ATTR_EMULATE_PREPARES => false`, so
the input is sent separately from the statement and is never parsed as SQL.

## 9. Output escaping (XSS)

1. As a health worker, register a patient named
   `<script>alert(1)</script>Test`.
2. Open the patient list, the new-appointment form and the doctor dashboard.

**Pass:** the name is shown as literal text, no dialog appears, and
`view-source` shows `&lt;script&gt;`. The Content-Security-Policy would block an
injected inline script even if an escape were missed.

## 10. Password storage

1. In phpMyAdmin run `SELECT phone, password_hash FROM users LIMIT 3;`
   (or `mysql -u root telemedicine -e "SELECT phone, password_hash FROM users LIMIT 3;"`)

**Pass:** every value starts with `$argon2id$` (or `$2y$` on a build without
Argon2). No readable password anywhere.

2. Log in as the administrator, **Reset a password**, set a new one, log in with
   it.

**Pass:** the new password works, the old one does not, and a
`RESET_PASSWORD` row appears in the audit log.

## 11. Session handling

| Test | Do | Pass |
|---|---|---|
| Cookie flags | DevTools &rarr; Application &rarr; Cookies | `TELEMEDSESSID` has `HttpOnly` and `SameSite=Lax` (plus `Secure` over HTTPS) |
| Fixation | note the session id before logging in | the id is different after login |
| Idle timeout | leave a session for 30 minutes, then click a link | returned to the login page with *Your session ended for safety* |
| Logout | log out, then press Back | the protected page is not served |

## 12. Login throttle

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

## 13. Audit trail (FR6)

Log in as the administrator and open **Audit log**.

**Pass:** every login, failed login, registration, appointment, doctor
response, withdrawal, account change and export is listed with the user, their
role, the record, the detail line, the IP address and the time. Filter by
action or by name, then press **CSV** to download the filtered rows.

## 14. Validation

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

## 15. Transactions

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

## 16. HTTPS behaviour (NFR1)

Localhost is exempt so development needs no certificate. On any other host
`includes/init.php` sends a 301 to `https://`, marks the session cookie
`Secure` and adds `Strict-Transport-Security`. To see the redirect logic
without a server, set a host name other than `localhost` in your hosts file and
open the site over `http://`.

## 17. Responsive interface and motion

Resize the window from a phone width (360 px) up to desktop.

**Pass:** the header menu collapses into a **Menu** button on small screens and
expands to a row on wide ones; cards, tables and the stats grid reflow without
sideways scrolling; buttons and tiles lift slightly on hover. With the operating
system set to "reduce motion", the entrance and hover animations are suppressed.
