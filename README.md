# Shotokan Karate Portal

A private membership portal for Noji Ratzlaff's Shotokan Karate dojo. Students can track
their attendance, belt tests, and payments; instructors can take attendance and manage belt
tests; and the admin can run the whole operation from one place.

---

## Tech Stack

| Layer | Detail |
|---|---|
| Language | PHP 7.x |
| Database | MySQL 8.0 — DB: `karate_portal` |
| Frontend | Bootstrap 5 (CDN), vanilla JS |
| Payments | PayPal JS SDK (sandbox by default) |
| Local dev | XAMPP at `C:\Users\ericratz\XAMPP` |
| Tests | Playwright 1.60 (`npm test`) |

---

## Roles

There are three roles, stored in `users.role`. Each user account is optionally linked to a
row in the `students` table via `students.user_id`. An account that exists but has no linked
roster entry lands on `student/profile_edit.php` after login, where they can fill out their
info and contact Noji with questions.

### Student

The default role for anyone who creates an account via `register.php`.

**What a student can do:**

- View their personal dashboard — recent attendance, current rank, recent payments, belt
  test history, injury waiver status
- Browse their full attendance history (dates attended only — no absent entries or percentages shown)
- Browse their belt test history (date, rank tested for, pass/fail, fee status)
- Browse their payment history
- Make a payment online via PayPal (`pay.php` → PayPal JS SDK)
- Sign the digital injury waiver (`waiver.php`) and later view their signed copy
- Edit their profile (name, email, date of birth) and change their password
- Send a message to Noji directly from the dashboard ("Questions or Issues?" block)

**What a student cannot do:**

- See any other student's data
- See absent entries or attendance percentages
- Take attendance, schedule belt tests, or access any admin or instructor page

---

### Instructor

Granted manually by the admin. Has full read access to the roster and takes attendance.

**What an instructor can do (everything a student can, plus):**

- View the full roster split into three groups: Instructors, Students, and Guests
- Filter the roster by name, status (active/inactive), has-login, rank, injury waiver, and
  recent attendance (last 30 days / last 90 days / this year / never)
- View any student's full profile page — attendance history with present/absent status and
  percentage, belt test history, rank, notes
- Edit a student's attendance directly from the student profile view (instructors only —
  admins use the edit profile page instead)
- Take attendance for any class date — creates a session if one doesn't exist yet, or edits
  an existing one; list can be sorted by last attended or by last name, and filtered by name
- View the Attendance by Session page — date-range filterable list of every session showing
  present/absent counts, with expandable rows listing names (First L. format); includes
  "This Month" and "This Year" quick filters
- Add private notes to a student's profile
- Schedule new belt tests, edit existing ones, record pass/fail results, and delete tests

**What an instructor cannot do:**

- Add, edit, or delete student roster entries
- Record or delete payments
- Access the admin section

---

### Admin

Full access to everything. Intended for the dojo owner only. Admins see an "Admin" dropdown
in the nav bar (no separate nav link for the instructor dashboard — it lives inside the
dropdown under the Instructor section).

**What an admin can do (everything an instructor can, plus):**

- **Roster management** — add new students, edit all student fields (name, DOB, email, rank,
  active status, `active_override`, student type), delete a profile. Roster is filterable by
  name, status, has-login, rank, injury waiver, and recent attendance.
- **Edit student profile** — full edit page (`admin/student_edit.php`) with Profile Info,
  Recent Attendance, Active Status, Belt Test History (with add), Payment History (with add),
  Payment Waivers, and Account Details cards
- **Payments** — record manual payments for any student (monthly tuition, registration fee,
  belt test fee, SLC training, seminar, other); choose payment method (PayPal, Venmo, cash,
  check, mail); delete incorrect records. Filterable by student, type, method, date range,
  with "This Month" and "This Year" quick filters.
- **Payment waivers** — grant a student a tuition exemption (permanent or with expiry) so
  they show as "paid" without an actual payment entry
- **Guest promotion** — recording a registration payment for a guest automatically upgrades
  their `student_type` from `guest` to `student`
- **Expenses** — log dojo expenses (rent, equipment, utilities, supplies, other), mark
  paid/unpaid, delete records. Filterable by type, paid status, and date range with quick
  filters.
- **Injury waivers** — view a signed waiver for any student; mark a waiver as recorded
  manually (paper); view the full waivers list with status for every active student
- **Email students** — compose a plain-text email and send it to any combination of
  students, instructors, and/or guests; each email is addressed to the recipient by name
- **Class notes** — add and delete general notes that appear in the class log
- **Student notes** — add private per-student notes (also available to instructors)
- **Users** — view all login accounts, link an existing user account to a roster entry,
  toggle active status, reset passwords, unlink accounts. Filterable by role (including
  guest), status, and linked status.
- **User profile** — view/edit a specific user account; shows account created date, linked
  roster entry, last login, and offers a confirmation-protected link action for unlinked
  accounts with a matching roster entry
- **Audit log** — read-only log of all significant actions (logins, edits, deletions,
  payments, etc.) with username, IP, and timestamp
