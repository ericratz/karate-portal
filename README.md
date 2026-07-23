# Shotokan Karate Portal — V4.5

A full-stack membership management platform for a martial arts dojo — role-based dashboards, attendance tracking, belt test progression, payments (PayPal + manual), digital waivers, and self-service check-in. Built with PHP and MySQL behind a React 19 + TypeScript SPA: all four portals (admin, instructor, parent, student) were incrementally migrated from server-rendered pages to a single code-split bundle over a versioned JSON API, with every old page URL preserved as a redirect stub. Fully containerized with Docker (app + database + CI toolchain) and verified by a 500+ test Playwright + PHPUnit + Vitest suite and Psalm static + taint analysis running on every push via GitHub Actions.

See [`CHANGELOG.md`](CHANGELOG.md) for full version history.

---

## Highlights

- **Four tailored role-based dashboards** (admin, instructor, parent, student) driven by a single derived-role system — no redundant role tables, no client-trusted role state
- **Incremental React migration of a live app, completed across three releases** — all four portals share one React 19 + TypeScript SPA (Vite, react-router, Chart.js): a single hashed bundle served from four thin PHP shells that keep the server-side role gates, with the admin pages code-split into their own lazy chunks so families never download them. A versioned JSON API (`api/v1`) reuses the existing session auth, header-based CSRF, family-scoped ownership checks, and role gates. Every old page URL remains a server-side redirect stub, and the React ports preserve the legacy DOM contract (ids, names, inline-display visibility), so the migration landed slice by slice — parent (V4.0), then payments, student, and instructor (V4.1), then the whole admin portal (V4.2) — with the full test suite green at every step. htmx, which powered the admin pages' partial swaps, is now fully retired
- **Hardened CSP** — nonce-only `script-src`, zero inline event handlers anywhere in the codebase, enforced by a dedicated regression test
- **Defense-in-depth access control** — role checks on every protected page, plus per-record ownership scoping (a parent can only ever query their own linked children's data, verified server-side, not just hidden client-side)
- **Digital workflows end-to-end** — self-service registration with duplicate-account detection, digital injury waivers, PayPal one-time + subscription payments, PDF rank certificates, PIN-gated self check-in
- **Mobile-friendly** — layout and touch targets tuned for phone-width viewports, with a dedicated Playwright suite exercising core flows at mobile sizes
- **518 Playwright tests + 113 PHPUnit tests + 43 Vitest component tests**, plus Psalm static analysis at level 3 and taint analysis, and strict TypeScript, run on every push via a self-hosted GitHub Actions CI pipeline
- **Fully containerized** — app, database, and the entire CI toolchain (Psalm, PHPUnit, Playwright, plus opt-in nmap/Nikto/ZAP scanners) run in Docker via `docker compose`, so dev and CI share one reproducible stack — as of V4.4 the containers are also the local dev server, running the same PHP 8.4.23 as production instead of a machine-coupled XAMPP install
- **21 shipped releases in 6+ weeks**, solo — from a bare attendance tracker to a full multi-role membership platform with payments, security hardening, static analysis, containerization, and CI (see [`CHANGELOG.md`](CHANGELOG.md))
- **Iterative data-model refinement** — guardian/family relationships and user-identity fields were each reworked once real usage patterns emerged, rather than over-designed upfront
- **Security matured alongside features** — CSP hardening, an external verification pass (ZAP, sqlmap, Burp Suite, nmap, Nikto), a full test-suite audit for coverage gaps, and a documented/drilled backup-restore process.

---

## Tech Stack

| Layer | Detail |
|---|---|
| Language | PHP 8.4 (backend) · TypeScript (SPA) |
| Database | MySQL 8.0 — `karate_portal` |
| Frontend | React 19 + TypeScript + Vite + react-router + Chart.js — one SPA for all four portals over `api/v1` JSON endpoints (admin routes lazy-loaded) · Bootstrap 5 + vanilla JS on the remaining server-rendered pages (auth, print/document views, profile edit) |
| Payments | PayPal JS SDK (one-time + subscriptions), loaded on demand by the SPA pay route |
| Auth | Username/password + Google OAuth |
| Tests | Playwright 1.60 (518 tests) + PHPUnit 9.6 (113 tests) + Vitest/React Testing Library (43 tests) |
| Static analysis | Psalm 6 (level 3 + taint analysis, both CI-gating) — PHP; TypeScript strict — SPA; `@ts-check` + JSDoc via `tsconfig.json` (`checkJs`) — test suite |
| Containerization | Docker + docker-compose — `app` (php:8.4-apache), `db` (mysql:8.0), `ci` (Playwright + PHP + Composer) |
| CI | GitHub Actions — self-hosted Windows runner, containerized pipeline; `tests.yml` (Psalm standard + taint, PHPUnit, Vitest, Playwright) on every push to `main`, plus `security.yml` (nmap/Nikto/ZAP) weekly and on-demand |

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
- Bcrypt password hashing; HttpOnly + SameSite session cookies (Secure on HTTPS), regenerated on login
- Content-Security-Policy with a nonce-only `script-src` — no inline event handlers anywhere in the codebase
- Per-record ownership scoping on every parent/student-facing endpoint, independent of role checks — centralized in `includes/family.php` and covered by dedicated PHPUnit + Playwright regression tests
- The `api/v1` JSON API (React SPA backend) reuses the same session auth; the `parent/*` endpoints apply family scoping with a whitelist serializer so admin-only columns never leave the server, the `instructor/*` endpoints are gated to instructor/admin (existing belt-test editing admin-only), the `admin/*` endpoints are admin-only, and every mutation requires the CSRF token in an `X-CSRF-Token` header
- Full audit log of logins, edits, deletions, and payments, with time-based retention
- Verified with nmap, Nikto, OWASP ZAP, sqlmap, and Burp Suite — nmap/Nikto/ZAP run as `docker compose` scanner services against the containerized app, executed weekly and on-demand by a dedicated GitHub Actions workflow (`security.yml`) with the ZAP baseline as the gating scan
- Psalm static analysis (level 3) and taint analysis on every push as two gating CI steps, catching type and injection issues before merge; the baseline acts as a ratchet, so any newly-introduced error fails the build
- Dependabot watching all four lockfiles plus the Actions pins; the five advisories from its first sweep were triaged and cleared in V4.4
- Session cookies carry `HttpOnly`, `SameSite=Lax`, and `Secure`, the last derived fail-safe so it stays on behind the production TLS-terminating proxy
- Backup/restore process documented and drilled end-to-end (see [`tests/RESTORE_RUNBOOK.md`](tests/RESTORE_RUNBOOK.md))

---

## Testing

518 Playwright tests across all four roles (dashboards, React SPA flows, access-control boundaries, security regressions, mobile viewport) plus 113 PHPUnit unit/integration tests and 43 Vitest + React Testing Library component tests, run automatically on every push via GitHub Actions alongside Psalm static and taint analysis and a strict-mode TypeScript check of the SPA — all inside Docker containers (app, database, and CI toolchain), so CI exercises the same reproducible stack used for local development. The Playwright/Node test files themselves are type-checked with `@ts-check` + JSDoc annotations (`tsconfig.json` with `checkJs`).

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
docker compose up -d db app     # set APP_PORT to something else if port 80 is taken

# 4. Map the app's hostname so redirects work in a browser. Add to
#    C:\Windows\System32\drivers\etc\hosts (needs Administrator), placed AFTER
#    Docker Desktop's "# End of section" marker so Docker doesn't overwrite it:
#      127.0.0.1  karate.test
```

Site: `http://karate.test/karate/portal` (login: `admin` / `ChangeMe123!` on a fresh DB)

The hostname is not cosmetic. The container sets `SITE_URL=http://karate.test/karate/portal` and is
reachable at that name inside the Compose network via a network alias, so the `ci` container and your
browser use the identical URL. Every server-side redirect (login, logout, auth guards) emits that
absolute URL — without the hosts entry you can load pages but die on the first redirect.

Do **not** use the `app` service name as the browsable hostname: `.app` is a real gTLD on the HSTS
preload list, so browsers force `https://` for a bare `app` host and refuse plain HTTP before the
request is sent. Preloaded HSTS entries cannot be clicked through or cleared, so this is unfixable from
the app's side. `.test` is reserved by RFC 2606 and will never be preloaded.

New dev hostnames need no code change: the session cookie's `Secure` flag is driven by `APP_ENV=dev`
(set in `docker-compose.yml`, never in `.env`), not by a hostname allowlist. Production sets nothing and
is secure by omission — see `site_is_https()` in `portal/includes/config.php`.

```bash
# Run the checks — all inside the ci container, same as CI does:
docker compose run --rm ci npm run typecheck
docker compose run --rm ci sh -c "cd portal && vendor/bin/psalm"                  # standard, level 3
docker compose run --rm ci sh -c "cd portal && vendor/bin/psalm --taint-analysis" # taint
docker compose run --rm ci sh -c "cd portal && vendor/bin/phpunit"
docker compose run --rm ci sh -c "cd frontend && npm run typecheck && npm test"
docker compose run --rm ci npx playwright test      # writes tests/report/

docker compose down -v          # stop and wipe the db volume
```

### Frontend (React SPA) development

The parent portal SPA lives in `frontend/`. The app image builds it during
`docker compose build` (multi-stage Node step), so nothing extra is needed to
run it. For a hot-reload dev loop against the running app:

```bash
cd frontend
npm install
npm run dev     # Vite on :5173, proxies /karate/portal to http://karate.test
# Log in at http://karate.test/karate/portal first, then open
# http://karate.test:5173 (not localhost:5173) — the session cookie is
# host-scoped, so it only flows to the dev server if you reach both through the
# same hostname. Cookies ignore the port, so :5173 and :80 share it.
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

### Security scanners

nmap, Nikto, and OWASP ZAP run as `security`-profile services against the
containerized app — they never start on a plain `docker compose up`. Run them
locally on demand:

```bash
docker compose up -d db app
docker compose run --rm nmap
docker compose run --rm nikto
docker compose run --rm zap      # baseline scan; rules in docker/zap/zap-baseline.conf
```

In CI they run on a schedule (and on-demand) via the `security.yml` workflow —
weekly, plus a manual **Run workflow** trigger — with the ZAP baseline as the
gating scan and the nmap/Nikto reports saved as run artifacts.

---

## Project Structure

```
karate/
├── frontend/           # React 19 + TypeScript SPA (Vite) — all four portals:
│   └── src/            #   typed api/v1 client, routes, components,
│                       #   Vitest tests (admin routes code-split)
├── portal/
│   ├── includes/       # Shared: auth, DB, config, family scoping, API plumbing
│   ├── api/v1/         # JSON API for the SPA (me + parent/* + instructor/*
│   │                   #   + admin/*)
│   ├── parent/         # app.php SPA shell (serves dist/) + redirect stubs;
│   │                   #   profile_edit stays server-rendered (password change)
│   ├── student/        # app.php SPA shell + redirect stubs; profile_edit
│   ├── instructor/     # app.php SPA shell + redirect stubs
│   ├── admin/          # app.php SPA shell + redirect stubs; still PHP:
│   │                   #   member cards, rank certificates, waiver view,
│   │                   #   check-in PIN, database backup download
│   └── cron/           # Scheduled jobs
├── tests/              # Playwright test suite (518 tests, 40 spec files)
├── docker/             # app.Dockerfile (builds the SPA in-image), ci.Dockerfile
├── docker-compose.yml  # app + db + ci + opt-in security scanners
├── karate_schema.sql   # Fresh-install schema with seed data
├── CHANGELOG.md        # Full version history
└── README.md
```
