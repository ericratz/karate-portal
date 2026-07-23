/// <reference types="vitest/config" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

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
  },
}));
