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

        # Serialize across app/horizon/scheduler: all three containers run this
        # entrypoint AND share the vendor named volume, and a FRESH volume boots
        # them simultaneously — three concurrent `composer install`s into one
        # tree corrupt each other's extractions (vanishing temp zips, partial
        # package dirs → "does not appear to be a file nor a folder"). One
        # installer at a time; the others block here, then re-check the stamp
        # under the lock and find the work already done.
        if command -v flock >/dev/null 2>&1; then
            exec 9>"vendor/.install.lock"
            flock 9
        fi

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
            # Retry in-process: first-boot installs over Docker Desktop's volume
            # layer can fail transiently mid-extraction. Composer resumes cleanly
            # from a partial tree, and retrying here beats dying under set -e —
            # a container exit makes `docker compose up` declare the dependency
            # failed and abandon nginx, even though a restart would have healed.
            ok=0
            for attempt in 1 2 3; do
                if composer install --no-interaction --prefer-dist --no-progress; then
                    ok=1
                    break
                fi
                echo "[entrypoint] composer install attempt ${attempt}/3 failed — retrying in 10s" >&2
                sleep 10
            done
            if [ "$ok" != "1" ]; then
                echo "[entrypoint] composer install failed after 3 attempts — exiting for a container restart" >&2
                exit 1
            fi
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
# root.
#
# PROBE before chowning: only re-own when www-data genuinely CAN'T write. A
# `chown -R` over a large storage tree (a long-lived dev box accumulates ~500k
# cached tiles) on a Docker Desktop bind mount is pathologically slow and —
# running before `exec` — STALLS php-fpm startup for many minutes → nginx 502.
# On Docker Desktop www-data can already write, so the probe passes and we skip
# the chown entirely (fast boot); on a fresh native-Linux deploy the probe fails
# and the chown runs once over a small fresh tree.
if ! su -s /bin/sh www-data -c 'test -w /var/www/html/storage/logs && test -w /var/www/html/storage/framework && test -w /var/www/html/bootstrap/cache' 2>/dev/null; then
    echo "[entrypoint] storage not writable by www-data — chowning (one-time, native-Linux deploy)" >&2
    chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
fi

# ── ETL control dir writability (same native-Linux bind-mount class) ──────
# The setup wizard's Map Data step writes scripts/etl/control/request.json for the etl
# supervisor to pick up. That dir is gitignored (runtime-only), so on a fresh clone it
# doesn't exist; on a native-Linux/Pi bind mount the parent is host-owned, so the www-data
# FPM worker can neither create nor write it → the wizard 500s ("Could not create ETL
# control directory"). Ensure it exists and is group-writable by www-data (the etl
# container shares the same dir at /etl/control). Idempotent; cheap (one small dir).
mkdir -p /var/www/html/scripts/etl/control 2>/dev/null || true
chgrp -R www-data /var/www/html/scripts/etl/control 2>/dev/null || true
chmod -R 0775     /var/www/html/scripts/etl/control 2>/dev/null || true

# .env must be readable AND writable by the FPM user. On a host with a
# restrictive umask (e.g. 077) deploy.sh's `cp .env.example .env` yields mode
# 600 owned by the host uid, so www-data can't read it → MissingAppKeyException
# + a silent fallback to the sqlite default → 500 on every web route. (Latent
# until the worker restart forces FPM to re-read .env on a fresh deploy.)
# GROUP-WRITE (660) it for www-data — the setup wizard SELF-CONFIGURES .env
# (operator self-URL, ARCHIVE_PATH / PROTOMAPS_DIR for the map folder) from the
# UI, and the php-fpm pool runs as www-data, so a read-only .env made those
# saves fail with "Could not write .env: permission". Never world-readable — it
# holds the APP_KEY + DB credentials.
if [ -f /var/www/html/.env ]; then
    chgrp www-data /var/www/html/.env 2>/dev/null || true
    chmod 660      /var/www/html/.env 2>/dev/null || true
fi

# Hand off to the upstream php image's entrypoint, which exec's CMD.
exec docker-php-entrypoint "$@"
