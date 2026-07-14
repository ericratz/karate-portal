# Application container: Apache + PHP 8.4 serving the portal.
# PHP 8.4 matches the live production stack (CHANGELOG: "PHP upgraded to 8.4"),
# standardizing out the 8.2/8.4 drift between this dev box and production.
FROM php:8.4-apache

# ── PHP extensions the app's Composer deps need ────────────────────────────
#   pdo_mysql — db.php's PDO connection
#   zip       — google/apiclient
#   curl/mbstring/openssl are already bundled in the official php:8.4 image
#   curl (CLI) — for the container HEALTHCHECK so CI can wait on readiness
RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev unzip curl \
 && docker-php-ext-install pdo_mysql zip \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# Block web access to dotfiles (.env under DocumentRoot would otherwise leak).
COPY docker/apache-hardening.conf /etc/apache2/conf-enabled/zz-hardening.conf

# ── Composer (copied from the official image, no bootstrap script) ─────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The app is served at /karate/portal/... to match every hardcoded BASE URL
# in the test suite and .env's SITE_URL. DocumentRoot stays the image default
# (/var/www/html); the app lives one level down under karate/.
WORKDIR /var/www/html/karate

# Install PHP deps first (own layer) so source edits don't bust the cache.
# --no-dev: Psalm/PHPUnit are dev-only and run from the ci image instead.
COPY portal/composer.json portal/composer.lock ./portal/
RUN cd portal && composer install --no-dev --no-interaction --no-progress --optimize-autoloader

# App source. tests/ is copied too so the suite's clear_rate_limit.php helper
# is reachable at /karate/tests/clear_rate_limit.php (global-setup pings it).
# No chown needed: Apache (www-data) only reads these, and COPY leaves them
# world-readable. The app never writes into its own web root (verified), so a
# recursive chown here would just add ~6 min per build on Docker Desktop for
# nothing. If a writable dir is ever added, chown only that path.
COPY portal/ ./portal/
COPY tests/ ./tests/

EXPOSE 80
