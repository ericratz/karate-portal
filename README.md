# Shotokan Karate Portal — V2.2

A private membership portal for Noji Ratzlaff's Shotokan Karate dojo. Students can track
their attendance, belt tests, and payments; instructors can take attendance and manage belt
tests; and the admin can run the whole operation from one place.

---

## What's New in V2.2

- **Home address on profiles** — `street_address` and `city_state_zip` added to the student record; editable on the profile edit form and displayed in the Profile Info card across all views (migration: `migrations/add_student_address.sql`)
- **Phone formatting** — phone numbers now display with hyphens (801-368-1865) in all profile and waiver views
- **Waiver redesign** — student and parent waiver forms now match the physical PDF layout (paper-style underline fields, legal text, pre-filled and read-only when already signed); admin can digitize physical waivers via the waiver view page with full form entry
- **Terminology cleanup** — "Liability Waiver" renamed to "Waiver" and "Payment Waiver" renamed to "Exempt" across all views to avoid confusion
- **Parent ENUM fix** — `student_type = 'parent'` was missing from the DB ENUM, causing parent records to save as blank and be invisible on the roster (migration: `migrations/fix_student_type_enum_add_parent.sql`)
- **Sticky navigation bar** — purple nav bar stays visible when scrolling on all pages
- **Roster: name format & sort** — names display as "First Last" by default with a First Name / Last Name sort toggle on both admin and instructor rosters; applied consistently across all pages site-wide
- **Roster: column rename** — "Injury Waiver" column renamed to "Waiver"
- **Attendance: date navigation** — changing the date picker navigates to that date's attendance (page reloads with correct data); fixed a critical bug where saving attendance for a past date could move and overwrite a more recent session
- **Attendance: name sort** — First Name / Last Name / Last Attended sort toggle added to the attendance page
- **Belt tests: auto-rank** — a passing score (≥ 80%) automatically records the rank in the student's Rank History; no separate "Belt Awarded" step needed
- **Belt tests: terminology** — "Belt Awarded" renamed to "Test Passed" across all views; pending tests now show "—" instead of ✗
- **Profile save redirect** — after a student saves their profile, they are sent directly to their profile view
- **Record Attendance button** — on the student profile page (instructor/admin view), a "+ Record Attendance" button links to the attendance page with that student pre-checked
- **Session list accordion** — on the Classes page, expanding one session's attendance now collapses any previously open session
- **PayPal default** — payment method field defaults to PayPal on the payments page
- **Waiver dashboard card** — hidden for students and parents who have already signed; profile info card shows a View button and signed date instead

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
- **Exempt** — grant tuition exemptions
- **Expenses** — log and manage dojo expenses
- **Waivers** — view signed waivers; digitize physical paper waivers with full field entry
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
