# Shotokan Karate Portal — V2.1

A private membership portal for Noji Ratzlaff's Shotokan Karate dojo. Students can track
their attendance, belt tests, and payments; instructors can take attendance and manage belt
tests; and the admin can run the whole operation from one place.

---

## What's New in V2.1

- **Medical notes** — students and parents can enter a medical note on their profile; a ⚕ symbol with tooltip appears next to the student's name on the admin and instructor rosters
- **Account type tooltips** — role badges in the nav header and on profile cards now show a tooltip (student: "Registration fee paid", parent: "Family account", guest: "Non-paying participant")
- **Account type in profile info** — student type badge now displayed in the Profile Info card on parent and instructor views
- **Profile info layout** — profile fields converted to horizontal label/value rows across all views (parent, student, instructor, admin)
- **Belt test columns** — Fee (✓ or blank) and Awarded (✓/✗) columns added to belt test tables across all views
- **Parent payment notice** — when a parent selects themselves on the payment page and a child has already paid tuition that month, a notice appears: no pressure, just awareness
- **Child Summary card** — parent dashboard shows a summary table of all linked children (last attendance, last payment, waiver status) on the parent's own tab
- **User accounts split** — admin Users page now separates Linked and Unlinked accounts into two tables
- **Delete user** — admin can delete a login account from the user profile page; student history (attendance, belt tests, payments) is preserved and the roster entry is detached
- **Compare & Link flow** — selecting a student on the user profile page now opens the Compare page directly; student picker auto-populates on selection with no Compare button needed
- **Date of birth on user profile** — birth date from account creation now displayed in the admin user profile view
- **Parent badge color** — parent account type now displays as teal (bg-info) consistently across all views
- **Back-forward cache fix** — navigating back to the Users page now always shows current data instead of a stale cached version


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

There are four roles, stored in `users.role`. Each user account is optionally linked to a
row in the `students` table via `students.user_id`.

### Student

The default role for anyone who creates an account via `register.php`.

**What a student can do:**

- View their personal dashboard — recent attendance, current rank, recent payments, belt
  test history, injury waiver status
- Browse full attendance, belt test, and payment history
- Make a payment online via PayPal
- Sign the digital injury waiver and later view their signed copy
- Edit their profile and change their password
- Send a message to Noji from the dashboard

---

### Parent

A family account linked to one or more children's roster entries via `parent_students`.
Created when an admin sets `student_type = parent` on a roster entry and links children
via the user profile page.

**What a parent can do:**

- Tabbed dashboard — one tab per linked child, showing each child's attendance, rank,
  waiver status, and payment summary
- View full attendance, belt test, and payment history for any linked child
- Make a payment for a specific child (dropdown selector); the system warns if any family
  member has already paid tuition this month
- Sign and view the liability waiver for any linked child
- Edit profile info for any linked child; change their own password on their own tab only

---

### Instructor

Granted manually by the admin. Has full read access to the roster and takes attendance.

**What an instructor can do (everything a student can, plus):**

- View the full roster split into four groups: Instructors, Parents, Students, Guests
- Filter the roster by name, status, has-login, rank, injury waiver, and recent attendance
- View any student's full profile — attendance with present/absent status, belt tests,
  rank, notes; family tabs shown when viewing a parent or child
- Edit a student's attendance from the student profile view
- Take attendance — creates or edits a session; sortable and filterable by name
- Browse sessions with date-range and type filters; expandable rows showing who attended
- Add private notes to a student's profile
- Schedule, edit, record, and delete belt tests; student picker shows current rank and
  full test history inline

---

### Admin

Full access to everything. Intended for the dojo owner only.

**What an admin can do (everything an instructor can, plus):**

- **Roster** — add, edit, and delete students; set student type, active override, and all
  profile fields
- **Payments** — record manual payments, delete incorrect records, full filtering
- **Payment waivers** — grant tuition exemptions
- **Expenses** — log and manage dojo expenses
- **Injury waivers** — view signed waivers and mark manual (paper) waivers
- **Email students** — compose and send to any combination of groups or individuals
- **Class notes** — general notes visible in the class log
- **Student notes** — private per-student notes
- **Users** — view all accounts, toggle active status, reset passwords, link/unlink roster
  entries; role and status badges have tooltips; children are excluded from the link dropdown
- **Compare & Link** — side-by-side account vs roster comparison with field mismatch
  highlighting before committing a link
- **Link Requests** — admin dashboard card showing Notify Noji submissions from new
  registrations, with a Review button that opens the Compare page
- **Audit log** — read-only log of all significant actions

---

## Student Types vs. Roles

| Field | Table | What it controls |
|---|---|---|
| `users.role` | `users` | Portal access (`student` / `parent` / `instructor` / `admin`) |
| `students.student_type` | `students` | How the roster groups a person (`guest` / `student` / `parent` / `instructor` / `admin`) |

Recording a registration payment for a guest automatically promotes `student_type` to
`student`. Setting `student_type = parent` and linking children via the user profile page
creates the family relationship stored in `parent_students`.

