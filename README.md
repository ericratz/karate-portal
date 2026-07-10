# Shotokan Karate Portal — V3.5

A private membership portal for Noji Ratzlaff's Shotokan Karate dojo. Students, parents, and instructors each get a tailored dashboard — tracking attendance, belt tests, payments, and waivers. The admin runs the full operation from one place. A Playwright + PHPUnit test suite runs automatically on every push via GitHub Actions.

---

## What's New in V3.5

- **Parent-card real-time bug fixed** — changing a student's account type to Parent on `admin/student_edit.php` now updates the Guardian/Parent card to "Linked Family" immediately via an htmx out-of-band swap (`hx-swap-oob` on `#guardian-card`), instead of requiring an exit-and-re-enter workaround
- **`member_card.php` IDOR fixed** — student/parent roles could view any other family's member card (name, rank, registration date) by changing `student_id` in the URL; it now validates the requested ID against the logged-in user's own record + linked children, matching the pattern already used on every other parent-facing page. Instructor/admin retain full roster access
- **Security verification pass** — nmap, Nikto, OWASP ZAP, sqlmap, and Burp Suite run against the app to verify the above fixes and probe for further issues; findings were either resolved (the IDOR above) or confirmed to be local-dev-only artifacts with no equivalent on the live server (verbose PHP error display, exposed `Server`/`X-Powered-By` headers — both already hardened on production)
- **CSP `style-src` review** — audited every interpolated `style=""` attribute in the codebase for injection risk; all take fixed/internal values (booleans, counts, a hardcoded belt-color table), none reflect user input. `unsafe-inline` in `style-src` is deferred as accepted low-severity risk rather than cleaned up — removing it would mean auditing/rewriting ~300 static `style=""` attributes across 43 files for a directive that only guards CSS injection, not the XSS-capable `script-src` (already nonce-only since V3.4)
- **Log retention** — new `apply_log_retention()` purges `error_log` rows older than 1 month and `email_log`/`activity_log` rows older than 6 months, run opportunistically on admin dashboard load (same pattern as the existing auto-inactivation check)
- **Test DB snapshot/restore speed-up** — `error_log`, `email_log`, and `activity_log` are now excluded from the Playwright test snapshot/restore cycle via `mysqldump --ignore-table`, since log data isn't part of test fixture state
- **PHP upgraded to 8.4** on the live server
- **CI/CD faster** — removed the redundant Composer install step from the GitHub Actions workflow (dependencies were already committed), cutting the pipeline from ~5.0 minutes to ~3.3 minutes
- **Test suite audit and expansion** — swept the full Playwright suite for silently-skipping tests (`if (count === 0) return`-style guards that pass without ever asserting anything); converted 30+ into real, always-executing assertions backed by verified fixture data, self-seeded fixtures where none existed, or documented `test.skip` where the environment genuinely can't support it (e.g., local `mail()` always fails, so an email-status-`sent` filter test can never have a row to check). Also caught and fixed 3 tests that had silently gone stale (an admin-only UI check running as instructor, a selector for a `<select>` element removed by the V3.3 belt-test rewrite, a certificate test using a rank the fixture student was never actually awarded). Added regression tests for gaps found across V3.1–V3.4: the CSP inline-handler hardening, the payments.php XSS fix, the belt-test date-edit duplicate-rank bug, admin waiver always-editable, the Linked Family card, the Center Stage YTD stat, roster search by email/phone, and certificate.php's actual rendered content. New PHPUnit coverage added for previously-untested pure functions in `belt_helpers.php`, `registration.php`, `paypal.php`, and the new `log_retention.php`
- **Test coverage** — 503 Playwright tests (39 spec files), 91 PHPUnit tests, all passing

---

## What's New in V3.4

