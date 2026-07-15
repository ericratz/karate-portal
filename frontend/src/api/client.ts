// Typed fetch client for portal/api/v1. Every response uses the
// {"ok":true,"data":...} / {"ok":false,"error":"..."} envelope from
// includes/api.php; this unwraps it or throws ApiError. Auth rides on the
// same PHP session cookie as the rest of the portal, and mutations echo the
// CSRF token (fetched once via /me.php) in the X-CSRF-Token header.

import type { Me } from './types';

const BASE = '/karate/portal/api/v1';
const LOGIN_URL = '/karate/portal/login.php';

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

interface Envelope<T> {
  ok: boolean;
  data?: T;
  error?: string;
}

let csrfToken: string | null = null;

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(BASE + path, { credentials: 'same-origin', ...init });

  if (res.status === 401) {
    // Session expired — bounce to login like the PHP pages do.
    window.location.href = LOGIN_URL;
    throw new ApiError('Not logged in', 401);
  }

  const body = (await res.json()) as Envelope<T>;
  if (!res.ok || !body.ok || body.data === undefined) {
    throw new ApiError(body.error ?? `Request failed (HTTP ${res.status})`, res.status);
  }
  return body.data;
}

export function apiGet<T>(path: string): Promise<T> {
  return request<T>(path);
}

export async function apiPost<T>(path: string, payload: unknown): Promise<T> {
  if (csrfToken === null) {
    await fetchMe();
  }
  return request<T>(path, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken ?? '',
    },
    body: JSON.stringify(payload),
  });
}

/** Session bootstrap — also caches the CSRF token for later apiPost calls. */
export async function fetchMe(): Promise<Me> {
  const me = await apiGet<Me>('/me.php');
  csrfToken = me.csrf_token;
  return me;
}

/** Test-only: forget the cached CSRF token between test cases. */
export function resetCsrfTokenForTests(): void {
  csrfToken = null;
}
