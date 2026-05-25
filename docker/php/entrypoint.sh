#!/bin/bash
# OPcache pre-warm entrypoint.
#
# After `docker compose restart app`, the operator's browser typically hits
# the app ~5-15 seconds later (the time to alt-tab + reload the page). The
# first request that lands on a fresh PHP-FPM process pays a ~5-15 second
# OPcache + framework-parse cost. That cost is what drives the post-restart
# "page hangs forever" symptom: by the time the page-side polling kicks in,
# the framework hasn't compiled yet, polls stack up, and the small worker
# pool saturates.
#
# This entrypoint fires a single curl in the background as soon as nginx is
# reachable. The request travels: curl -> nginx -> PHP-FPM (us) -> Laravel
# boot -> OPcache fills. By the time the operator's browser arrives, the
# framework files are already compiled in shared memory and the response is
# fast on the first hit.
#
# Used by `app`, `horizon`, and `vite` containers (they share this Dockerfile).
# Only `app` actually benefits from the pre-warm; the curl is harmless in the
# other contexts (it just hits nginx which has nothing to do with horizon/vite).

set -e

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

# Hand off to the upstream php image's entrypoint, which exec's CMD.
exec docker-php-entrypoint "$@"