- **Admin dashboard** — at-a-glance stats: active student count, revenue for the month and
  YTD, which students haven't paid tuition this month, which active students are missing an
  injury waiver, and any user accounts that are an unlinked name/email match for a roster
  entry

---

## Student Types vs. Roles

These are two separate concepts that often get conflated.

| Field | Table | What it controls |
|---|---|---|
| `users.role` | `users` | Which pages a login can access (`student` / `instructor` / `admin`) |
| `students.student_type` | `students` | How the roster groups a person (`guest` / `student` / `instructor` / `admin`) |

A brand-new self-registration creates a `users` row with `role = student` and a `students`
row with `student_type = guest`. Once the admin records a registration payment the
`student_type` flips to `student` automatically. Roster entries marked `student_type =
instructor` or `admin` appear in the Instructors section of the roster but their portal
access is controlled entirely by `users.role`.

---

## Active / Inactive Status

`students.active` is maintained automatically by `apply_auto_inactive()`, which runs every
time the instructor or admin dashboard loads (so it never needs a cron job to stay current):

- A student with **no attendance in the last 3 months** is set to `active = 0`.
- A student who **attends a class** is set back to `active = 1`.
- If `students.active_override` is set to `1` or `0`, the auto-rule is skipped for that
  student and the override value is used instead. The admin can force-activate or
  force-deactivate any student this way. An "Override" badge appears on the roster when this
  is in effect.

Only active students appear in the "Unpaid tuition" list on the admin dashboard. Inactive
students still appear in the payment recording dropdown so the admin can record a catch-up
payment.

---

## Registration Flow

1. Anyone can register at `/portal/register.php` — creates a `users` row and a linked
   `students` row with `student_type = guest`.
2. If the account has no linked roster entry after login, the student sees only their profile
   edit page until they fill out their info.
3. The admin records a registration-fee payment for the guest via `admin/payments.php`.
4. This automatically flips `student_type` to `student`.
5. The student then signs the digital injury waiver at `/student/waiver.php`.

---

## Payments

Supported payment types: `monthly_tuition`, `registration`, `belt_test`, `slc_training`,
`seminar`, `other`.

Supported payment methods: `paypal`, `venmo`, `cash`, `check`, `mail`.

Configured fees (in `portal/includes/config.php`):

| Fee | Amount |
|---|---|
| Monthly tuition | $30 |
| Registration | $15 |
| Belt test | $10 |
| SLC training | $10 |
| Seminar | $60 |

Students pay online via PayPal (`/student/pay.php`). The PayPal JS SDK is currently
configured in **sandbox mode** — switch `PAYPAL_MODE` to `'live'` and update
`PAYPAL_CLIENT_ID` / `PAYPAL_SECRET` in `config.php` before going to production.

---

## Security

- **Session hardening** — `HttpOnly`, `SameSite=Lax`, session ID regenerated on login
- **CSRF protection** — every mutating form includes a hidden `csrf_token`; `verify_csrf()`
  checks it server-side before any write operation
- **Login rate limiting** — 5 or more failed login attempts in 15 minutes from the same
  username **or** the same IP blocks further logins. The counter is stored in the
  `login_attempts` table and cleared automatically after 1 hour
- **Password hashing** — bcrypt via `password_hash()` / `password_verify()`; hashes are
  automatically upgraded if the cost factor changes
- **Role enforcement** — every protected page calls `require_role()` at the top; accessing
  a page above your role returns HTTP 403 "Access denied."
- **Audit log** — every login, logout, edit, delete, and payment is recorded in `audit_log`
  with user ID, username, action, target, IP address, and timestamp
- **Confirmation dialogs** — destructive or irreversible actions (linking accounts, deleting
  sessions, deleting payments) use a `type="button"` + JS `confirm()` + `form.submit()`
  pattern rather than `onsubmit`, which is more reliable across browsers

---

## Automated Cron Jobs (production / cPanel)

| Job | Schedule | What it does |
|---|---|---|
| `cron/backup.php` | Wednesdays at 2:00 AM | Dumps the full database with `mysqldump`, gzips it, keeps 8 weeks of backups outside `public_html`; emails only on failure |
| `cron/attendance_alert.php` | Sundays at 7:00 AM | Checks whether a session was recorded for the previous Saturday; emails Noji if not |
| `cron/waiver_alert.php` | Daily at 8:00 AM | Emails Noji a list of active students with no injury waiver on file |
| `cron/rent_reminder.php` | Saturdays at 7:00 AM | On the first Saturday of the month, emails Noji a reminder about rent |

See `portal/cron/CRON_SETUP.txt` for the exact cPanel commands.

---

## Cannot Test Locally

The following features exist in the codebase but cannot be meaningfully tested in a local
XAMPP environment:

- **PayPal flow** — sandbox credentials are configured but PayPal's JS SDK requires a
  publicly reachable capture endpoint; `paypal_capture.php` will not receive callbacks from
  localhost
- **Session timeout** — PHP session GC is configured server-side; local XAMPP settings may
  not match production and the timeout cannot be reliably triggered in automated tests
