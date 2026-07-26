/// <reference types="vitest/config" />
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// The version is owned by the PHP side (portal/includes/version.php) so there
// is exactly one place to bump it at release time. Parsing it here compiles the
// same number into the bundle, which is what lets a running SPA notice it is
// talking to a different deploy than it was built against — the half-uploaded
// deploy that blanked the attendance page. A build that cannot read the version
// fails rather than shipping an "unknown" that would defeat the check.
function appVersion(): string {
  const file = fileURLToPath(new URL('../portal/includes/version.php', import.meta.url));
  const match = /define\('APP_VERSION',\s*'([^']+)'\)/.exec(readFileSync(file, 'utf8'));
  if (!match) throw new Error('APP_VERSION not found in portal/includes/version.php');
  return match[1];
}

// Two modes:
//
// dev   — `npm run dev` serves the SPA on :5173 and proxies every
//         /karate/portal request to the app (Docker publishes it on
//         localhost:80, native XAMPP answers at the same URL). Log in at
//         http://localhost/karate/portal first — the session cookie is
//         host-scoped, so it flows to :5173 automatically.
//
// build — hashed bundles + manifest.json land in portal/parent/dist/, and
//         the PHP shell (portal/parent/app.php) reads the manifest to emit
//         the script/css tags. The absolute base keeps chunk/asset URLs
//         correct no matter what page URL the shell is served from.
export default defineConfig(({ command }) => ({
  plugins: [react()],
  base: command === 'build' ? '/karate/portal/parent/dist/' : '/',
  define: { __APP_VERSION__: JSON.stringify(appVersion()) },
  build: {
    outDir: '../portal/parent/dist',
    emptyOutDir: true,
    manifest: true,
  },
  server: {
    proxy: {
      // Must match the host you log in on: the session cookie is host-scoped,
      // so a cookie set for `karate.test` is not sent to `localhost:5173`. Open
      // the dev server at http://karate.test:5173 (cookies ignore port).
      '/karate/portal': {
        target: 'http://karate.test',
        changeOrigin: false,
      },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: './src/test/setup.ts',
    coverage: {
      provider: 'v8',
      reporter: ['text', 'html'],
      include: ['src/**/*.{ts,tsx}'],
      // Entry point, test scaffolding, and the generated env typings carry no
      // testable logic — excluding them keeps the percentage honest.
      exclude: ['src/**/*.test.{ts,tsx}', 'src/test/**', 'src/main.tsx', 'src/**/*.d.ts'],
    },
  },
}));
