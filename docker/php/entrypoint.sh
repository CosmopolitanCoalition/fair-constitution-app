#!/bin/bash
# App-container entrypoint: composer auto-install + OPcache pre-warm.
#
# === 1. Composer auto-install on composer.json/composer.lock drift ===
# After `git pull` lands new PHP dependencies, the named `vendor` volume
# still holds the previous install. PHP-FPM boots, Laravel autoloader can't
# resolve the new class, every request 500s. The operator has to know to run
# `docker compose exec app composer install` manually.
#
# We check a sha256 of composer.json + composer.lock against a stamp inside
# the vendor named volume. Match → skip install (no boot regression).
# Mismatch (or stamp missing) → install before continuing.
#
# === 2. OPcache pre-warm via background nginx curl ===
# After `docker compose restart app`, the operator's browser typically hits
# the app ~5-15 seconds later (the time to alt-tab + reload the page). The
# first request that lands on a fresh PHP-FPM process pays a ~5-15 second
# OPcache + framework-parse cost. That cost is what drives the post-restart
# "page hangs forever" symptom: by the time the page-side polling kicks in,
# the framework hasn't compiled yet, polls stack up, and the small worker
# pool saturates.
#
# A single curl fires in the background as soon as nginx is reachable. The
# request travels: curl -> nginx -> PHP-FPM (us) -> Laravel boot -> OPcache
# fills. By the time the operator's browser arrives, the framework files are
# already compiled in shared memory and the response is fast on the first hit.
#
# === Container scope ===
# This entrypoint is for `app` and `horizon` (they share this Dockerfile
# and both have a vendor named volume). The `vite` container has its own
# entrypoint at docker/vite/entrypoint.sh — that one handles npm install
# the analogous way.

set -e

# ── Composer auto-install ─────────────────────────────────────────────────
if [ -f /var/www/html/composer.json ]; then
    (
        cd /var/www/html
        STAMP="vendor/.installed-hash"
        WANT="$(cat composer.json composer.lock 2>/dev/null | sha256sum | cut -d' ' -f1)"
        HAVE="$([ -f "$STAMP" ] && cat "$STAMP" || echo '')"
        # Mirror the vite entrypoint's paranoia probe: if the volume is corrupted
        # such that the autoloader file is missing, force a reinstall even if the
        # hash happens to match an older state.
        NEED=0
        [ "$HAVE" != "$WANT" ] && NEED=1
        [ ! -f "vendor/autoload.php" ] && NEED=1

        if [ "$NEED" = "1" ]; then
            echo "[entrypoint] composer.json drift — running composer install" >&2
            echo "[entrypoint]   stamped: ${HAVE:-<missing>}" >&2
            echo "[entrypoint]   desired: $WANT" >&2
            composer install --no-interaction --prefer-dist --no-progress
            echo "$WANT" > "$STAMP"
            echo "[entrypoint] composer install complete — stamp updated" >&2
        else
            echo "[entrypoint] vendor up to date (hash $WANT) — skipping composer install" >&2
        fi
    )
fi

(
    # Wait for nginx to be reachable AND to successfully proxy a request to
    # PHP-FPM. nginx returns 502 if PHP-FPM isn't ready yet, so a successful
    # GET / means the whole nginx -> app -> Laravel stack is online.
    #
    # Timeout: 60 attempts * 1s sleep = up to 60s. Curl itself waits up to
    # 30s per attempt for the response.
    for i in $(seq 1 60); do
        if curl -fsS -o /dev/null --max-time 30 http://nginx/ 2>/dev/null; then
            echo "[entrypoint] OPcache pre-warm: GET http://nginx/ succeeded after ${i}s" >&2
            break
        fi
        sleep 1
    done
) &

# ── 3. Boot-time cache prewarm dispatch (horizon container only) ───────────
# The HORIZON container dispatches the raster + GeoJSON warm jobs so that app +
# horizon booting together don't double-queue. The jobs run on Horizon's
# `long-running` supervisor; live tile/GeoJSON requests are still served
# synchronously by PHP-FPM (generate-on-miss), so whatever the operator is
# looking at always preempts this background work.
#
# Both jobs are idempotent: rasters:prewarm skips every already-cached tile via
# the file-exists fast-path, and the GeoJSON caches are rememberForever — so a
# normal restart re-dispatch is cheap. They also self-guard on empty data
# (rasters:prewarm → empty land mask → quick exit; geojson:prewarm → "no
# legislatures" → exit), so a fresh instance still in setup warms nothing. This
# mirrors the documented "just re-dispatch on restart" recovery recipe.
case "$*" in
  *horizon*)
    (
        # Wait until the app serves a request (implies Postgres + Redis online)
        # before dispatching — artisan needs the DB to enumerate scopes.
        for i in $(seq 1 90); do
            curl -fsS -o /dev/null --max-time 30 http://nginx/ 2>/dev/null && break
            sleep 1
        done
        cd /var/www/html
        echo "[entrypoint] dispatching raster (z0-12) + geojson prewarm to Horizon" >&2
        php artisan rasters:prewarm --min-zoom=0 --max-zoom=12 --land-only --queue 2>/dev/null || true
        php artisan geojson:prewarm --queue 2>/dev/null || true
    ) &
    ;;
esac

# ── Storage / cache writability on native-Linux bind mounts ───────────────
# The `.:/var/www/html` bind mount preserves the HOST uid (e.g. 1000) on a
# native-Linux host, so php-fpm's worker user (www-data, uid 33) can't write
# storage/ (Blade-compiled views, framework cache, logs) or bootstrap/cache.
# First render then 500s — Blade can't write the compiled view, and Laravel
# can't even write the error to storage/logs/laravel.log ("could not be opened
# in append mode"). The composer install above also writes bootstrap/cache as
# root. Re-own both for the FPM user on every boot. Guarded + a harmless no-op
# on Docker Desktop (Win/macOS) mounts, which already present files as writable.
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# .env must also be readable by the FPM user. On a host with a restrictive
# umask (e.g. 077) deploy.sh's `cp .env.example .env` yields mode 600 owned by
# the host uid, so www-data can't read it → MissingAppKeyException + a silent
# fallback to the sqlite default → 500 on every web route. (Latent until the
# worker restart forces FPM to re-read .env on a fresh deploy.) Group-read it
# for www-data while keeping the host uid as owner+writer, so deploy.sh's
# idempotent set_env can still edit it; never world-readable — it holds the
# APP_KEY + DB credentials.
if [ -f /var/www/html/.env ]; then
    chgrp www-data /var/www/html/.env 2>/dev/null || true
    chmod 640      /var/www/html/.env 2>/dev/null || true
fi

# Hand off to the upstream php image's entrypoint, which exec's CMD.
exec docker-php-entrypoint "$@"
