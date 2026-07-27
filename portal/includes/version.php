<?php
// Single source of truth for the deployed version. Bumped by hand as step 1 of
// RELEASE.md, and nowhere else.
//
// This exists because dev and live were otherwise indistinguishable: nothing in
// the shipped tree named its own version, so "what is live actually running?"
// could only be answered by diffing files over FTP. Since the build artifacts
// (parent/dist/, vendor/) are deliberately untracked and uploaded by hand, a
// half-finished upload is the single most likely way this app breaks — the
// instructor-tracking deploy blanked the attendance page exactly that way, with
// a new bundle talking to an old API.
//
// The SPA bundle compiles this same number in (frontend/vite.config.ts parses
// the define below at build time) and compares it against what api/v1/me.php
// reports at runtime. A mismatch means a partial deploy, and now says so out
// loud instead of rendering an empty page.
//
// Keep the format a bare dotted number — vite.config.ts matches on '...' quotes.
if (!defined('APP_VERSION')) define('APP_VERSION', '5.1.1');
