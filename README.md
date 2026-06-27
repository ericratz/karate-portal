# Shotokan Karate Portal — V3.1

A private membership portal for Noji Ratzlaff's Shotokan Karate dojo. Students, parents, and instructors each get a tailored dashboard — tracking attendance, belt tests, payments, and waivers. The admin runs the full operation from one place. A Playwright + PHPUnit test suite runs automatically on every push via GitHub Actions.

---

## What's New in V3.1

- **HTMX partial card swaps** — all edit cards across `admin/student_edit.php` save in-place without a page reload. Edit → Save → card updates, scroll position preserved
- **Inline profile editing** — students, instructors, and parents can edit their own profile directly on the dashboard card (no separate edit page navigation). Implemented via HTMX on `parent/index.php` and `instructor/student_profile.php`
- **Roster search by email and phone** — the admin roster search bar (`admin/students.php`) now matches email addresses and phone numbers in addition to name
- **Next belt requirements** — student and parent dashboards show the next rank, minimum time-in-rank, and test score threshold needed to advance (`portal/includes/belt_helpers.php`)
- **Belt tests delete confirmation** — `instructor/belt_tests_all.php` delete uses `onsubmit` confirm instead of `hx-confirm` (eliminates double-confirm dialogs)
- **Test coverage expanded** — 483 tests across 37 spec files (Playwright) plus 34 PHPUnit unit/integration tests. New tests cover HTMX inline-edit flows, HTMX card swaps without page reload, and auth boundary checks for `update_profile` handlers
- **GitHub Actions CI** — a self-hosted Windows runner executes the full PHPUnit and Playwright suites on every push to `main`, reporting a pass/fail checkmark directly on GitHub

## What's New in V3.0

- **Password reset** — self-service forgot-password flow (`forgot_password.php` → `reset_password.php`). Tokens expire in 1 hour, single-use, same confirm screen for unknown usernames (no enumeration)
- **Email log** — admin can review all outgoing emails at `admin/email_log.php`, filterable by status, type, and date range. All `mail()` calls go through `log_email()` which records to `email_log`
- **Uniform size and belt size** — new fields on the student record (`000`–`8` for uniforms, `2`–`8` for belts). Editable in admin student edit, instructor student profile, and the student's own profile edit page
- **Attendance bar graph** — student dashboard shows a Chart.js bar chart of attendance for the last 12 months. Bars turn purple for any month in which a rank advancement was recorded
- **Roster quick-view** — registration paid status shows a ✓ or ✗ directly in the roster table (no need to open the full student record)
- **Roster and Attendance nav buttons** — quick-access links in the header bar for admin and instructor roles
- **Security headers** — `.htaccess` sets `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, and a full `Content-Security-Policy` with `report-uri` pointing to `api/csp_report.php`
- **CSP reporting** — `api/csp_report.php` receives browser Content Security Policy violation reports and logs them via `log_event()`
- **Role system overhaul** — `users.role ENUM` removed. Role is now derived at login: `is_admin=1` → `admin`; otherwise `students.student_type` (fallback `guest` if no linked record). Stored as `$_SESSION['role']`
- **Playwright test suite reorganized** — tests moved from flat feature files into role-based directories (`tests/roles/admin/`, `tests/roles/instructor/`, `tests/roles/student/`, `tests/roles/parent/`, `tests/auth/`, `tests/shared/`)

---

## Tech Stack

| Layer | Detail |
|---|---|
| Language | PHP 7.4 |
| Database | MySQL 8.0 — `karate_portal` |
| Frontend | Bootstrap 5 (CDN), vanilla JS |
| Payments | PayPal JS SDK (one-time + subscriptions) |
| Auth | Username/password + Google OAuth |
| Local dev | XAMPP at `C:\Users\ericratz\XAMPP` |
| Tests | Playwright 1.60 (`npm test`) + PHPUnit 9.6 (`cd portal && vendor/bin/phpunit`) |
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
- Bcrypt password hashing
- HttpOnly + SameSite=Lax session cookies, ID regenerated on login
- Role enforcement on every protected page
- Full audit log (logins, edits, deletions, payments)

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
│   │                   #   users, audit log, email, donations, backup
│   └── cron/           # Scheduled jobs
├── tests/              # Playwright test suite (483 tests, 37 spec files)
├── migrations/         # SQL migration scripts
├── karate_schema.sql   # Fresh-install schema with seed data
└── README.md
```
