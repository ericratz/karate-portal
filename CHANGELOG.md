# Changelog

Full version history for the Shotokan Karate Portal. See `README.md` for the current architecture and setup.

---

## V4.2

Migrating the admin portal — the last htmx surface — to the same React SPA, phase by phase (see the earlier parent/student/instructor migrations in V4.0/V4.1). Ships as one release when complete.

**Phase 1 — admin SPA shell, code splitting, and the read-mostly CRUD pages:**

- **Fourth SPA shell** — `admin/app.php` serves the same `parent/dist/` bundle as the parent/student/instructor shells, behind the same server-side `require_role('admin')` gate. The admin dashboard (`admin/index.php`) stays server-rendered for now, so the shell's navbar brand still links to it
- **Route-level code splitting** — the admin routes load as their own lazily-imported chunks (`React.lazy` + Vite dynamic import), so parents, students, and instructors never download admin code. The server-side role gate already fences the pages; this is purely a payload optimization (~7 chunks, biggest ~10 kB gzipped)
- **Six migrated pages, six new `api/v1/admin/` endpoints** (all gated `require_role('admin')`, same `{ok,data}` envelope + `X-CSRF-Token` conventions as the parent/instructor APIs): the **roster** (`students.php` — reuses the instructor roster shape plus admin-only email/phone search and a registration-paid column), **users** (linked/unlinked account tables with role/status filters; the row actions stay on the still-server-rendered `user_profile.php`), **exemptions** (`waivers.php` — grant form with the shared type-to-filter student picker, filter bar, Edit-toggle delete), **donations**, **expenses**, and the combined **logs** viewer (Activity/Errors/Mail tabs; the log timeframe now defaults to *This Week*)
- **Legacy log pages consolidated away** — the pre-`logs.php` standalone viewers (`audit_log.php`, `app_log.php`, `email_log.php`) are now redirect stubs into the SPA logs route and can be deleted once nothing links to them
- **Every old admin URL preserved as a redirect stub** — `students.php`, `users.php`, `waivers.php`, `donations.php`, `expenses.php`, `logs.php` (and the three legacy log pages) forward into the SPA routes, carrying their filter/tab parameters through a fixed-vocabulary whitelist so no user-supplied string reaches the `Location` header (keeps Psalm's taint analysis clean). The `logs.php?download_backup=1` export moved to the always-present `db_backup.php`
- **Shared `StudentPicker` component** — the type-to-filter student selector the waivers/donations PHP pages hand-rolled is now one React component, preserving the legacy DOM contract (`#…Filter` input, `.…-stu-btn` option buttons, hidden id input, `setCustomValidity` on the visible box) the Playwright specs address
- **Tests** — the admin roster/users/waivers/donations/expenses/logs specs adapted to SPA rendering (async waits, hash-route redirects, all-time log filter), plus a stale-closure race fixed in the SPA filter bars (two rapid filter changes could drop each other's edit). Psalm + taint clean, PHPUnit green

**Phase 2 — dashboard and the account-linking flows:**

- **Admin dashboard migrated** (`index.php`, 725 lines → SPA route `#/admin`): stat cards, the 12-month revenue/expenses Chart.js chart (new `RevenueChart` component on the already-bundled chart.js — no more CDN script tag on this page, and the chart stays hidden on phones), attendance-not-recorded and rent-not-recorded banners, tuition-unpaid and missing-waiver lists, all three registration alert queues plus the legacy link-request and possible-account-link cards, and recent payments. The dashboard's opportunistic side effects (auto-inactivation pass, log retention) moved into the `api/v1/admin/dashboard.php` GET so they still run on every dashboard load
- **Linking flows migrated** — `user_profile.php` (account details edit-in-place, password reset, activate/deactivate, link/unlink, delete), `compare_account.php` (side-by-side login-vs-roster comparison with mismatch highlighting), and `resolve_link.php` (needs-manual-linking resolution with candidate search; the server-side transaction that deletes the auto-created duplicate is unchanged). Four new `api/v1/admin/` endpoints
- **Pre-existing bug fixed in the port** — `user_profile.php` read `$user['is_admin']` before loading `$user` on a self-edit, so an admin saving their own account details silently cleared their own admin flag; the API keeps the current DB value for self-edits
- **Admin navbar completed** — the SPA layout's brand and dropdown now route within the SPA; dashboard action buttons intentionally keep their legacy `.php` hrefs (all redirect stubs), so bookmarks and the Playwright specs' URL assertions keep working
- **Tests** — user-profile/dashboard/compare specs adapted (SPA waits, hash-route id extraction, the footer spec re-targeted to a page that still renders the PHP footer), plus a flash-message fix for hash-only navigations (same route, new query string doesn't remount the component)

**Phase 3 — payments and bulk email:**

- **Payments migrated** (`payments.php`, 712 lines → SPA route `#/admin/payments`): the record form with its required student picker (`?action=add&student_id=&type=` deep links from the dashboard still open and prefill it), per-type auto-amount that never clobbers a hand-edited value, the duplicate-tuition warning (warns, never blocks), student/type/method/year filters, the per-row "+" prefill button, and the Edit-toggle inline edit rows + delete column. The business rules stayed server-side in `api/v1/admin/payments.php`: guest → student auto-promotion when a registration fee is recorded, revert-to-guest sync when the last registration payment is deleted or retyped, and the receipt email on record
- **Bulk email migrated** (`email_students.php` → SPA route `#/admin/email`): compose card, group checkboxes with correct indeterminate states, click-anywhere recipient rows, live selected count, and send confirmation; `api/v1/admin/email_students.php` sends the same personalised plain-text emails through `log_email()`
- **Tests** — the email spec's "bypass the JS and submit the form natively" server-validation tests became direct API assertions (a fetch()-based SPA has no native form submission to bypass); payment filter assertions now wait on the filtered fetch instead of a page reload

**Phase 4 — the student editor, Class Notes, and htmx retirement:**

- **Student editor migrated** (`student_edit.php`, 1,846 lines — the biggest page in the codebase → SPA route `#/admin/student-edit`): the profile view/edit card, bulk attendance checkbox editing (with the empty-session cleanup), payment / rank / belt-test / exemption / guardian cards each with their inline add-boxes, per-row edit rows, and Edit-toggle reveal columns, the notes card, the new-student form, and profile delete. All 19 POST handlers moved action-for-action into `api/v1/admin/student_edit.php` with their side effects intact: guest→student promotion and revert-to-guest sync, belt-test deletion cleaning up the awarded rank, SKSD cert numbering, and the linked login account's name/email kept in sync. The htmx out-of-band card-sync trick this page existed for is native in React — one refetch updates every card
- **Class Notes migrated** (`student_notes.php` → SPA route `#/admin/notes`): the students-with-notes roster with live name search, the general class-notes log with highlight search and Ctrl+F capture, inline entry editing, and the per-student notes view
- **htmx fully retired** — the CDN script tag, swap-fade CSS, and tooltip re-init hook are gone from the shared header/footer; the parent profile-edit page's two htmx-enhanced forms became plain POSTs (their handlers already redirected for non-htmx requests). No `hx-` attribute remains in the codebase
- **Tests** — the student-edit and notes specs' native `form.submit()` bypasses became UI-driven flows, rank-history guards became assertions (the serial suite guarantees the data exists), and the delete-profile cleanup helpers wait for the SPA render. README/CHANGELOG updated to describe the completed migration

---

## V4.1

- **Payment page migrated to React** — `parent/pay.php` (the last big parent-facing PHP page) is now a SPA route (`app.php#/pay/N`): fee checklist with tuition month picker and already-paid notices, donations, custom amounts, PayPal Buttons, inline receipt, and the per-family-member auto-pay manager. The old URL is a redirect stub that preserves the role gate, the `?student_id=` deep link, and the `?autopay=` result flags (forwarded only from a fixed whitelist, keeping user input out of the Location header). Order create/capture reuse the existing `api/paypal_create.php` / `api/paypal_capture.php` JSON endpoints unchanged — server-side amount validation, family scoping, and the session-pinned order flow all still apply
- **New `api/v1/parent/pay.php`** — one GET returns everything the page previously computed server-side: family list, fee table, tuition-paid ids, paid months per student, registration/auto-pay state, and month options (still server-computed so client and server agree on "this month")
- **New `api/v1/parent/subscription.php`** — auto-pay create/cancel as JSON for the SPA (create returns the PayPal approve URL, cancel confirms inline) with the same scoping/audit/logging as the legacy form endpoints, which remain for the student pages. After approval, PayPal now returns parents to the pay page so the success message lands next to the auto-pay list
- **`api_respond()`/`api_error()` declared `: never`** — Psalm now understands endpoint guard clauses terminate, keeping level-4 static analysis and taint analysis clean without baseline additions
- **PayPal SDK loads on demand** — the SPA injects the same SDK script tag the PHP page used, only on the pay route; CSP needed no changes (www.paypal.com was already whitelisted for script/frame/connect)
- **Tests** — 12 new Vitest cases for the selection→items/total logic (extracted pure into `frontend/src/pay.ts`), 6 new Playwright tests (deep-link preselection, unlinked-id fallback, fee-total math, auto-pay manager, an API boundary test that a parent cannot create auto-pay for an unlinked student, mobile overflow), all suites passing: 113 PHPUnit, 43 Vitest, Psalm + taint clean
- **Student portal migrated to the same React SPA** — students now get the identical app the parent portal ships (`student/app.php` is a second thin shell over the one `parent/dist/` build; Vite's absolute base URL makes the hashed assets load from either shell). A student is simply a single-member family: the api/v1 role gates now admit every logged-in role (`guest` through `admin` — matching the old pages' `require_login()`), while `family_can_access()` keeps each user scoped to their own record. Zero new React pages were needed — dashboard, attendance, belt tests, payment history, waiver, and pay all reuse the parent components, which means students inherit inline profile editing, the rank-colored attendance chart, and the richer pay page (donations, per-month tuition notices) their old pages lacked
- **Student page URLs live on as stubs** — same pattern as the parent pages: `index.php` keeps the "account pending" screen for logins with no linked student record, the rest redirect into the SPA routes; `profile_edit.php` stays server-rendered (password change) and now shows its save/confirmation flash itself instead of bouncing it off the dashboard
- **Waiver inputs keep their legacy `name=` attributes** — same Playwright-testability convention as ProfileCard, so the shared waiver spec addresses the SPA form the way it addressed the PHP page
- **Test-suite adaptations for the SPA** — student pay/belt-tests/profile/waiver + shared smoke/coverage/mobile specs updated to SPA rendering (async waits, hash-route links, bundled Chart.js instead of the CDN tag, API-based chart-data assertion); the `logout()` helper now tolerates the benign navigation race where an in-flight SPA fetch 401s during logout and redirects to login first; full suite green (466+ Playwright tests)
- **Instructor portal migrated to the same React SPA** — all seven instructor pages (3,091 lines of PHP) are now SPA routes under `app.php#/instructor/…`: dashboard, roster (live search + status/login/rank/waiver/attendance filters), Take Attendance (row-click toggles, name filter, per-type sections, session delete), Classes (type/year filters in the route query, single-open accordion), All Belt Tests (type-to-filter student picker, Edit/Done delete column, confirm-guarded deletes), the instructor-facing student profile (inline own-profile edit, bulk attendance uncheck, write-only notes for instructors / note list for admins, linked-family tabs), and the full JKA grading chart (lower/regular chart switching by target rank, live subtotal/total/PASS-RETEST-FAIL preview, manual-score fallback, fee auto-check from same-day history, duplicate warning)
- **Six new `api/v1/instructor/` endpoints** (`dashboard`, `roster`, `attendance`, `sessions`, `belt_tests`, `student`, `belt_test_edit`) gated to instructor/admin, replicating the old pages' queries exactly — including the batched per-student rank/history queries from the belt-test editor's N+1 fix, the attendance save/auto-delete-empty-session semantics, score computation with clamped sub-scores, pass→auto-rank-award, and all audit entries. Existing-test editing stays admin-only at both the stub and the API
- **Legacy DOM contract preserved** — the React ports keep every id/class/name the Playwright specs and cross-suite fixtures rely on (`#cb-N`/`present[]` checkboxes, `#studentFilter`/`.student-btn` picker, `#editToggle`/`.delete-col`, `#det-N`/`#tog-N` accordion, `#count-*` badges tracking visible rows, hidden `session_date` input), so the vast majority of the ~1,000 lines of instructor specs run unchanged; rows hide via inline display rather than unmounting, matching how the specs measure visibility
- **Taint-proof date passthrough** — request-supplied `YYYY-MM-DD` strings are rebuilt from integer parts before entering redirect headers or JSON responses, keeping Psalm's taint analysis clean without suppressions

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