---

## Active / Inactive Status

`students.active` is maintained automatically by `apply_auto_inactive()`, which runs every
time the instructor or admin dashboard loads:

- No attendance in the last 3 months → `active = 0`
- Attends a class → `active = 1`
- `active_override` set by admin → auto-rule skipped; "Override" badge shown on roster

Only active students appear in the "Unpaid tuition" and "Missing waivers" lists on the admin
dashboard. Family groups are treated as a unit for the tuition check.

---

## Registration Flow

1. Register at `/portal/register.php` → creates a `users` row
2. Optional "Notify Noji" step — user selects new student / returning student / parent and
   optionally adds a note. Creates a `link_requests` record visible on the admin dashboard.
3. Admin reviews via the Link Requests card → Compare page → links account to roster entry
4. Admin records a registration payment → `student_type` flips from `guest` to `student`
5. Student signs the digital injury waiver

---

## Payments

Supported payment types: `monthly_tuition`, `registration`, `belt_test`, `slc_training`,
`seminar`, `other`.

Supported payment methods: `paypal`, `cash`, `check`, `mail`.

| Fee | Amount |
|---|---|
| Monthly tuition | $30 |
| Registration | $15 |
| Belt test | $10 |
| SLC training | $10 |
| Seminar | $60 |

Students and parents pay online via PayPal. The SDK is in **sandbox mode** — switch
`PAYPAL_MODE` to `'live'` in `config.php` before going to production.

---

## Security

- **Session hardening** — `HttpOnly`, `SameSite=Lax`, session ID regenerated on login
- **CSRF protection** — every mutating form includes a hidden `csrf_token`
- **Login rate limiting** — 5 failed attempts in 15 minutes (same username or IP) blocks
  login; stored in `login_attempts`, cleared after 1 hour
- **Password hashing** — bcrypt, auto-upgraded on cost change
- **Role enforcement** — `require_role()` at the top of every protected page
- **Audit log** — all logins, edits, deletions, and payments logged with IP and timestamp

---

## Automated Cron Jobs (production / cPanel)

| Job | Schedule | What it does |
|---|---|---|
| `cron/backup.php` | Wednesdays 2:00 AM | Full DB dump, gzipped, 8-week retention |
| `cron/attendance_alert.php` | Sundays 7:00 AM | Alerts if Saturday session wasn't recorded |
| `cron/waiver_alert.php` | Daily 8:00 AM | Alerts on active students with no waiver |
| `cron/rent_reminder.php` | Saturdays 7:00 AM | First Saturday of month rent reminder |

See `portal/cron/CRON_SETUP.txt` for exact cPanel commands.

---

## Cannot Test Locally

- **PayPal flow** — capture endpoint requires a publicly reachable URL
- **Session timeout** — PHP GC settings differ from production
- **Email delivery** — `mail()` succeeds silently; no email is actually sent on localhost

---

## Local Development

```bash
# 1. Import the database (phpMyAdmin or CLI)
CREATE DATABASE karate_portal;
USE karate_portal;
SOURCE /path/to/karate_schema.sql;

# 2. Install PHP dependencies (Google OAuth library)
cd portal
php composer.phar install   # or: composer install
cd ..

# 3. Install Playwright
npm install

# 4. Create tests/credentials.js (gitignored — never committed)
# Copy the template below and fill in real values:
module.exports = {
    ADMIN_USER: 'admin',  ADMIN_PASS: '...',
    INST_USER:  '...',    INST_PASS:  '...',
    STU_USER:   '...',    STU_PASS:   '...',
    GUEST_USER: '...',    GUEST_PASS: '...',
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
npm test
# or headed:
npx playwright test --headed
```

---

## Project Structure

```
karate/
├── portal/                 # PHP application root (served by XAMPP)
│   ├── includes/           # Shared: auth, DB, config, header/footer, auto-inactive rule
│   ├── student/            # Student portal — dashboard, attendance, payments, waiver, profile
│   ├── parent/             # Parent portal — tabbed family dashboard, per-child pages
│   ├── instructor/         # Instructor pages — attendance, roster, belt tests, student profiles
│   ├── admin/              # Admin-only pages — all of the above plus payments, expenses,
│   │                       #   donations, waivers, users, audit log, compare & link
│   └── cron/               # Scheduled jobs — backup, alerts, reminders (see CRON_SETUP.txt)
├── tests/                  # Playwright test suite (299 tests, ~2.7 min)
│   ├── global-setup.js     # Saves DB snapshot + auth state before each run
│   ├── global-teardown.js  # Always restores DB snapshot after run (pass or fail)
│   ├── helpers.js          # Shared login, visit, CSRF, API helpers + AUTH constants
│   ├── credentials.js      # gitignored — create locally with test account passwords
│   └── *.spec.js           # One spec file per feature area (27 files)
├── playwright.config.js
└── karate_schema.sql       # Fresh-install DB schema with seed data
```
