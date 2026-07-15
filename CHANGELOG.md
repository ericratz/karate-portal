# Changelog

Full version history for the Shotokan Karate Portal. See `README.md` for the current architecture and setup.

---

## V4.0

- **Parent portal migrated to React 19 + TypeScript** — the tabbed family dashboard, payment history, attendance, belt tests, and the injury waiver (view + sign) are now a single-page app (`frontend/`, built with Vite + react-router + Chart.js, Bootstrap bundled via npm instead of the CDN). A thin PHP shell (`parent/app.php`) keeps the server-side role gate and reads the Vite manifest for the hashed bundle; the old page URLs live on as server-side redirect stubs that preserve their role gates and ownership checks, so existing links and access-control semantics are unchanged. `pay.php` (PayPal) and `profile_edit.php` (password change) intentionally stay server-rendered pending their own migrations
- **Versioned JSON API** — new `portal/api/v1/` endpoints (`me.php` session bootstrap + seven `parent/*` endpoints) mirror the old pages' queries exactly, on the same session auth. Mutations authenticate via an `X-CSRF-Token` header (`includes/api.php`); every response passes through a whitelist serializer (`family_student_profile()`) so admin-only columns (`notes`, `active_override`, …) can never leak, backed by a dedicated unit test
- **Family ownership scoping centralized** — the parent allowed-student-ids logic, previously copy-pasted in five files (four parent pages + `api/paypal_create.php`), is now `includes/family.php`, covered by a new `FamilyScopeTest` integration suite that seeds a sentinel family and asserts the access boundary from both sides
- **CSP survives the SPA unchanged** — the React bundle loads as an external module from `'self'`, so the nonce-only `script-src` needed no relaxing; the shell page serves the same header as every PHP page
- **New Vitest + React Testing Library suite** (31 tests) — API client envelope/CSRF contract, PHP-parity formatters (`hn`, `fmt_date`, `fmt_phone`), belt/homework age boundaries, and ProfileCard edit/save/validation flows — run in CI alongside a strict-mode `tsc` check of the SPA
- **Docker builds the frontend** — `app.Dockerfile` gained a multi-stage Node step (`npm ci && npm run build`) so the image never depends on a host-side build; `portal/parent/dist/` is git- and docker-ignored. The ci image carries the frontend toolchain, and the GitHub Actions workflow gained a frontend typecheck + Vitest step
- **Parent Playwright specs adapted to the SPA** — same coverage (role denials, ownership redirects, inline profile edit, IDOR regressions), now exercising the React UI and the JSON API; the other 30+ spec files pass untouched, which is the proof the migration stayed contained
- **Security scanners scheduled in CI** — new `security.yml` workflow runs nmap, Nikto, and the OWASP ZAP baseline against the containerized app weekly and on-demand; ZAP (rules in `docker/zap/zap-baseline.conf`) gates the run, nmap/Nikto reports upload as artifacts. All three re-run clean against the post-React app (including the new `api/v1` surface)
- **Test coverage** — 514 Playwright tests (39 spec files), 113 PHPUnit tests (up from 94), 31 Vitest tests, all passing

---

## V3.7

