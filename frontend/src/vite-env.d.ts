/// <reference types="vite/client" />

// Compiled in by vite.config.ts from portal/includes/version.php — the version
// this bundle was built against. Compared at runtime with the app_version that
// api/v1/me.php reports, to surface a partial deploy.
declare const __APP_VERSION__: string;
