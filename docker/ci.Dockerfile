# CI-tools container: one image that runs every existing check —
#   npm run typecheck  (tsc)
#   psalm --taint-analysis
#   phpunit
#   npx playwright test
#
# Based on the official Playwright image (Node + Chromium prebuilt),
# version-matched to @playwright/test in package.json so the bundled
# browsers are compatible without an `npx playwright install`.
FROM mcr.microsoft.com/playwright:v1.60.0-noble

# ── PHP 8.4 CLI + Composer + mysql client ──────────────────────────────────
# PHP 8.4 isn't in Ubuntu noble's default repos, so pull it from ondrej/php.
# default-mysql-client provides mysqldump/mysql for the DB snapshot/restore
# in global-setup.js / global-teardown.js.
RUN apt-get update \
 && apt-get install -y --no-install-recommends software-properties-common ca-certificates gnupg \
 && add-apt-repository -y ppa:ondrej/php \
 && apt-get update \
 && apt-get install -y --no-install-recommends \
      php8.4-cli php8.4-mysql php8.4-mbstring php8.4-curl php8.4-zip php8.4-xml \
      default-mysql-client unzip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# PHP deps (incl. dev: Psalm + PHPUnit) — own layer for cache reuse.
# Psalm 5.26.1's composer constraint conservatively caps at PHP 8.3 but runs
# fine on 8.4, so skip the platform check rather than diverge from the lock file.
COPY portal/composer.json portal/composer.lock ./portal/
RUN cd portal && composer install --no-interaction --no-progress --ignore-platform-req=php

# Node deps — own layer. Browsers already live in the base image.
COPY package.json package-lock.json ./
RUN npm ci

# Project source. tests/ and .env are also bind-mounted at run time
# (see docker-compose.yml) so edited specs and DB creds don't need a rebuild.
COPY tsconfig.json playwright.config.js ./
COPY portal/ ./portal/
COPY tests/ ./tests/

# Reach the app service by its Compose DNS name from inside this container.
ENV TEST_BASE_URL=http://app/karate/portal
