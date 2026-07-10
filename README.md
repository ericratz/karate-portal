# Shotokan Karate Portal — V3.5

A full-stack membership management platform for a martial arts dojo — role-based dashboards, attendance tracking, belt test progression, payments (PayPal + manual), digital waivers, and self-service check-in. Built with PHP, MySQL, and htmx; verified by a 500+ test Playwright + PHPUnit suite running on every push via GitHub Actions.

See [`CHANGELOG.md`](CHANGELOG.md) for full version history.

---

## Highlights

- **Four tailored role-based dashboards** (admin, instructor, parent, student) driven by a single derived-role system — no redundant role tables, no client-trusted role state
- **Real-time UI without a JS framework** — htmx partial swaps and out-of-band updates keep every card in sync across edits, with no full-page reloads
- **Hardened CSP** — nonce-only `script-src`, zero inline event handlers anywhere in the codebase, enforced by a dedicated regression test
- **Defense-in-depth access control** — role checks on every protected page, plus per-record ownership scoping (a parent can only ever query their own linked children's data, verified server-side, not just hidden client-side)
- **Digital workflows end-to-end** — self-service registration with duplicate-account detection, digital injury waivers, PayPal one-time + subscription payments, PDF rank certificates, PIN-gated self check-in
- **500+ Playwright tests + 90+ PHPUnit tests**, run on every push via a self-hosted GitHub Actions CI pipeline
- **15 shipped releases in 5 weeks**, solo — from a bare attendance tracker to a full multi-role membership platform with payments, security hardening, and CI (see [`CHANGELOG.md`](CHANGELOG.md))
- **Iterative data-model refinement** — guardian/family relationships and user-identity fields were each reworked once real usage patterns emerged, rather than over-designed upfront
- **Security matured alongside features** — CSP hardening, an external verification pass (ZAP, sqlmap, Burp Suite, nmap, Nikto), and a full test-suite audit for coverage gaps came after the core product was stable, not as an afterthought

---

## Tech Stack

| Layer | Detail |
|---|---|
| Language | PHP 8.4 |
| Database | MySQL 8.0 — `karate_portal` |
| Frontend | Bootstrap 5 (CDN), vanilla JS, htmx |
| Payments | PayPal JS SDK (one-time + subscriptions) |
| Auth | Username/password + Google OAuth |
| Tests | Playwright 1.60 (503 tests) + PHPUnit 9.6 (91 tests) |
| CI | GitHub Actions — self-hosted Windows runner, runs on every push to `main` |

---

## Architecture

Role is derived at login from account state — not stored as an editable field — and cached in `$_SESSION['role']`:

| Role | Derived from | Access |
|---|---|---|
| `admin` | `users.is_admin = 1` | Everything |
| `instructor` | `students.student_type = 'instructor'` | Roster, attendance, belt tests |
| `parent` | `students.student_type = 'parent'` | Family dashboard (tabbed per child) |
| `student` | `students.student_type = 'student'` | Student portal |
| `guest` | No linked record yet, or `student_type = 'guest'` | Student portal, pending registration |

**Registration flow:** account details → duplicate-match search against the existing roster (name, DOB, email) → claim, create, or flag as new → account created and logged in as `guest` → admin resolves any match → registration payment promotes to `student` → digital waiver signed.

**Payments:** `monthly_tuition`, `registration`, `belt_test`, `slc_training`, `seminar`, `other`, `donation` — via PayPal, cash, check, or mail.

---

## Security

- CSRF tokens on every mutating form, with per-session token validation
- Login and check-in PIN rate limiting (5 failures / 15 min)
- Bcrypt password hashing; HttpOnly + SameSite session cookies, regenerated on login
- Content-Security-Policy with a nonce-only `script-src` — no inline event handlers anywhere in the codebase
- Per-record ownership scoping on every parent/student-facing endpoint, independent of role checks
- Full audit log of logins, edits, deletions, and payments, with time-based retention
- Verified with nmap, Nikto, OWASP ZAP, sqlmap, and Burp Suite

---

## Testing

503 Playwright tests across all four roles (dashboards, HTMX flows, access-control boundaries, security regressions) plus 91 PHPUnit unit/integration tests, run automatically on every push via GitHub Actions.

Payment/OAuth callbacks, live email delivery, and scheduled cron jobs are validated through manual verification against the staging/production environment, since they depend on publicly reachable callback URLs and real third-party services.

---

## Local Development

```bash
# 1. Import the database
CREATE DATABASE karate_portal;
SOURCE /path/to/karate_schema.sql;

# 2. Install PHP dependencies (first-time setup only — CI keeps vendor/ persisted
#    on the self-hosted runner instead of reinstalling on every run)
cd portal && php composer.phar install && cd ..

# 3. Install Playwright
npm install

# 4. Create tests/credentials.js (gitignored)
module.exports = {
    ADMIN_USER:  'admin', ADMIN_PASS:  '...',
    INST_USER:   '...',   INST_PASS:   '...',
    STU_USER:    '...',   STU_PASS:    '...',
    PARENT_USER: '...',   PARENT_PASS: '...',
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
├── CHANGELOG.md        # Full version history
└── README.md
```
