#!/bin/sh
# Re-measure the lane busy factor on THIS host (audit row, operator order
# 2026-08-30). The 0.86 constant in HostCapacity was measured once on the
# 12-core reference box; a different host re-measures instead of riding it.
#
# Run on the HOST (needs the docker CLI) while the autoscale run is at full
# width. Samples container CPU N times, averages, and prints the .env line:
#
#   busy_factor = (horizon_cpu + postgres_cpu) / lanes / 100
#
# Usage: ./scripts/measure-busy-factor.sh [samples=6] [interval_s=10]

samples="${1:-6}"
interval="${2:-10}"
prefix="${CONTAINER_PREFIX:-fc}"

lanes=$(docker exec "${prefix}_app" php artisan tinker \
    --execute='echo App\Support\HostCapacity::autoscaleWorkers();' 2>/dev/null | tail -1)
case "$lanes" in ''|*[!0-9]*) echo "Could not read the lane count from ${prefix}_app."; exit 1;; esac

echo "Sampling ${samples}x every ${interval}s against ${lanes} lanes..."
total=0
i=0
while [ "$i" -lt "$samples" ]; do
    line=$(docker stats --no-stream --format '{{.Name}} {{.CPUPerc}}' \
        | awk -v p="$prefix" '
            $1 == p"_horizon" || $1 == p"_postgres" { gsub(/%/, "", $2); s += $2 }
            END { print s }')
    total=$(awk -v t="$total" -v l="$line" 'BEGIN { print t + l }')
    i=$((i + 1))
    echo "  sample $i: ${line}% (horizon + postgres)"
    [ "$i" -lt "$samples" ] && sleep "$interval"
done

awk -v t="$total" -v n="$samples" -v l="$lanes" 'BEGIN {
    bf = t / n / l / 100
    printf "\nMeasured busy factor: %.2f\n", bf
    if (bf < 0.3) {
        print "Caution: lanes look mostly idle - measure while the run is at full width."
    }
    printf "Pin it in .env with:\n  CGA_AUTOSCALE_BUSY_FACTOR=%.2f\n", bf
    print "Then restart horizon so new workers derive with it."
}'
