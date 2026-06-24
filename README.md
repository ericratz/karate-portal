# Shotokan Karate Portal — V3.0

A private membership portal for Noji Ratzlaff's Shotokan Karate dojo. Students track
attendance, belt tests, and payments. Instructors manage classes and roster. The admin
runs the full operation from one place.

---

## What's New in V3.0

- **Password reset** — self-service forgot-password flow (`forgot_password.php` → `reset_password.php`). Tokens expire in 1 hour, single-use, same confirm screen for unknown usernames (no enumeration)
- **Email log** — admin can review all outgoing emails at `admin/email_log.php`, filterable by status, type, and date range. All `mail()` calls go through `log_email()` which records to `email_log`
- **Uniform size and belt size** — new fields on the student record (`000`–`8` for uniforms, `2`–`8` for belts). Editable in admin student edit, instructor student profile, and the student's own profile edit page
- **Attendance bar graph** — student dashboard shows a Chart.js bar chart of attendance for the last 12 months. Bars turn purple for any month in which a rank advancement was recorded
- **Roster quick-view** — registration paid status shows a ✓ or ✗ directly in the roster table (no need to open the full student record)
- **Roster and Attendance nav buttons** — quick-access links in the header bar for admin and instructor roles
- **Link underlines removed** — global CSS in `portal/includes/header.php`; hover state also unstyled
- **Security headers** — `.htaccess` sets `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, and a full `Content-Security-Policy` with `report-uri` pointing to `api/csp_report.php`
- **CSP reporting** — `api/csp_report.php` receives browser Content Security Policy violation reports and logs them via `log_event()`
- **Role system overhaul** — `users.role ENUM` removed. Role is now derived at login: `is_admin=1` → `admin`; otherwise `students.student_type` (fallback `guest` if no linked record). Stored as `$_SESSION['role']`
- **Playwright test suite reorganized** — tests moved from flat feature files into role-based directories (`tests/roles/admin/`, `tests/roles/instructor/`, `tests/roles/student/`, `tests/roles/parent/`, `tests/auth/`, `tests/shared/`). 466 tests across 36 spec files. Output goes to `tests/report/` and `tests/results/`

## What's New in V2.5

- **API endpoints moved** — PayPal and feedback endpoints live in `portal/api/` (cleaner root directory)
- **Payment receipts** — students receive an email receipt after any PayPal or manually-recorded payment
- **Google registration** — now runs the same multi-step matching flow as standard registration (find existing record → confirm → create account)
- **Rate limiting on registration** — max 5 attempts per hour per IP
- **Belt test → rank sync** — awarding a belt test (score ≥ 80%) automatically updates the student's rank history from both the admin and instructor edit forms
- **Donation payment type** — admins can now record donations manually alongside other payment types
- **Venmo removed** — no longer a valid payment method for new entries (legacy records still display correctly)
- **Hardcoded paths replaced** — all `/karate/portal/` paths now use `SITE_URL` from `.env`
- **Student dashboard** — added Homework and Tests & Grading buttons linking to the karate website

## What's New in V2.4

- **Multi-step registration** — form → match existing roster record → confirm. Nothing written to DB until final confirm. Users get full portal access immediately.
- **Automatic record matching** — registration searches for existing roster entries by name, DOB, or email. Match cards show name, belt rank, masked email, and birth day/month (year hidden).
- **Admin registration alerts** — three dashboard cards replace the old Link Requests card: Needs Manual Linking, Claimed Existing Records, New Registrations.
- **Resolve link page** — `admin/resolve_link.php` lets admin manually link a user to a roster entry when no match was found during registration.
- **Guest role** — assigned at registration; replaced with the correct role once the record is linked.
- **`last_login` on registration** — populated immediately so "Last login: Never" never appears for new accounts.
- **`parent_students` removed** — superseded by `student_guardians` in V2.3.
- **Cron job fix** — removed spurious `-f php` that caused "Could not open input file: php" errors.

## What's New in V2.3

- **Student guardians** — parent roster entries can be linked to child roster entries via `student_guardians`. No user account required for either party.
- **Data ownership separation** — user account fields (`first_name`, `last_name`, `date_of_birth`, `email`) live on the `users` table. Roster entries are admin-managed separately.
- **Date of birth on user accounts** — users can enter and update their DOB from their profile.
- **Date format** — all dates display with a leading zero (03 Jun 2026).
- **Dojo email updated** — outgoing mail sends from `noreply@noji.com`.

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
| Tests | Playwright 1.60 (`npm test`) |

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
├── tests/              # Playwright test suite (466 tests, 36 spec files)
├── migrations/         # SQL migration scripts
├── karate_schema.sql   # Fresh-install schema with seed data
└── README.md
```