- **Full Docker migration** — the entire stack now runs in containers orchestrated by `docker-compose.yml`, one container per responsibility: `app` (`php:8.4-apache`), `db` (`mysql:8.0`, schema auto-imported on first start), and `ci` (the official Playwright image plus PHP 8.4 CLI, Composer, and the MySQL client — one image that runs Psalm, PHPUnit, and Playwright). A real `composer install` at image-build time finally replaces the old `portal/vendor` filesystem junction into the live htdocs install, so dependencies are reproducible instead of machine-coupled
- **CI/CD rebuilt on Docker** — the self-hosted GitHub Actions workflow was rewritten from native PowerShell steps (hardcoded `C:\Users\...` PHP/MySQL paths) to `docker compose build` / `up --wait` / `run --rm ci …` / `down -v`. Readiness is gated on a real container healthcheck (curls `login.php`, proving app + db are wired up) instead of a fixed sleep; the app publishes on a random host port so it never clashes with the box's native XAMPP on port 80
- **Config decoupled from the machine via environment variables** — `db.php` and `config.php` now prefer real environment variables over the `.env` file, so a single `.env` serves both native XAMPP (`DB_HOST=localhost`, `SITE_URL=http://localhost/...`) and the containers (which override `DB_HOST=db`, `SITE_URL=http://app/...` on the Compose network). This fixed a real cross-environment bug: server-side redirects (logout, post-login, auth guards) build absolute URLs from `SITE_URL`, so a hardcoded localhost would bounce a containerized browser to the wrong host
- **Security scanners containerized** — nmap, Nikto, and OWASP ZAP are defined as opt-in `docker compose run` services under a `security` profile (never started by a plain `up`), each targeting the app by its Compose DNS name; ZAP baseline runs against a committed rules file. No more host-level tooling installs to probe the dev app
- **Rate-limit exemption generalized** — the "never rate-limit localhost" carve-out (login, registration, check-in PIN) is now a single `rate_limit_exempt()` helper that also honors a `RATELIMIT_DISABLED` env flag, set only on the dev/CI container (whose client IP isn't localhost). Production, running natively, leaves rate limiting fully on
- **Apache hardening** — the container blocks web access to dotfiles, so the mounted `.env` (which lives under the document root) can never be served as plaintext; verified by the existing `.env`-not-accessible security tests
- **Schema completeness fix** — `karate_schema.sql` was missing the `email_log` table that `config.php`'s mail logging and the log-retention job both write to; a fresh database is now complete, caught by importing the schema into a clean container
- **PHP 8.4 forward-compat fix** — guarded a `strtotime(null)` deprecation in `admin/waiver_view.php` that surfaced once the app ran on 8.4 (matching the live server) instead of the dev box's 8.2
- **Psalm tightened to level 3** — from level 4, with the baseline updated for the newly-in-scope issues; still run as standard + taint-analysis passes
- **Type-checking on the test suite** — the Playwright/Node test files carry `// @ts-check` with JSDoc type annotations, checked via a `tsconfig.json` (`checkJs`) covering `tests/**/*.js` and `playwright.config.js`
- **Test suite made environment-agnostic** — the base URL and database host are now env-driven (default to localhost, overridden to the Compose service names inside `ci`); worker count and retries are env-tunable so the containerized run stays stable under shared host resources
- **Test coverage** — 514 Playwright tests (39 spec files), 94 PHPUnit tests, all passing — now against the containerized app + database

---

## V3.6

- **Static analysis with Psalm** — codebase brought up to Psalm level 4, with a baseline file for pre-existing issues below that bar; integrated into CI as two dedicated steps (standard + taint analysis) on every push
- **Backup/restore drill documented** — `tests/RESTORE_RUNBOOK.md` walks through recovering the live database from a backup, exercised end-to-end to verify the process actually works, not just that a backup file exists
- **PayPal webhook idempotency fix** — new `webhook_events` table (unique key on `event_id`) lets `api/paypal_webhook.php` drop retried deliveries before touching any payment row; PayPal resends webhooks it didn't get a timely 200 for, so a slow request previously risked applying the same payment twice. Race-safe (two simultaneous deliveries both attempt the insert, only one wins). Covered by a new `WebhookIdempotencyTest`
- **`includes/paypal.php` rewritten around a single request helper** — every PayPal call (token, order, capture, subscription, cancel, webhook verify) now goes through one `paypal_request()` that sets connect/response timeouts, detects transport failures, and validates the response is JSON before returning. Fixes real bugs the old per-call `curl_exec()` copies had: `paypal_capture_order()` silently returned `[]` on a failed capture instead of surfacing the error; `paypal_cancel_subscription()` never checked PayPal's response status, so a rejected cancel could still be recorded locally as cancelled while PayPal kept charging — it now throws unless PayPal returns 204
- **Mobile-friendly pass** — layout and touch-target fixes across most pages so the portal is usable on phone-width viewports; new `tests/shared/mobile.spec.js` suite exercises the core flows at mobile viewport sizes
- **Class Notes and Student Notes combined** — `admin/general_notes.php` removed; its functionality merged into `admin/student_notes.php` as a single notes page instead of two overlapping ones
- **Browser console cleanup** — worked through every warning/error surfaced in Chrome DevTools across the app and resolved them
- **Widespread page touch-ups** — most pages received changes this release, largely as a side effect of the mobile-friendly pass, Psalm level-4 compliance, and test fixes
- **Test coverage** — 515 Playwright tests (39 spec files), 94 PHPUnit tests, all passing

---

## V3.5

- **Parent-card real-time bug fixed** — changing a student's account type to Parent on `admin/student_edit.php` now updates the Guardian/Parent card to "Linked Family" immediately via an htmx out-of-band swap (`hx-swap-oob` on `#guardian-card`), instead of requiring an exit-and-re-enter workaround
- **`member_card.php` access-control fix** — student/parent roles could view any other family's member card (name, rank, registration date) by changing `student_id` in the URL; it now validates the requested ID against the logged-in user's own record + linked children, matching the pattern already used on every other parent-facing page. Instructor/admin retain full roster access
- **Security verification pass** — nmap, Nikto, OWASP ZAP, sqlmap, and Burp Suite run against the app to verify the fixes above and probe for further issues
- **CSP `style-src` audited** — every interpolated `style=""` attribute in the codebase reviewed for injection risk; all take fixed/internal values, none reflect user input
- **Log retention** — new `apply_log_retention()` purges `error_log` rows older than 1 month and `email_log`/`activity_log` rows older than 6 months, run opportunistically on admin dashboard load (same pattern as the existing auto-inactivation check)
- **Test DB snapshot/restore speed-up** — `error_log`, `email_log`, and `activity_log` are now excluded from the Playwright test snapshot/restore cycle via `mysqldump --ignore-table`, since log data isn't part of test fixture state
- **PHP upgraded to 8.4** on the live server
- **CI/CD faster** — removed the redundant Composer install step from the GitHub Actions workflow, cutting the pipeline from ~5.0 minutes to ~3.3 minutes
- **Test suite audit and expansion** — swept the full Playwright suite for tests that could pass without ever exercising their assertion; converted 30+ into real, always-executing checks backed by verified fixture data or self-seeded fixtures. Added regression tests for the CSP inline-handler hardening, the `payments.php` output-escaping fix, the belt-test date-edit duplicate-rank fix, admin waiver editability, the Linked Family card, the Center Stage YTD stat, roster search by email/phone, and certificate.php's rendered content. New PHPUnit coverage for `belt_helpers.php`, `registration.php`, `paypal.php`, and `log_retention.php`
- **Test coverage** — 503 Playwright tests (39 spec files), 91 PHPUnit tests, all passing

---

## V3.4

- **CSP hardened — nonce-only `script-src`** — every inline `onclick`/`onchange`/`onsubmit`/`oninput` handler across the portal converted to external JS via delegated event listeners (`data-fn`/`data-arg` dispatcher, shared `confirm-submit-form` pattern for delete confirmations); every inline `<script>`/`<style>` tag carries a per-request nonce (`csp_nonce()` in `includes/auth.php`). CSP header moved from a static `.htaccess` directive to a dynamic PHP `header()` call so it can embed that nonce
- **Output-escaping fix** — `admin/payments.php`'s "+" prefill button now builds its student-name attribute with `htmlspecialchars()`-escaped `data-*` attributes read via a delegated listener, replacing an `addslashes()`-based inline `onclick` that didn't escape HTML double-quotes
- **Missing PRG (Post/Redirect/Get) fixes** — reloading the page after a mutating POST no longer re-submits it, across `admin/waivers.php`, `admin/email_students.php`, and several other admin pages
- **htmx swap-scope fixes** — filter bar + results card wrapped in a single container per page (waivers, donations, expenses, general notes, student notes, belt tests, payments, attendance sessions) so htmx partial swaps no longer leave filter controls or selection state stale
- **Live count updates on delete** — delete handlers on the above pages fall through to a full re-render for htmx requests, so result counts update immediately without a page reload
- **Year-dropdown filters** — replaced This Month/This Year/Filter buttons with a live-filtering Year dropdown (always includes the current year) plus a Clear button, across the same set of pages
- **Payments** — removed the dead "Month Covered" add-form field and results column; Amount now auto-fills based on selected Payment Type while the field is blank or holds a previous auto-set value
- **Attendance auto-cleanup** — saving attendance with zero students present deletes the empty `class_sessions` row instead of leaving a ghost session
- **Tuition month picker** — `student/pay.php` and `parent/pay.php` now offer the previous month in addition to current/next 3
- **`admin/checkin_pin.php`** — masked PIN display gets a View/Hide toggle; failed-attempt history now links to the activity log instead of a separate table
- **`admin/student_edit.php` cleanup** — removed the dead "Test Passed" checkbox, removed inline belt-test row editing in favor of a direct link to `belt_test_edit.php`, merged the standalone Active Status and Waiver cards into Profile Info
- **`instructor/student_profile.php` / `belt_test_edit.php`** — belt test history is now view-only; Delete moved below the edit form
- **`parent/index.php`** — added editable Uniform Size / Belt Size fields to the profile card
- **`instructor/add_note.php` removed** — adding a student note is now an inline "Add Note" card directly on `instructor/student_profile.php`
- **Instructor own-profile edit bug fix** — split a combined `<script>` block so a tooltip-init failure can't take down the Edit/Cancel/Save handlers
- **Test coverage** — 488 Playwright tests, 52 PHPUnit tests, all passing

---

## V3.3

- **Member card** — instructor signature block, darkened belt border colours per rank, larger rank pill, credit-card print scaling, text selection disabled
- **Rank certificate** — new `admin/certificate.php` generates a printable certificate from a scanned template with student name, rank, date, certificate number, and instructor signature; PDF download via html2canvas + jsPDF
- **Self check-in** — new `checkin.php` lets students type their name and tap to mark present; PIN-gated per calendar day, rate-limited using the same `login_attempts` table as login; QR check-in button for instructors, PIN management for admin
- **Belt test edit rewrite** — type-to-filter student selector, dynamic test-PDF link, fee-paid auto-check, collapsible history section
- **Belt test results** — `belt_tests_all.php` and `student_profile.php` show a green "Passed" badge, red ✗, or "—" based on the `result` field
- **Sessions Attended** — "Recent Attendance" card on `instructor/student_profile.php` now only shows sessions the student attended
- **Type-to-filter student selectors** — replaced dropdowns across `payments.php`, `waivers.php`, and `belt_tests_all.php`
- **Roster and student notes** — category cards hide when empty; count badges update live as filters are applied
- **Waiver prefill** — street address and city/state/ZIP now carry over from the student record

---

## V3.2

- **Admin waiver always editable** — admin can edit any submitted waiver directly at any time; no more read-only lock after save
- **Linked Family card** — instructor student profile shows a "Linked Family" card after Belt Test History, listing parent/child links as clickable profile links
- **Center Stage YTD tracking** — admin dashboard shows a "Center Stage (year)" stat card with total rent paid year-to-date
- **Rent reminders improved** — cron fires on the 1st of the month and every Saturday until rent is recorded, with separate email wording for first vs. follow-up alerts
- **Attendance count in save message** — saving attendance confirms "X present" alongside the date
- **Belt test date edit bug fixed** — editing a belt test date no longer creates a duplicate rank history entry or a ghost student in the roster. Root cause: `student_ranks` was missing a `UNIQUE KEY (student_id, rank_id)`. Fixed with DELETE + INSERT and a schema migration
- **Test coverage** — 475 Playwright tests + 44 PHPUnit tests, all passing

---

## V3.1.1

- Added scroll bars to all data cards
- Two-column layout bug fix

---

## V3.1

- **HTMX partial card swaps** — all edit cards across `admin/student_edit.php` save in-place without a page reload
- **Inline profile editing** — students, instructors, and parents can edit their own profile directly on the dashboard card
- **Roster search by email and phone** — the admin roster search bar now matches email addresses and phone numbers in addition to name
- **Next belt requirements** — student and parent dashboards show the next rank, minimum time-in-rank, and test score threshold needed to advance
- **Belt tests delete confirmation** — switched to an `onsubmit` confirm, eliminating double-confirm dialogs
- **GitHub Actions CI** — a self-hosted Windows runner executes the full PHPUnit and Playwright suites on every push to `main`
- **Test coverage** — 483 Playwright tests (37 spec files) + 34 PHPUnit tests

---

## V3.0

- **Password reset** — self-service forgot-password flow, tokens expire in 1 hour, single-use, no username enumeration
- **Email log** — admin can review all outgoing emails, filterable by status, type, and date range
- **Uniform size and belt size** — new fields on the student record
- **Attendance bar graph** — student dashboard shows a Chart.js bar chart of attendance for the last 12 months
- **Roster quick-view** — registration paid status shows a ✓ or ✗ directly in the roster table
- **Security headers** — CSP, X-Frame-Options, X-Content-Type-Options added
- **Role system overhaul** — `users.role` derived at login instead of stored directly
- **Playwright test suite reorganized** — moved from flat feature files into role-based directories

---

## V2.5

- **API endpoints moved** — PayPal and feedback endpoints live in `portal/api/`
- **Payment receipts** — students receive an email receipt after any PayPal or manually-recorded payment
- **Google registration** — now runs the same multi-step matching flow as standard registration
- **Rate limiting on registration** — max 5 attempts per hour per IP
- **Belt test → rank sync** — awarding a passing belt test automatically updates the student's rank history
- **Donation payment type** — admins can record donations manually alongside other payment types

---

## V2.4

- **Multi-step registration flow** — account details → match an existing roster record → confirm; nothing written until the final step
- **Automatic record matching** — searches existing roster entries by name, date of birth, or email
- **Admin alert cards** — dashboard replaced a single Link Requests card with three: Needs Manual Linking, Claimed Existing Records, New Registrations
- **Resolve link page** — lets the admin manually link a user whose record wasn't auto-matched
- **Guest role** — new accounts get full student portal access immediately, before being linked to a roster entry

---

## V2.3

- **Student guardians** — parent and child roster entries can be linked directly without either party needing a user account (`student_guardians` table)
- **Data ownership separation** — user account fields (`first_name`, `last_name`, `date_of_birth`, `email`) now live exclusively on the `users` table, independent of the admin-managed student record
- **Date of birth on user accounts** — users can enter and update their own date of birth

---

## V2.21

- Full test suite rewrite to cover latest features
- Standardized date format across the portal
- PayPal set as the default payment method
- Manual database backup/download button for admin

---

## V2.2

- **Home address on profiles** — street address and city/state/ZIP added to the student record
- **Waiver redesign** — student and parent waiver forms now match the physical PDF layout; admin can digitize physical waivers directly
- **Roster: name format & sort** — consistent "First Last" display with a name-field sort toggle across all rosters
- **Belt tests: auto-rank** — a passing score automatically records the rank in the student's history, no separate "Belt Awarded" step

---

## V2.1

- **Medical notes** — students and parents can enter a medical note on their profile, surfaced as a roster tooltip
- **Child Summary card** — parent dashboard shows a summary table of all linked children
- **User accounts split** — admin Users page separates Linked and Unlinked accounts
- **Compare & Link flow** — selecting a student on the user profile page opens the comparison view directly

---

## V2.0

- **Parent role** — family accounts manage one or more linked children through a tabbed portal (dashboard, attendance, payments, belt tests, waiver, profile per child)
- **Notify Noji on registration** — new users flag themselves as new/returning student or parent so the admin can review and link accounts without an out-of-band email
- **Compare & Link page** — side-by-side view of a login account and a roster entry, highlighting mismatched fields before linking
- **Family-aware tuition alert** — the "Tuition Unpaid" dashboard card understands family groups

---

## V1.0

Initial release — role-based portal (student, instructor, admin) for attendance tracking, belt test history, and PayPal payments, with a Playwright test suite.
