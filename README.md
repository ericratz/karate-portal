# Shotokan Karate Portal — V3.7

A full-stack membership management platform for a martial arts dojo — role-based dashboards, attendance tracking, belt test progression, payments (PayPal + manual), digital waivers, and self-service check-in. Built with PHP, MySQL, and htmx; fully containerized with Docker (app + database + CI toolchain) and verified by a 500+ test Playwright + PHPUnit suite and Psalm static analysis running on every push via GitHub Actions.

See [`CHANGELOG.md`](CHANGELOG.md) for full version history.

---

## Highlights

- **Four tailored role-based dashboards** (admin, instructor, parent, student) driven by a single derived-role system — no redundant role tables, no client-trusted role state
- **Real-time UI without a JS framework** — htmx partial swaps and out-of-band updates keep every card in sync across edits, with no full-page reloads
- **Hardened CSP** — nonce-only `script-src`, zero inline event handlers anywhere in the codebase, enforced by a dedicated regression test
- **Defense-in-depth access control** — role checks on every protected page, plus per-record ownership scoping (a parent can only ever query their own linked children's data, verified server-side, not just hidden client-side)
- **Digital workflows end-to-end** — self-service registration with duplicate-account detection, digital injury waivers, PayPal one-time + subscription payments, PDF rank certificates, PIN-gated self check-in
- **Mobile-friendly** — layout and touch targets tuned for phone-width viewports, with a dedicated Playwright suite exercising core flows at mobile sizes
- **514 Playwright tests + 94 PHPUnit tests**, plus Psalm static + taint analysis at level 3, run on every push via a self-hosted GitHub Actions CI pipeline
- **Fully containerized** — app, database, and the entire CI toolchain (Psalm, PHPUnit, Playwright, plus opt-in nmap/Nikto/ZAP scanners) run in Docker via `docker compose`, so dev and CI share one reproducible stack instead of a machine-coupled XAMPP install
- **17 shipped releases in 5+ weeks**, solo — from a bare attendance tracker to a full multi-role membership platform with payments, security hardening, static analysis, containerization, and CI (see [`CHANGELOG.md`](CHANGELOG.md))
- **Iterative data-model refinement** — guardian/family relationships and user-identity fields were each reworked once real usage patterns emerged, rather than over-designed upfront
- **Security matured alongside features** — CSP hardening, an external verification pass (ZAP, sqlmap, Burp Suite, nmap, Nikto), a full test-suite audit for coverage gaps, and a documented/drilled backup-restore process.

---

## Tech Stack

| Layer | Detail |
|---|---|
| Language | PHP 8.4 |
| Database | MySQL 8.0 — `karate_portal` |
| Frontend | Bootstrap 5 (CDN), vanilla JS, htmx |
| Payments | PayPal JS SDK (one-time + subscriptions) |
| Auth | Username/password + Google OAuth |
| Tests | Playwright 1.60 (514 tests) + PHPUnit 9.6 (94 tests) |
| Static analysis | Psalm (level 3, + taint analysis) |
| Containerization | Docker + docker-compose — `app` (php:8.4-apache), `db` (mysql:8.0), `ci` (Playwright + PHP + Composer) |
| CI | GitHub Actions — self-hosted Windows runner, containerized pipeline, runs on every push to `main` |

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
- Verified with nmap, Nikto, OWASP ZAP, sqlmap, and Burp Suite — nmap/Nikto/ZAP now run as opt-in `docker compose` scanner services against the containerized app
- Psalm static + taint analysis (level 3) on every push, catching type and injection issues before merge
- Backup/restore process documented and drilled end-to-end (see [`tests/RESTORE_RUNBOOK.md`](tests/RESTORE_RUNBOOK.md))

---

## Testing

514 Playwright tests across all four roles (dashboards, HTMX flows, access-control boundaries, security regressions, mobile viewport) plus 94 PHPUnit unit/integration tests, run automatically on every push via GitHub Actions alongside Psalm static and taint analysis — all inside Docker containers (app, database, and CI toolchain), so CI exercises the same reproducible stack used for local development.

Payment/OAuth callbacks, live email delivery, and scheduled cron jobs are validated through manual verification against the staging/production environment, since they depend on publicly reachable callback URLs and real third-party services.

---

## Local Development

The whole stack runs in Docker — app (Apache + PHP 8.4), database (MySQL 8.0),
and a CI-tools container that runs every check. No native PHP/MySQL/Node needed.

```bash
# 1. Create .env at the project root (gitignored). DB_HOST must be `db` — the
#    Compose service name. Compose also uses DB_NAME/DB_PASS to init MySQL.
DB_HOST=db
DB_NAME=karate_portal
DB_USER=root
DB_PASS=yourpassword
SITE_URL=http://localhost/karate/portal
# GOOGLE_CLIENT_ID=...  GOOGLE_CLIENT_SECRET=...  (optional)

# 2. Create tests/credentials.js (gitignored) — local test-account logins
module.exports = {
    ADMIN_USER:  'admin', ADMIN_PASS:  '...',
    INST_USER:   '...',   INST_PASS:   '...',
    STU_USER:    '...',   STU_PASS:    '...',
    PARENT_USER: '...',   PARENT_PASS: '...',
};

# 3. Build and start. The schema auto-imports into a fresh db volume on first
#    start; composer install + npm install happen at image-build time.
docker compose build
docker compose up -d db app     # set APP_PORT=8080 first if host port 80 is taken
```

Site: `http://localhost/karate/portal` (login: `admin` / `ChangeMe123!` on a fresh DB)

```bash
# Run the checks — all inside the ci container, same as CI does:
docker compose run --rm ci npm run typecheck
docker compose run --rm ci sh -c "cd portal && vendor/bin/psalm --taint-analysis"
docker compose run --rm ci sh -c "cd portal && vendor/bin/phpunit"
docker compose run --rm ci npx playwright test      # writes tests/report/

docker compose down -v          # stop and wipe the db volume
```

> **Note:** `karate_schema.sql` seeds only the default `admin` account. The
> Playwright suite logs in as additional test accounts (`Noji`, `jsmith`, an
> instructor, a parent) that must exist in the DB. To run the full suite against
> a fresh containerized `db`, dump those accounts from your existing DB and mount
> the dump so it auto-imports right after the schema — add a line to the `db`
> service in `docker-compose.yml`:
>
> ```yaml
>   - ./test-seed.sql:/docker-entrypoint-initdb.d/02-test-seed.sql:ro
> ```

### Security scanners (opt-in)

nmap, Nikto, and OWASP ZAP run as `security`-profile services against the
containerized app — they never start on a plain `docker compose up`:

```bash
docker compose up -d db app
docker compose run --rm nmap
docker compose run --rm nikto
docker compose run --rm zap      # baseline scan; rules in docker/zap/zap-baseline.conf
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
├── tests/              # Playwright test suite (514 tests, 39 spec files)
├── docker/             # app.Dockerfile, ci.Dockerfile, Apache/ZAP configs
├── docker-compose.yml  # app + db + ci + opt-in security scanners
├── migrations/         # SQL migration scripts
├── karate_schema.sql   # Fresh-install schema with seed data
├── CHANGELOG.md        # Full version history
└── README.md
```
