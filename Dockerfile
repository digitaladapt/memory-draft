# Dockerfile — Memory Loom
#
# Base image is pinned (GUIDING-LIGHT §6.4): never `:latest`, because a base
# that moves under a build makes two builds of the same commit differ.
FROM dunglas/frankenphp:1-php8.5-trixie AS base

# ── Build stage ───────────────────────────────────────────────────────────────
FROM base AS build

# Composer from its own pinned image rather than a curl | php, so the version is
# explicit and reproducible.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies first: this layer only rebuilds when the lockfile changes, so
# ordinary source edits do not re-resolve the vendor tree.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist \
        --no-progress --optimize-autoloader

COPY . .

# Compile the production container with an empty environment: the build must not
# depend on, or bake in, any value that comes from the runtime environment
# (§8.12). Real secrets are injected at run time only.
RUN composer dump-env prod --empty \
 && composer run-script auto-scripts --no-interaction \
 && APP_ENV=prod APP_DEBUG=0 php -d memory_limit=-1 bin/console cache:warmup

# ── Runtime stage ─────────────────────────────────────────────────────────────
FROM base AS runtime

# pcov is for coverage in CI only; the runtime image needs sqlite and nothing
# else beyond the base.
RUN apt-get update \
 && apt-get install -y --no-install-recommends sqlite3 \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=build --chown=www-data:www-data /app /app

# The store lives on a volume so memory outlives the container. Creating the
# path here means a fresh volume is writable without a chown step.
RUN mkdir -p /data && chown www-data:www-data /data

# Never run as root (§6.4). The base image already ships www-data for this.
USER www-data

ENV SERVER_NAME=:80 \
    APP_ENV=prod \
    APP_DEBUG=0 \
    MEMORY_DB_PATH=/data/memory.db

VOLUME ["/data"]

EXPOSE 80

# Health endpoint is served by the console command's HTTP kernel.
# `/health` (liveness) and `/ready` (readiness) are split per §8.4.
HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=10s \
    CMD curl -sf http://localhost/health || exit 1
