// The API client's contract with includes/api.php: unwrap the {ok, data}
// envelope, surface {ok:false, error} as typed ApiError, and echo the CSRF
// token from /me.php in X-CSRF-Token on every POST.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

// client.ts caches the CSRF token in module state — re-import fresh per test
async function freshClient() {
  vi.resetModules();
  return import('./client');
}

describe('api client', () => {
  const fetchMock = vi.fn();

  beforeEach(() => {
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('apiGet unwraps the success envelope', async () => {
    const { apiGet } = await freshClient();
    fetchMock.mockResolvedValueOnce(jsonResponse({ ok: true, data: { hello: 'world' } }));

    await expect(apiGet('/parent/family.php')).resolves.toEqual({ hello: 'world' });
    expect(fetchMock).toHaveBeenCalledWith(
      '/karate/portal/api/v1/parent/family.php',
      expect.objectContaining({ credentials: 'same-origin' }),
    );
  });

  it('apiGet throws ApiError with the server message on ok:false', async () => {
    const { apiGet, ApiError } = await freshClient();
    fetchMock.mockResolvedValueOnce(
      jsonResponse({ ok: false, error: 'Student not linked to your account' }, 403),
    );

    const err = await apiGet('/parent/student.php?student_id=2').catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as InstanceType<typeof ApiError>).message).toBe('Student not linked to your account');
    expect((err as InstanceType<typeof ApiError>).status).toBe(403);
  });

  it('apiPost fetches /me.php first and sends its CSRF token as X-CSRF-Token', async () => {
    const { apiPost } = await freshClient();
    fetchMock
      .mockResolvedValueOnce(
        jsonResponse({ ok: true, data: { user_id: 1, username: 't', role: 'parent', csrf_token: 'tok123' } }),
      )
      .mockResolvedValueOnce(jsonResponse({ ok: true, data: { saved: true } }));

    await expect(apiPost('/parent/profile.php', { student_id: 4 })).resolves.toEqual({ saved: true });

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock.mock.calls[0][0]).toBe('/karate/portal/api/v1/me.php');
    const postInit = fetchMock.mock.calls[1][1] as RequestInit;
    expect(postInit.method).toBe('POST');
    expect((postInit.headers as Record<string, string>)['X-CSRF-Token']).toBe('tok123');
    expect(postInit.body).toBe(JSON.stringify({ student_id: 4 }));
  });

  it('apiPost reuses the cached token on subsequent calls', async () => {
    const { apiPost, fetchMe } = await freshClient();
    fetchMock
      .mockResolvedValueOnce(
        jsonResponse({ ok: true, data: { user_id: 1, username: 't', role: 'parent', csrf_token: 'tok456' } }),
      )
      .mockResolvedValueOnce(jsonResponse({ ok: true, data: { saved: true } }));

    await fetchMe();
    await apiPost('/parent/profile.php', {});

    expect(fetchMock).toHaveBeenCalledTimes(2); // no second /me.php
    const postInit = fetchMock.mock.calls[1][1] as RequestInit;
    expect((postInit.headers as Record<string, string>)['X-CSRF-Token']).toBe('tok456');
  });
});