- **Concurrent session handling** — the portal does not currently enforce single-session
  login; testing race conditions or session conflicts requires a multi-user load setup
- **Email delivery** — all `mail()` calls silently succeed on localhost but no email is
  actually sent; SMTP relay must be configured on the production host to verify delivery of
  alerts, rent reminders, and student emails

---

## Local Development

### Prerequisites

- XAMPP (MySQL + Apache) running at `http://localhost`
- Node.js (for Playwright)

### First-time setup

```bash
# 1. Import the database
# In phpMyAdmin or MySQL CLI:
#   CREATE DATABASE karate_portal;
#   USE karate_portal;
#   SOURCE /path/to/karate_portal.sql;

# 2. Install Playwright from root folder
npm install

# 3. (Optional) create a .env file in the project root:
#   DB_HOST=localhost
#   DB_NAME=karate_portal
#   DB_USER=root
#   DB_PASS=yourpassword
```

The site is then available at `http://localhost/karate/portal`.

### Running tests

```bash
npm test
# or to see the browser:
npx playwright test --headed
```

Playwright takes a DB snapshot before the suite starts and restores it if any test fails, so
the DB is always left in a clean state. The test timeout is 5 seconds per assertion; long
operations (password change, cleanup hooks) call `test.setTimeout(30_000)` explicitly.

---

## Project Structure

```
karate/
├── portal/
│   ├── index.php               # Redirects to role-appropriate dashboard
│   ├── login.php
│   ├── register.php
│   ├── logout.php
│   ├── paypal_create.php       # PayPal order creation endpoint
│   ├── paypal_capture.php      # PayPal order capture endpoint
│   ├── includes/
│   │   ├── auth.php            # Session, CSRF, rate limiting, roles, audit log
│   │   ├── db.php              # PDO singleton — call db() anywhere after require
│   │   ├── config.php          # Site constants and fee amounts
│   │   ├── auto_inactive.php   # apply_auto_inactive() helper
│   │   ├── header.php          # Shared nav bar (role-aware links)
│   │   └── footer.php          # Bootstrap JS bundle
│   ├── student/                # Student-only pages
│   │   ├── index.php           # Student dashboard
│   │   ├── attendance.php      # Full attendance history (present dates only)
│   │   ├── belt_tests.php      # Full belt test history
│   │   ├── payment_history.php # Full payment history
│   │   ├── pay.php             # PayPal payment page
│   │   ├── profile_edit.php    # Edit profile + change password + Questions or Issues
│   │   └── waiver.php          # Digital injury waiver
│   ├── instructor/             # Instructor + admin pages
│   │   ├── index.php           # Instructor dashboard (take attendance, recent sessions, belt tests)
│   │   ├── attendance.php      # Take / edit attendance for a session
│   │   ├── attendance_sessions.php  # Browse sessions by date range with expand/collapse
│   │   ├── student_profile.php # Student profile view (instructors can edit attendance here)
│   │   ├── students.php        # Roster with full filtering
│   │   ├── add_note.php        # Add private note to a student
│   │   ├── belt_test_edit.php  # Add / edit a belt test
│   │   └── belt_tests_all.php  # All belt tests with date filter
│   ├── admin/                  # Admin-only pages
│   │   ├── index.php           # Admin dashboard (stats, unpaid, missing waivers, link suggestions)
│   │   ├── students.php        # Full roster with filtering and + New Student
│   │   ├── student_edit.php    # Full student edit (profile, attendance, payments, waivers, status)
│   │   ├── student_notes.php   # All student notes across roster
│   │   ├── payments.php        # All payments with filtering and manual entry
│   │   ├── expenses.php        # Dojo expenses with filtering
│   │   ├── waivers.php         # Injury waiver status for all active students
│   │   ├── waiver_view.php     # View a specific signed waiver
│   │   ├── email_students.php  # Compose and send email to selected students
│   │   ├── general_notes.php   # Class-wide general notes log
│   │   ├── users.php           # All user accounts with filtering
│   │   ├── user_profile.php    # Individual user account detail and link/edit actions
│   │   └── audit_log.php       # Read-only audit trail
│   └── cron/
│       ├── backup.php          # Weekly DB backup (emails on failure only)
│       ├── attendance_alert.php # Alert if Saturday attendance wasn't recorded
│       ├── waiver_alert.php    # Alert for active students missing a waiver
│       ├── rent_reminder.php   # Monthly rent reminder on first Saturday
│       └── CRON_SETUP.txt      # Exact cPanel cron commands
├── tests/
│   ├── playwright.config.js
│   ├── helpers.js
│   ├── global-setup.js         # DB snapshot + rate-limit flush
│   ├── global-teardown.js      # DB restore on failure
│   ├── db-config.js            # mysqldump path resolution
│   ├── admin.spec.js
│   ├── bugs.spec.js
│   ├── coverage.spec.js
│   ├── email_and_promote.spec.js
│   ├── functional.spec.js
│   ├── security.spec.js
│   └── waiver.spec.js
└── clear_rate_limit.php        # Dev helper — clears login_attempts (called by global-setup)
```
