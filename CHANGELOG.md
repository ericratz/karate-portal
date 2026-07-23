# Changelog

Full version history for the Shotokan Karate Portal. See `README.md` for the current architecture and setup.

---

## V4.7

Adds first-class tracking of who taught each class, with support for more than one instructor on the same
day, and points the admin "Attendance" entry at the class list.

- **Class instructor tracking, with multiple instructors per class** — `class_sessions` had carried a single `instructor_id` since the original schema, but it was dormant: silently set to whoever *saved* the attendance sheet and never displayed anywhere. It's now a deliberate, visible field. A new `class_session_instructors` join table records the taught-by set as a many-to-many, so a class can name several instructors — for a mid-class hand-off (one instructor leaves early and turns the class over) or team teaching. The Take Attendance page gained a **"Taught by"** multi-select (active admins + instructor-type users) that defaults a not-yet-recorded class to the primary admin — the founder account, usually Noji — since that's who teaches most days; the instructor can change it before saving. The Classes list gained an **Instructor** column showing the name(s). The save endpoint validates the submitted user IDs against the real instructor/admin set, so a tampered request can't attach arbitrary users to a session; when an empty class is dropped (nobody present), the join rows cascade away with it. The legacy `instructor_id` column is left in place as the who-recorded-it stamp and is no longer treated as the answer to who taught
- **Admin "Attendance" nav now opens the class list** — both the admin top-navbar "Attendance" button and the matching Admin-dropdown item pointed at `/instructor/attendance`, which always opened a blank sheet for *today*; they now open `/instructor/classes` (the Classes list), from which any date — including today — is one click via the existing date picker + "Record New Class". Instructor navigation is unchanged
- **Schema + migrations** — new `class_session_instructors` table added to `karate_schema.sql`. For an existing database, apply `add_class_session_instructors.sql` (creates the table), then `backfill_class_instructors_noji.sql` (attributes every pre-existing class that has no instructor to the primary admin, so the historical sessions didn't need hand-editing). Both use `IF NOT EXISTS` / `NOT EXISTS` guards and are safe to re-run
- **Testability + coverage** — the instructor logic was pulled out of the endpoints into `includes/instructors.php` (mirroring `includes/family.php`), so the "instructor/admin only" rule, the picker default (primary admin), and the taught-by read/write live in one directly-testable place; the take-attendance endpoint dropped its inline validation SQL for `filter_instructor_ids()` / `set_session_instructors()`. A new `InstructorSessionTest` covers the validation (admins + active instructors kept; plain students, inactive instructors, unknown ids, and duplicates dropped), the primary-admin default, and the taught-by round-trip. On the front end, the Take Attendance page's pure helpers (`fmtHeading`, `sortStudents`) moved to `attendance-helpers.ts` with unit tests, and the Classes list's date formatter was deduped onto the existing `fmtDateWeekday`. Vitest coverage reporting was added (`@vitest/coverage-v8`, `npm run coverage`) to make the number visible
- **Tests** — full suite green with no spec changes needed (the Classes list's specs don't pin an exact column set): 518 Playwright, 122 PHPUnit (191 assertions), 50 Vitest, Psalm standard + taint clean, strict TypeScript clean

---

## V4.6

Maintenance release: the remaining Dependabot upgrades, a Psalm target-version correction, and a set of
front-end refinements to the payment screen, long-text table cells, and the parent dashboard.

- **Dependency upgrades** — TypeScript 5.9 → 7.0.2 and jsdom 28 → 29 in the React SPA, and `actions/checkout` and `actions/upload-artifact` 4 → 7 in both workflows. The TypeScript jump was the notable one: the SPA had deliberately stayed on 5.x (only the test toolchain moved to TS 7 in V4.3), and the two-major bump to the native compiler passed the strict typecheck, Vitest, production build, and the full Playwright suite with zero new type errors. These are the routine version bumps that were deferred in V4.4, when only the security-advisory bumps were taken. The two GitHub Actions upgrades can only be exercised on the self-hosted runner, so their first validation is this release's own CI run
- **Psalm `phpVersion` corrected 8.2 → 8.4** — it had lagged the runtime since the XAMPP-to-Docker switch. Dev, CI, and live all run PHP 8.4.23, so Psalm now analyzes against the same language semantics; standard + taint both stay clean at the newer version. (The `composer.json` platform pin was already correct at 8.4.23.)
- **Payment screen — a single-choice "Paying for" selector collapses to text** — when there is only one person to pay for (e.g. a student paying for their own record), the member dropdown was a pointless one-option control; it now renders the name as static text. Parents with multiple linked family members still get the dropdown, and the admin payment recorder (a type-to-filter over all students) is unchanged
- **Payment screen — the in-person cash/check option is spelled out** — the "Other Payment Options" card only described mailing a check; it now also states that payment can be made in person with cash or check at class, above the existing mailing address
- **Long free-text table cells truncate with a "View all" toggle** — notes, reasons, and descriptions that can run arbitrarily long were rendered in full inside their table cells, so a single long entry could blow out a row. A new shared `<TruncatedText>` shows the first 50 characters with a **View all** / **Show less** control, applied to belt-test notes, donation notes, exemption reasons, expense descriptions, and payment notes/payer notes. The admin-dashboard and compare-account link-request note columns — one of which previously hard-cut at 60 characters with no way to read the rest — were switched to the same component so every long-text cell behaves identically
- **Parent dashboard — child-summary "Next Test HW" link uses the standard external-link icon** — its outbound cue was a bare angled arrow (`↗`) while every other outbound link on the dashboard used the box-with-arrow "opens in new window" icon; it now uses the same shared `ExtIcon`
- **Tests** — 518 Playwright, 113 PHPUnit, 43 Vitest, Psalm standard + taint clean, strict TypeScript (now TS 7) clean

---

## V4.5

Began as a follow-up to V4.4's dev-environment switch — the hostname it introduced turned out to be
unusable in a browser — and grew into a broad UI/table pass, a fix for a class of shell-relative broken
links, and a full audit of the Psalm baseline.

**Dev environment — a browsable hostname and an explicit environment flag:**

- **Dev hostname changed from `app` to `karate.test`** — V4.4 browsed the portal at `http://app/karate/portal`, reusing the Compose service name. That works for `curl` and Playwright but not for a browser: `.app` is a real gTLD on the HSTS preload list, so a bare `app` hostname is forced to `https://` and plain HTTP is refused before a request is sent. Preloaded HSTS entries cannot be clicked through or deleted (`chrome://net-internals/#hsts` only clears *dynamic* entries), so no browser setting could work around it — the hostname had to change. `.test` is reserved by RFC 2606 and will never be delegated or preloaded. A Compose network alias makes `karate.test` resolve inside the network too, so the `ci` container and the host browser still share one `SITE_URL` with no environment fork. The `nikto`/`zap` scanner targets and the Vite dev-server proxy moved with it
- **Environment declared explicitly via `APP_ENV`, replacing hostname sniffing** — V4.4's `site_is_https()` inferred dev-vs-production from a hostname allowlist (`localhost`/`app`), which meant every new dev hostname had to be added to PHP or the session cookie got `Secure` over plain HTTP. Browsers discard such cookies silently, so the symptom would be a login that appears to succeed and then bounces back to the login page, with nothing logged anywhere — and the `karate.test` rename would have triggered exactly that. Now only `APP_ENV=dev` opts out, read exclusively as a real environment variable set in `docker-compose.yml`, deliberately never from `.env`: that file is deployed to the live host, where a stray `APP_ENV=dev` would silently ship insecure cookies. Production runs without Docker, sets nothing, and is secure by omission — the safe state requires no correct configuration. Adding a dev hostname is now a hosts-file line, not a code change

**Shell-independent navigation — a class of broken links fixed:**

- **The SPA is served from four PHP shells** (`admin/app.php`, `instructor/app.php`, …), and many in-app links were still relative `.php` hrefs (`student_edit.php`, `belt_test_edit.php`, `student_profile.php`, `attendance.php?date=`). A relative href resolves against the *current* shell, so opening an instructor-only page under the admin shell — or vice-versa — produced a 404 (e.g. `admin/belt_test_edit.php`, which doesn't exist). The reported symptom was reaching the exemptions table under the wrong shell and getting a 404 from its `student_edit.php` link. Every such link is now an in-app hash route (`#/admin/student-edit?id=…`, `#/instructor/student/…`) that works under any shell and navigates client-side without a reload. Absolute `../instructor/…` links from admin pages (which bounced the admin onto the instructor shell mid-flow) were converted too. The handful of genuinely server-rendered targets — `certificate.php`, `member_card.php`, `waiver_view.php`, and the instructor profile's "Full Edit" (whose `.php` stub enforces the admin-role gate) — were intentionally left as `.php`. Every admin/instructor page was crawled to confirm zero remaining 404 links
- **A related shell-relative bug fixed on the admin student editor** — its "Record New Class" date form and belt-test links did native `.php` navigations that resolved against the wrong shell; they now navigate in-app

**UI and tables:**

- **Site footer ported to the SPA** — the shared `<footer>` (trademark / "Questions or Issues?" contact card / copyright, with the collapse chevron and AJAX feedback form) was never migrated, so every React page rendered without it while the still-server-rendered pages kept theirs. Now a React `Footer` in the shared layout, posting to the same `api/send_feedback.php`. Its fixed positioning also restored the `body` bottom-padding, removing a phantom scrollbar
- **Uniform table column spacing** — a global `table-layout: fixed` splits every table's width evenly across its columns, so the gap between adjacent columns is consistent within a table and across the app. Utility columns (checkboxes, row-action buttons) get a fixed narrow width via shared classes so they don't take an equal share; the four role tables on the take-attendance page share a colgroup so their columns line up with each other. All money/amount columns were switched from right- to left-aligned for consistency
- **Rolling log timeframes** — the Logs filter windows now count back from now (`Last 24 Hours / 7 Days / 30 Days / 365 Days`) instead of calendar boundaries, which showed nothing just after midnight or only Monday's rows on a Monday morning; the three log-tab tables were made visually consistent (same text size, equal columns)
- **Smaller fixes** — the admin navbar dropdown's purple styling (dropped in the SPA migration) was restored; the current-rank "purple row" highlight was removed from the rank-history tables; the redundant "All Fees" exemption type was removed from the grant/filter dropdowns (kept as a display label for any legacy row); recent-attendance dates now render as `Tue` rather than `Tuesday` to match the other cards; the bulk-email recipient table gained a `✓` header on its checkbox column to match attendance

**Static analysis — Psalm baseline audited and cut 639 → 54 lines (~92%):**

- **Root cause of the noise identified and fixed at the source** — 73% of the baseline was `UndefinedFunction`/`UndefinedConstant` (`db()`, `has_role()`, `SITE_URL`, …). PHP functions aren't autoloadable, so Psalm resolves them through the include graph, and the template partials (header, footer, cron, PayPal helpers) call shared helpers without `require`-ing the files that define them. Enabling `allFunctionsGlobal` + `allConstantsGlobal` — the idiomatic Psalm setting for this include pattern — resolved all 280 occurrences correctly instead of baselining them, and immediately *surfaced* 7 real issues that had been masked behind them. Type inference rose 83% → 87%
- **The remaining findings were reviewed one by one, not blanket-baselined.** Verdict: no genuine bugs, but several worth hardening — a PayPal webhook `event_type` type-confusion guard (a malformed but signature-valid payload can no longer reach `strtolower()` as an array and TypeError), a `preg_replace` null guard in phone formatting (PHP 8.1 deprecation), `file()`-returns-`false` guards in the `.env` loaders, and `filemtime`/`filesize` guards in the backup cron. The `date(…, strtotime($x))` and `json_decode(file_get_contents(…))` patterns were made type-clean with explicit casts / `time()` arithmetic; `#[\Override]` was added to test `setUp()` methods; and four `assertNotNull` were added to `BeltNextRankTest`. What remains baselined (54 lines) is deliberately kept: over-defensive null checks that shouldn't be weakened just to satisfy the linter, one genuine false positive (`PAYPAL_MODE === 'live'`, which Psalm can't see because it only reads the `'sandbox'` default), and a test-only `$_SESSION` quirk

**Tests** — 518 Playwright, 113 PHPUnit (178 assertions), 43 Vitest, Psalm standard + taint clean, strict TypeScript clean. Cookie behaviour verified in both directions (`APP_ENV=dev` → no `Secure` over HTTP dev; unset → `Secure` in every case, including a stale `http://` `SITE_URL`). The shell-relative link fixes required updating ~15 spec assertions that pinned the old `.php` hrefs, plus two cleanup helpers

**Known gap, carried forward:** internal *redirects* are still built as absolute URLs from `SITE_URL`
(`header('Location: ' . SITE_URL . '/login.php')`). RFC 7231 §7.1.2 permits relative `Location` headers,
which all browsers support; with relative internal redirects the server side would also work under any
hostname with no alias or hosts entry, leaving `SITE_URL` only for things that genuinely leave the app
(password-reset emails, the Google OAuth `redirect_uri`, PayPal return URLs). Roughly 40 call sites across
16 files. Not attempted here — this release fixed the client-side link equivalent, not the server-side redirects

---

## V4.4

Security and toolchain release: the first Dependabot advisory sweep, session-cookie hardening for the
production TLS setup, and retiring XAMPP so local development runs the same PHP as live.

- **All five Dependabot security advisories resolved** — every one transitive through the single direct dependency `google/apiclient`, so a dependency update cleared them with no application code change: `guzzlehttp/guzzle` 7.11.1 → 7.15.1 (silent HTTPS-proxy downgrade to cleartext; dot-only cookie domains matching all hosts), `guzzlehttp/psr7` 2.11.0 → 2.13.0 (CRLF injection in HTTP start-line serialization), `firebase/php-jwt` 6.10.0 → 7.1.0 (weak encryption, CVE-2025-45769), and `phpseclib/phpseclib` (X.509 AIA SSRF) **removed from the tree entirely** — `google/auth` 1.52.0 no longer depends on it. `php-jwt` was capped at 6.x by `google/apiclient` v2.16.1 and `google/auth` v1.37.2, so lifting it required `google/apiclient` → v2.19.4. Reviewed for actual exposure first: the app uses the library only for Google OAuth login (`createAuthUrl`, token exchange, `userinfo`), configures no HTTP proxy, uses no Guzzle cookie jar, and parses no attacker-supplied certificates, so none of the five were reachable in practice. `composer audit` now reports no advisories
- **Composer platform pinned to production's runtime** — `config.platform.php` is now `8.4.23`, matching live (Midphase/StackCP) and both containers, so Composer can never resolve a package the production host cannot run
- **Psalm 5.26.1 → 6.16.1** — forced by the platform pin, which immediately failed resolution: Psalm 5 declares `php ^7.4 || ~8.0 || ~8.1 || ~8.2 || ~8.3` and has no PHP 8.4 support at all. `docker/ci.Dockerfile` had been carrying `--ignore-platform-req=php` with a comment documenting exactly this gap; Psalm 6 supports 8.4 natively, so the override was removed and the image now installs cleanly without it — a genuinely incompatible dependency will fail the build instead of installing silently. Closes Dependabot PR #3
- **Dead Psalm suppression removed** — the `TaintedHeader` exemption for `admin/student_edit.php` no longer matched anything (V4.2 reduced that file to a 13-line redirect stub), and Psalm 6 reports unused suppressions as errors
- **Session cookie hardening** — the `Secure` flag was derived from `$_SERVER['HTTPS']`, which is not set on the live host: Midphase/StackCP terminates TLS at a load balancer, so the session cookie was shipping without `Secure` over HTTPS in production. Replaced with `site_is_https()`, which fails safe — it assumes HTTPS unless `SITE_URL` names a known dev host (`localhost`, `app`), so a stale `http://` value in the live `.env` still yields a Secure cookie. `X-Forwarded-Proto` was deliberately not trusted: it is client-supplied and the proxy is not under our control
- **`checkin.php` session cookie fixed** — the public PIN-gated check-in page called a bare `session_start()`, so a visitor landing there first was issued a `PHPSESSID` with PHP's ini defaults (no `HttpOnly`, no `SameSite`, no `Secure`) — and that same cookie was reused once they logged in. Both entry points now go through one `start_secure_session()` in `config.php`
- **XAMPP retired as the dev server** — it caps at PHP 8.2.12 while live and both containers run 8.4.23, so local development no longer diverges from production. The `app` container publishes on host port 80 (`APP_PORT=80`) and the portal is browsed at `http://app/karate/portal`, backed by a `127.0.0.1 app` hosts entry. That one name resolving identically from the host browser and from the `ci` container over Compose DNS is what lets a single `SITE_URL` serve both, so server-side redirects work in a real browser and under Playwright without an environment fork or code change
- **Tests** — 518 Playwright, 113 PHPUnit, Psalm taint analysis clean, Google OAuth login URL verified rendering against the upgraded client. The Playwright suite was run against unmodified code as a control to confirm the session-cookie change caused no regressions
- **Psalm's standard analysis restored as a CI step** — `tests.yml` had been running only `psalm --taint-analysis` since commit `24d6c64` ("speed up psalm"), while the step was still *named* "Run Psalm static + taint analysis" and the README claimed static analysis ran on every push. The two modes do not overlap — taint analysis tracks data flow from sources to sinks and performs none of the type checks — so `errorLevel="3"` and the baseline had been unenforced since V3.7. Now two dedicated steps again, as in V3.6, costing about a minute on the containerized runner. README claims corrected to match in four places
- **Psalm baseline regenerated under Psalm 6** (598 → 639 lines), capturing the 83 errors Psalm 6's stricter standard analysis surfaced in pre-existing code that Psalm 5 never checked: 49 `PossiblyFalseArgument` (mostly `date('j M Y', strtotime($x))`, where `strtotime` can return `false`, plus `json_decode(file_get_contents('php://input'))` in the PayPal endpoints), 18 `MissingOverrideAttribute`, and 16 others. None are regressions from this release. Baselining makes the CI step a ratchet — the known issues stay recorded and visible in a tracked file, while any newly-introduced error fails the build. Worth fixing on their own merits later: 35 of them Psalm can fix automatically (`--alter --issues=MissingOverrideAttribute,MissingClosureReturnType,ClassMustBeFinal`)

---

## V4.3

Cleanup and dependency-update release following the V4.2 migration.

- **Dependency automation switched on** — Dependabot's first sweep (configured in V4.2's `.github/dependabot.yml`) plus a branch ruleset protecting `main` from force pushes and deletion
- **Vite 6 → 8 and `@vitejs/plugin-react` 4 → 6** — the interdependent pair upgraded together; production builds dropped from ~6.4s to ~1.5s, full Playwright + Vitest suites green
- **TypeScript 7 for the test-suite typecheck** — the root toolchain moved to TS 7, whose stricter DOM-element and bare-`resolve()` checks surfaced eight latent type holes in the Playwright specs, fixed with JSDoc casts (the SPA itself stays on TS 5.x for now)
- **Playwright 1.61 attempted and deliberately reverted** — in the `v1.61` Docker image, Chromium (both the headless shell and the full binary) cannot open TCP connections to sibling compose containers on the Docker Desktop/WSL2 host, while node and curl in the same container connect fine; the toolchain stays pinned at `v1.60.0-noble` with the reason documented in `docker/ci.Dockerfile`
- **License made coherent** — `LICENSE` rewritten as a proper source-available proprietary license (Shotokan Karate and Self-defense as copyright holder, viewing/evaluation explicitly permitted, all use requiring written permission, no-warranty clause); the contradictory `"ISC"` in `package.json` became `"UNLICENSED"` + `"private": true`, with matching declarations added to `frontend/package.json` and `portal/composer.json`
- **Repository cleanup** — removed a stray empty `portal;C/` directory (mangled-path artifact), the empty gitignored `migrations/` folder, an obsolete root `memory/` notes folder, pre-Docker `composer.phar`/`composer-setup.php` leftovers, and two accidentally committed artifacts (`test-results/.last-run.json`, `portal/.phpunit.result.cache`); `test-results/` is now gitignored
- **Test coverage** — 518 Playwright tests, 113 PHPUnit tests, 43 Vitest tests, all passing on the upgraded toolchain

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