- **CSP hardened — `unsafe-inline` removed from `script-src`** — every inline `onclick`/`onchange`/`onsubmit`/`oninput` handler across the portal was converted to external JS via delegated event listeners (`data-fn`/`data-arg` dispatcher and a shared `confirm-submit-form` pattern for delete confirmations), and every inline `<script>`/`<style>` tag now carries a per-request nonce (`csp_nonce()` in `includes/auth.php`). The CSP header itself moved from a static `.htaccess` directive to a dynamic PHP `header()` call so it can embed that nonce; `checkin.php` (a standalone page with no `auth.php`) got its own copy since it needs its own nonce. `style-src` keeps `unsafe-inline` (styles are a much lower-severity injection vector than scripts, and a huge amount of legitimate UI logic — dropdown toggles, card collapse, htmx's own injected indicator CSS — relies on dynamic inline styles) — `script-src` is the directive that actually stops XSS, and it's now nonce-only
- **XSS fix** — `admin/payments.php`'s "+" prefill button built an `onclick` attribute with `addslashes()`-escaped student name, which doesn't escape HTML double-quotes; a self-editable name containing `"` could break out of the attribute and inject arbitrary markup/scripts. Fixed by switching to `htmlspecialchars()`-escaped `data-*` attributes read via a delegated listener
- **Missing PRG (Post/Redirect/Get) fixes** — reloading the page after a mutating POST no longer re-submits it. Fixed across `admin/waivers.php` (duplicate exception grants), `admin/email_students.php` (duplicate mass emails), and several other admin pages that redirected inconsistently
- **htmx swap-scope fixes** — filter bar + results card wrapped in a single container per page (`admin/waivers.php`, `admin/donations.php`, `admin/expenses.php`, `admin/general_notes.php`, `admin/student_notes.php`, `instructor/belt_tests_all.php`, `admin/payments.php`, `instructor/attendance_sessions.php`) so htmx partial swaps no longer leave filter controls or selection state stale
- **Live count updates on delete** — delete handlers on the above pages now fall through to a full re-render for htmx requests instead of exiting early, so result counts update immediately without a page reload
- **Year-dropdown filters** — `admin/donations.php`, `admin/expenses.php`, `admin/general_notes.php`, `admin/student_notes.php`, `instructor/belt_tests_all.php`, `admin/payments.php`, `instructor/attendance_sessions.php` replaced This Month/This Year/Filter buttons with a live-filtering Year dropdown (always includes the current year) plus a Clear button
- **Payments** — removed the dead "Month Covered" add-form field and results column (edit form keeps it as a hidden field so existing values aren't lost); Amount now auto-fills based on selected Payment Type, but only while the field is still blank or holds a previous auto-set value — typing a different amount stops the auto-fill from overwriting it
- **Attendance auto-cleanup** — saving attendance with zero students present deletes the empty `class_sessions` row instead of leaving a ghost session; same rule applied to `checkin.php`'s "unmark" action and `admin/student_edit.php`'s per-student bulk attendance toggle
- **Tuition month picker** — `student/pay.php` and `parent/pay.php` now offer the previous month in addition to current/next 3; default-month logic was hardcoded to array indices `[0]`/`[1]`, which the new option would have silently broken, so it was rewritten to compute the current/next month directly from `date()`
- **`admin/checkin_pin.php`** — removed the "Failed PIN Attempts" table (now just links to the activity log, which already records incorrect PIN attempts including the guessed PIN); masked PIN display gets a View/Hide toggle
- **`admin/student_edit.php` cleanup** — removed the dead "Test Passed" checkbox (pass/fail was already derived from score), removed inline belt-test row editing in favor of a direct link to `belt_test_edit.php`, merged the standalone Active Status and Waiver cards into Profile Info
- **`instructor/student_profile.php` / `instructor/belt_test_edit.php`** — belt test history is now view-only (no inline add/edit); Delete moved below the edit form instead of the header row
- **`parent/index.php`** — added editable Uniform Size / Belt Size fields to the profile card, matching the admin/instructor views
- **`instructor/add_note.php` removed** — adding a student note is now an inline "Add Note" card directly on `instructor/student_profile.php` instead of a separate page; server-side validation (empty note rejected with an error) was carried over to the new inline handler
- **Instructor own-profile edit bug fix** — on `instructor/student_profile.php`, the inline Edit button silently did nothing because the tooltip-init script ran ahead of `bootstrap.bundle.min.js` (loaded in the footer) in the same `<script>` block; the thrown error aborted the rest of the block before the click-delegation listener could bind. Split into its own script block so a tooltip-init failure can't take down the Edit/Cancel/Save handlers
- **Test coverage** — Playwright suite updated to match the above (488 tests), PHPUnit suite unchanged (52 tests); both suites passing

---

## What's New in V3.3

- **Member card** — instructor signature block added (bottom-left); belt border colours darkened per rank; rank pill scaled up 25%; `zoom:0.341` print scaling for credit-card size; 3-letter month abbreviation; text selection disabled
- **Rank certificate** — new `admin/certificate.php` generates a printable certificate from a scanned template with student name, rank, date, certificate number, and instructor signature; PDF download via html2canvas + jsPDF. Migration `002_cert_number.sql` adds `cert_number` to `student_ranks` and backfills all existing ranks with sequential `SKSD-XXXX` numbers
- **Self check-in** — new `checkin.php` lets students type their name and tap to mark present; PIN-gated per calendar day (Mountain time); PIN rate-limited using the same `login_attempts` table as login (5 per 15 min); failed attempts log on `admin/checkin_pin.php` shows time, IP, and guessed PIN. Migration `003_checkin_pin.sql` creates `checkin_settings`. QR Check-in button on attendance sessions (all instructors); Check-in PIN button (admin only)
- **Belt test edit rewrite** — student selector is now type-to-filter (no active filter); test PDF button in chart header links to the relevant test document with a dynamic label; fee paid auto-checks when date matches an existing paid test; collapsible history section; removed Kata, Grader, back button, and 3rd Dan option
- **Belt test results** — `belt_tests_all.php` and `student_profile.php` show green "Passed" badge, red ✗, or "—" based on `result` field
- **Sessions Attended** — "Recent Attendance" card on `instructor/student_profile.php` renamed and now only shows sessions the student attended
- **Type-to-filter student selectors** — student dropdowns replaced across `payments.php`, `waivers.php`, and `belt_tests_all.php`; active-only filter removed from waivers student query
- **Roster and student notes** — category cards hide when empty (PHP render + JS filter); count badges update live as filters are applied
- **Waiver prefill** — street address and city/state/ZIP now carry over from the student record, matching the existing name/DOB/phone/email prefill
- **Audit log** — logout events no longer recorded; remove existing entries with `DELETE FROM activity_log WHERE action = 'logout'`
- **CSP** — added `cdnjs.cloudflare.com` to `connect-src` to suppress jsPDF sourcemap violation reports

---

## Tech Stack

| Layer | Detail |
|---|---|
| Language | PHP 8.4 |
| Database | MySQL 8.0 — `karate_portal` |
| Frontend | Bootstrap 5 (CDN), vanilla JS |
| Payments | PayPal JS SDK (one-time + subscriptions) |
| Auth | Username/password + Google OAuth |
| Local dev | XAMPP at `C:\Users\ericratz\XAMPP` |
| Tests | Playwright 1.60 — 503 tests (`npm test`) + PHPUnit 9.6 — 91 tests (`cd portal && vendor/bin/phpunit`) |
| CI | GitHub Actions — self-hosted Windows runner, runs on every push to `main` |

---

## Roles

Role is derived at login and stored in `$_SESSION['role']`. It is not a column.

| Role | How it's derived | Access |
|---|---|---|
| `admin` | `users.is_admin = 1` | Everything |
| `instructor` | `students.student_type = 'instructor'` (linked record) | Roster, attendance, belt tests |
| `parent` | `students.student_type = 'parent'` (linked record) | Parent portal (tabbed per child) |
| `student` | `students.student_type = 'student'` (linked record) | Student portal |
| `guest` | No linked student record, or `student_type = 'guest'` | Full student portal |

`students.student_type` controls how the roster groups a person. Recording a registration
payment promotes `student_type` from `guest` to `student`.

---

## Registration Flow

1. Fill in account details → portal searches for matching roster entries (name, DOB, or email)
2. User claims a match, creates a new record, or flags their record wasn't found
3. User confirms → account created, logged in immediately as `guest`
4. Admin resolves the alert → links account to roster, role updated
5. Admin records registration payment → `student_type` promoted to `student`
6. Student signs the digital injury waiver

---

## Payments

Supported types: `monthly_tuition`, `registration`, `belt_test`, `slc_training`, `seminar`, `other`, `donation`.

Supported methods: `paypal`, `cash`, `check`, `mail`.

PayPal mode (sandbox vs live) is controlled by `PAYPAL_MODE`, `PAYPAL_CLIENT_ID`, and `PAYPAL_SECRET` in `.env`. Local `.env` uses sandbox credentials; live server `.env` uses live credentials.

---

## Security

- CSRF tokens on every mutating form
- Login rate limiting (5 failures in 15 min blocks login)
- PIN rate limiting (5 failures in 15 min) for check-in, reusing the same `login_attempts` table
- Bcrypt password hashing
- HttpOnly + SameSite=Lax session cookies, ID regenerated on login
- Role enforcement on every protected page
- Full audit log (logins, edits, deletions, payments — logout events excluded)

---

## Cron Jobs

| Job | Schedule | Purpose |
|---|---|---|
| `cron/backup.php` | Sundays 7:00 AM | Full DB dump, pure PHP/PDO, 8-week retention |
| `cron/attendance_alert.php` | Saturdays 8:00 PM | Alerts if Saturday session wasn't recorded |
| `cron/waiver_alert.php` | Fridays 8:00 AM | Alerts on active students missing a waiver |
| `cron/rent_reminder.php` | Saturdays 7:00 AM | First Saturday of month rent reminder |

See `portal/cron/CRON_SETUP.txt` for cPanel commands.

---

## Cannot Test Locally

- **PayPal flow** — requires a publicly reachable URL
- **Google login** — OAuth callback requires a publicly reachable URL registered in Google Cloud Console
- **Email delivery** — `mail()` silently succeeds; no email is actually sent
- **Cron jobs** — must be scheduled and tested on the live server via cPanel
- **Session timeout** — PHP GC settings differ from production

---

## Local Development

```bash
# 1. Import the database
CREATE DATABASE karate_portal;
SOURCE /path/to/karate_schema.sql;

# 2. Install PHP dependencies
cd portal && php composer.phar install && cd ..

# 3. Install Playwright
npm install

# 4. Create tests/credentials.js (gitignored)
module.exports = {
    ADMIN_USER:  'admin', ADMIN_PASS:  '...',
    INST_USER:   '...',   INST_PASS:   '...',
    STU_USER:    '...',   STU_PASS:    '...',
    GUEST_USER:  '...',   GUEST_PASS:  '...',
    PARENT_USER: '...',   PARENT_PASS: '...',
    W_PASS:      '...',
};

# 5. Optional .env at project root
DB_HOST=localhost
DB_NAME=karate_portal
DB_USER=root
DB_PASS=yourpassword
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
```

Site: `http://localhost/karate/portal`

```bash
npm test          # run all tests
npm run test:ui   # headed (see the browser)
```

---

## Project Structure

```
karate/
├── portal/
│   ├── includes/       # Shared: auth, DB, config, header/footer
│   ├── student/        # Dashboard, attendance, payments, waiver, profile
│   ├── parent/         # Tabbed family dashboard, per-child pages
│   ├── instructor/     # Attendance, roster, belt tests, student profiles
│   ├── admin/          # Full management: payments, expenses, waivers,
│   │                   #   users, audit log, email, donations, backup,
│   │                   #   member cards, rank certificates, check-in PIN
│   └── cron/           # Scheduled jobs
├── tests/              # Playwright test suite (503 tests, 39 spec files)
├── migrations/         # SQL migration scripts
├── karate_schema.sql   # Fresh-install schema with seed data
└── README.md
```
