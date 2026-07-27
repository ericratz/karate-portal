// SessionProvider's partial-deploy detection.
//
// This is the release safety net: the bundle compiles in the version it was
// built against and compares it with what api/v1/me.php reports, so a
// half-finished upload announces itself instead of rendering a blank page.
//
// It is worth testing precisely because it is a safety net — if it silently
// stopped working, nothing would fail, and the only way to find out would be
// the next bad deploy presenting exactly the way it did before the net existed.

import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetCsrfTokenForTests } from './api/client';
import { SessionProvider } from './SessionContext';

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

/** Answers /me.php with the given server version, and /family.php with a stub. */
function mockApi(serverVersion: string) {
  return vi.fn((input: RequestInfo | URL) => {
    const url = typeof input === 'string' ? input : String(input);
    if (url.includes('/me.php')) {
      return Promise.resolve(
        jsonResponse({
          ok: true,
          data: {
            user_id: 1,
            username: 'Noji',
            role: 'admin',
            csrf_token: 'tok',
            app_version: serverVersion,
          },
        }),
      );
    }
    return Promise.resolve(jsonResponse({ ok: true, data: { students: [], parent: null } }));
  });
}

const BANNER = /partial deploy detected/i;

describe('SessionProvider — partial-deploy detection', () => {
  beforeEach(() => {
    resetCsrfTokenForTests();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('shows no banner when the bundle and the server agree', async () => {
    vi.stubGlobal('fetch', mockApi(__APP_VERSION__));

    render(
      <SessionProvider>
        <p>dashboard</p>
      </SessionProvider>,
    );

    await screen.findByText('dashboard');
    expect(screen.queryByText(BANNER)).toBeNull();
  });

  it('warns when the server is running a different version than the bundle', async () => {
    vi.stubGlobal('fetch', mockApi('9.9'));

    render(
      <SessionProvider>
        <p>dashboard</p>
      </SessionProvider>,
    );

    const banner = await screen.findByText(BANNER);
    expect(banner).toBeTruthy();
  });

  it('names both versions, so the banner says what to fix', async () => {
    vi.stubGlobal('fetch', mockApi('9.9'));

    render(
      <SessionProvider>
        <p>dashboard</p>
      </SessionProvider>,
    );

    // A banner that only said "something is wrong" would not shorten the
    // debugging at all — the two numbers are the actionable part.
    await waitFor(() => {
      const text = document.body.textContent ?? '';
      expect(text).toContain(__APP_VERSION__);
      expect(text).toContain('9.9');
    });
  });

  it('still renders the app when versions mismatch', async () => {
    vi.stubGlobal('fetch', mockApi('9.9'));

    render(
      <SessionProvider>
        <p>dashboard</p>
      </SessionProvider>,
    );

    // Deliberately a warning, not a block: a mismatched deploy is usually still
    // usable, and locking the admin out of the app is a worse failure than the
    // one being reported.
    expect(await screen.findByText('dashboard')).toBeTruthy();
  });
});
