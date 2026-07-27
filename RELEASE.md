# Release Runbook

How to ship a release to live, in order, and how to prove it worked.

Live is shared hosting (Midphase/StackCP) with **no build step and no Composer** — it runs whatever
files are uploaded to it. The build artifacts (`portal/parent/dist/`, `portal/vendor/`) and the
brand assets (`portal/admin/assets/`) are deliberately untracked, so git is *not* the deploy
manifest. This document is.

For database restores, see [`tests/RESTORE_RUNBOOK.md`](tests/RESTORE_RUNBOOK.md).

---

## The matched set

A release is **one atomic set**. Uploading part of it is the single most likely way this app breaks:

| Piece | Ships when |
|---|---|
| SQL migration | the release changes the schema |
| `portal/**/*.php` | any PHP changed |
| `portal/parent/dist/` | **any** front-end change — and it is rebuilt for *every* release, because the version is compiled into the bundle |
| `portal/vendor/` | only when `composer.json` / `composer.lock` changed |
| `portal/admin/assets/` | only when a font/image/template changed |

The instructor-tracking deploy blanked the attendance page on live because a new bundle was uploaded
against the old API. Since then the app detects this itself — see [Step 5](#step-5--verify-live) —
but the fix is still to never ship a partial set.

---

## Step 0 — Freeze and bump the version

1. Decide the version number, e.g. `5.1`.
2. Edit **`portal/includes/version.php`** — this is the only place the number lives. The PHP side
   defines it, and `frontend/vite.config.ts` parses that same file at build time so the bundle
   carries the identical number. A build that cannot read it fails rather than shipping "unknown".
3. Update the heading in `CHANGELOG.md` and the title line of `README.md` to match.

## Step 1 — Pre-flight (all green, no exceptions)

Everything runs in the `ci` container, exactly as CI does. **The images bake the source**, so
rebuild first or you will be testing the previous release:

```bash
docker compose build
docker compose up -d db app
```

```bash
docker compose run --rm ci npm run typecheck
docker compose run --rm ci sh -c "cd portal && vendor/bin/psalm"
docker compose run --rm ci sh -c "cd portal && vendor/bin/psalm --taint-analysis"
docker compose run --rm ci sh -c "cd portal && vendor/bin/phpunit"
docker compose run --rm ci sh -c "cd frontend && npm run typecheck && npm test"
docker compose run --rm ci npx playwright test
```

Record the counts in the CHANGELOG's **Tests** line.

## Step 2 — Build and extract the bundle

The bundle is built **inside the image**, never on the host — a host `npm run build` is broken here
by a Rolldown Windows binary, and `portal/parent/dist` is dockerignored precisely so a stale host
build can never be picked up.

```bash
docker compose build app
docker compose up -d --force-recreate app
docker compose cp app:/var/www/html/karate/portal/parent/dist ./portal/parent/dist
```

> **Trap:** `docker compose cp` reads the **running container**, not the image you just built. Without
> the `--force-recreate` line the container keeps running the previous image and you extract the
> *previous* release's bundle — silently, with no error. That is a partial deploy pre-loaded into the
> upload set, so always confirm the version actually baked in before uploading:
>
> ```bash
> grep -o '· v.\{0,14\}' portal/parent/dist/assets/index-*.js
> ```
>
> It must print the version from `version.php`. The entry filename is content-hashed, so a hash
> identical to last release's is itself a sign nothing was rebuilt.

> **Trap:** the extracted folder contains a **hidden `.vite/` directory** holding `manifest.json`.
> The four PHP shells (`admin/app.php`, `instructor/app.php`, `parent/app.php`, `student/app.php`)
> read that manifest to emit the hashed `<script>`/`<link>` tags. Upload it. An FTP client with
> "show hidden files" off will silently skip it and every SPA page will 503 with
> *"Frontend bundle not built"*.

Sanity-check before uploading:

```bash
ls -a portal/parent/dist          # must list .vite, assets, index.html
cat portal/parent/dist/.vite/manifest.json | head
```

## Step 3 — Back up live, before touching anything

Take a fresh dump even if the weekly cron ran — this is the rollback point.

- Admin portal → DB backup page (`portal/admin/db_backup.php`), or
- StackCP file manager → the `backups/karate/` directory on the hosting account (sibling of
  `public_html`, so it is not web-reachable; the full path is in `portal/cron/CRON_SETUP.txt`,
  which is untracked on purpose)

Download it to `backups/` locally. A valid dump starts with `-- Database backup:` and ends with
`-- End of backup`; if the footer is missing the export was truncated — do not proceed.

## Step 4 — Deploy, in this order

**Order matters.** Migrations are written additively, so the old code keeps running against the new
schema; the reverse (new code, old schema) is a hard error.

1. **SQL migration** — apply through phpMyAdmin on StackCP. Migrations are one-time scratch files
   in `migrations/` (untracked by design); `karate_schema.sql` at the repo root is the source of
   truth for the resulting shape, so update it in the same release.
2. **PHP files** — upload the changed `portal/**` tree, preserving paths.
3. **`portal/vendor/`** — only if Composer deps changed.
4. **`portal/parent/dist/`** — last, and **replace the whole folder** including `.vite/`. Filenames
   are content-hashed, so leftovers from the previous release are harmless but pointless; a stale
   `manifest.json` is not harmless.

Never set `APP_ENV=dev` on live — it is the one flag that disables the `Secure` session-cookie flag.
It lives in `docker-compose.yml` and must never appear in the live `.env`.

## Step 5 — Verify live

1. **Version stamp.** Load any server-rendered page (e.g. a profile edit page) and read the footer:
   it must show `· v<new version>`. This answers "what is live actually running?" without diffing
   files.
2. **No partial-deploy banner.** Open an SPA page. If the bundle and the API disagree, a yellow
   *"Partial deploy detected"* bar appears at the top naming both versions — finish the upload.
   Silence here means the two halves match.
3. **Walk the four shells** — admin, instructor, parent, student. Each must render, not 503.
4. **Exercise what the release actually changed**, including anything touching the migration.
5. **Check the error log** in StackCP for new entries.

> **There is no PayPal sandbox for this project — only live.** Payment code therefore has no safe
> rehearsal: the only way to exercise it is a real transaction with real money. Treat any change
> touching `api/paypal_*.php`, `includes/paypal.php`, or the pay routes as high-risk, keep such
> changes out of releases that are already large, and if one must ship, verify with a single small
> real payment and confirm the transaction ID lands in the payments table. The URLs PayPal itself
> calls — the webhook registered in the PayPal dashboard, and the `return_url`/`cancel_url` sent per
> subscription — must stay absolute; a relative value there silently breaks the return leg.

## Rollback

1. Re-upload the previous release's `portal/` tree and its `parent/dist/` (keep the last release's
   extracted folder around until the new one is confirmed — this is the only reason to keep it).
2. If the migration must come out, restore the Step 3 dump per
   [`tests/RESTORE_RUNBOOK.md`](tests/RESTORE_RUNBOOK.md). Additive migrations usually need no
   reversal: an extra column or table is inert to the older code.

---

## Post-release

- Move the CHANGELOG entry from in-progress to released.
- Delete the applied file(s) from `migrations/` — it is scratch space, and `karate_schema.sql`
  now carries the shape.
