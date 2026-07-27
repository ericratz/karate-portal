<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * base_path() / app_url() — the helpers every internal link and redirect in the
 * app now goes through (~40 call sites across 16 files).
 *
 * The property that matters is negative: an internal URL must never carry a
 * scheme or hostname. That is what lets the server work under any hostname
 * instead of only the one the live .env happens to name, and it is the thing a
 * well-meaning "fix" would most easily undo by reaching for SITE_URL again.
 *
 * SITE_URL is a constant and base_path() memoises, so these assert against the
 * configured value rather than sweeping alternatives — the shape of the output,
 * not one hardcoded string.
 */
final class AppUrlTest extends TestCase
{
    public function testBasePathIsThePathComponentOfSiteUrl(): void
    {
        $expected = rtrim((string)parse_url(SITE_URL, PHP_URL_PATH), '/');
        self::assertSame($expected, base_path());
    }

    public function testBasePathCarriesNoSchemeOrHost(): void
    {
        self::assertStringNotContainsString('://', base_path());
        self::assertStringNotContainsString('http', base_path());
    }

    public function testBasePathHasNoTrailingSlash(): void
    {
        // A trailing slash would double up: base_path() . '/login.php'.
        self::assertStringEndsNotWith('/', base_path());
    }

    public function testAppUrlPrefixesTheBasePath(): void
    {
        self::assertSame(base_path() . '/login.php', app_url('/login.php'));
    }

    public function testAppUrlAcceptsAPathWithoutALeadingSlash(): void
    {
        self::assertSame(app_url('/login.php'), app_url('login.php'));
    }

    public function testAppUrlIsAlwaysRootRelative(): void
    {
        // The regression this whole helper exists to prevent. A document-relative
        // path ('login.php') resolves against whichever shell directory the
        // current page lives in — the V4.5 404 bug — and an absolute one pins the
        // app to a single hostname.
        foreach (['/login.php', '/admin/', '/instructor/attendance_sessions.php', ''] as $path) {
            $url = app_url($path);
            self::assertStringStartsWith('/', $url, "must be root-relative: '$path'");
            self::assertStringNotContainsString('://', $url, "must not be absolute: '$path'");
        }
    }

    public function testAppUrlWithNoArgumentsIsUsableAsAPrefix(): void
    {
        // Templates echo app_url() and then concatenate a path onto it, as in
        // href="{app_url()}/admin/students.php", so the bare call must not
        // return something that breaks when concatenated. (Written without a
        // literal PHP close tag on purpose: "?" + ">" ends PHP mode even inside
        // a comment, which is what broke this file the first time.)
        self::assertStringStartsWith('/', app_url());
        self::assertStringEndsNotWith('/', app_url() . '/x');
    }

    public function testQueryStringsAndFragmentsSurvive(): void
    {
        self::assertSame(base_path() . '/two_factor.php?e=1', app_url('/two_factor.php?e=1'));
        self::assertSame(base_path() . '/admin/app.php#/admin', app_url('/admin/app.php#/admin'));
    }

    public function testSiteUrlItselfRemainsAbsolute(): void
    {
        // The other half of the split: SITE_URL is still what outbound links need
        // — password-reset emails, the Google redirect_uri, PayPal's return URLs.
        // If this ever becomes relative, those break silently and off-site.
        self::assertMatchesRegularExpression('#^https?://#', SITE_URL);
    }
}
