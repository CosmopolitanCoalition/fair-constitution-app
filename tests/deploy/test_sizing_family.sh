#!/usr/bin/env bash
#
# test_sizing_family.sh — THE SIZE-FAMILY GATE for the host-derived memory caps.
#
# Operator order 2026-09-19: "the formulas for derivation need to not have problems", on every
# size, known in advance. This harness runs the REAL get-started.sh --rederive for EVERY host
# size of the two families x EVERY closed profile, in temp dirs with `docker` and `git` stubbed
# (no Docker, no network, no live world), and holds each result to the laws below. It prints the
# metrics table the operator reads. Run it before any change to the sizing code ships:
#
#   bash tests/deploy/test_sizing_family.sh            # the gate (exit 1 on any breach)
#   bash tests/deploy/test_sizing_family.sh --table    # the same, table only
#
# Families:
#   cloud  Azure Dalsv7, 2 GiB a core, deployed public (the TLS edge and the voice SFU run):
#          D2als D4als D8als D16als D32als D48als D64als D96als (measured MemTotal, not nominal)
#   lan    Raspberry Pi / small LAN box, no edge, no voice: 1, 2, 4, 8 GB
#
# Laws (each an assertion):
#   L1 fit        the caps of the services the box runs sum to no more than the budget.
#   L2 needs      when the needs fit the budget, every running service's cap >= its need.
#   L3 positive   no cap is below 8 MB (never zero: Docker reads 0 as "uncapped"; never negative).
#   L4 monotonic  within a family and profile, no service's cap falls as the host grows.
#   L5 honest     a host below the resident minimum says so and names the minimum host;
#                 a host at or above it never prints that warning.
#   L6 lanes      the Horizon cap funds the whole tree of the lanes HostCapacity grants it
#                 (the PHP model is mirrored here; tests/Unit/HostCapacityLanesTest.php pins it).
#
# The needs are re-stated here on purpose (an independent statement of the contract, not a read
# of the script's own variables): postgres 1024, app 128, etl 96, redis cache 256, redis queue
# 226, matrix 160, login service (mas) 128, nginx 32, voice SFU 256, TLS edge 128, scheduler
# 128 + 96 x (background commands + 1), Horizon = master + (supervisors + 2 x supervisors - 1)
# x 64 + 2 x 192.
#
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
TABLE_ONLY=0; [[ "${1:-}" == "--table" ]] && TABLE_ONLY=1

CLOUD="D2als:3916:2 D4als:7937:4 D8als:15988:8 D16als:32090:16 D32als:64300:32 D48als:96500:48 D64als:128700:64 D96als:193000:96"
LAN="Pi-1G:920:4 Pi-2G:1900:4 Pi-4G:3790:4 Pi-8G:7800:4"
PROFILES="geodata mapping serving"

FAILS=0
breach() { echo "  BREACH: $1" >&2; FAILS=$((FAILS + 1)); }
min() { (( $1 < $2 )) && echo "$1" || echo "$2"; }
max() { (( $1 > $2 )) && echo "$1" || echo "$2"; }
ceil_div() { echo $(( ($1 + $2 - 1) / $2 )); }
clampi() { local v=$1; (( v < $2 )) && v=$2; (( v > $3 )) && v=$3; echo "$v"; }

SUPS="$(grep -cE "^        'supervisor-[a-z0-9-]+' => \[" "$REPO/config/horizon.php")"
BG="$(grep -c -- '->runInBackground()' "$REPO/routes/console.php")"
NEED_SCHED=$(( 128 + (BG + 1) * 96 ))

# Pure arithmetic, result in R (no subshell: a fork costs ~40 ms under Git Bash on Windows).
fleet_R() { local aw=$1 d l p
  d=$(( (aw + 2) / 3 )); (( d < 2 )) && d=2
  l=$aw; (( l > 7 )) && l=7; (( l < 2 )) && l=2
  p=$(( (aw + 3) / 4 )); (( p > 4 )) && p=4; (( p < 1 )) && p=1
  R=$(( d + l + aw + aw + aw + p )); }
# hz_need_R <lanes> <master> [lane MB]: the Horizon tree (HostCapacity::horizonNeedMb). The lane
# size defaults to the recycle floor (256); a third lane and after is charged at 400 (laneWorkMb).
hz_need_R() { local aw=$1 master=$2 lane=${3:-256}; fleet_R "$aw"; R=$(( master + (SUPS + R) * 64 + aw * (lane - 64) )); }

run_one() { # family name host_mb ncpu profile -> sets globals
  local family=$1 name=$2 host_mb=$3 ncpu=$4 profile=$5
  ws="$(mktemp -d)"
  cp "$REPO/get-started.sh" "$ws/"; : > "$ws/docker-compose.yml"
  mkdir -p "$ws/.git" "$ws/config" "$ws/routes" "$ws/bin"
  cp "$REPO/config/horizon.php" "$ws/config/"; cp "$REPO/routes/console.php" "$ws/routes/"
  printf 'APP_KEY=x\n' > "$ws/.env.example"
  {
    printf 'APP_KEY=x\nARCHIVE_PATH=/srv/archive\nCGA_MEM_PROFILE=%s\n' "$profile"
    [[ "$family" == cloud ]] && printf 'COMPOSE_FILE=docker-compose.yml:docker-compose.public.yml\n'
  } > "$ws/.env"
  printf '#!/usr/bin/env bash\nexit 0\n' > "$ws/bin/git"
  local maps=0; [[ "$profile" == mapping ]] && maps=1
  cat > "$ws/bin/docker" <<DOCKER
#!/usr/bin/env bash
case "\$*" in
  "info --format {{.MemTotal}}") echo $(( host_mb * 1048576 ));;
  "info --format {{.NCPU}}") echo $ncpu;;
  info*|"compose version"*) exit 0;;
  *psql*) [ "$maps" = 1 ] && { echo 1; exit 0; }; exit 1;;
  *) exit 1;;
esac
DOCKER
  chmod +x "$ws/bin/"*
  ( cd "$ws" && PATH="$ws/bin:$PATH" bash get-started.sh --rederive > out.log 2>&1 < /dev/null ); RC=$?
}
val() { grep -E "^$1=" "$ws/.env" | tail -1 | cut -d= -f2 | tr -d 'mMB'; }

[[ "$TABLE_ONLY" == 1 ]] || echo "== the size-family gate: $(echo $CLOUD $LAN | wc -w | tr -d ' ') sizes x 3 profiles, supervisors=$SUPS, background commands=$BG =="
printf '%-6s %-7s %-8s %7s | %6s %6s %6s %6s %6s %5s %5s %5s %4s %4s %4s %4s | %5s %6s | %s\n' \
  family size profile budget postgr horizn app etl sched rcach rqueu matrx mas ngnx sfu edge lanes recycl regime

for family in cloud lan; do
  [[ "$family" == cloud ]] && SIZES="$CLOUD" || SIZES="$LAN"
  for profile in $PROFILES; do
    prev=""   # the previous (smaller) host's caps, for L4
    for spec in $SIZES; do
      IFS=: read -r name host_mb ncpu <<<"$spec"
      run_one "$family" "$name" "$host_mb" "$ncpu" "$profile"
      tag="$family/$name/$profile"
      [[ "$RC" == 0 ]] || breach "$tag: get-started exited $RC"
      budget=$(( host_mb * 80 / 100 ))
      pg=$(val POSTGRES_MEM_LIMIT); hz=$(val MEM_HORIZON); app=$(val MEM_APP); etl=$(val ETL_MEM_LIMIT)
      rc=$(val MEM_REDIS_CACHE); rq=$(val MEM_REDIS_QUEUE); sch=$(val MEM_SCHEDULER); mtx=$(val MEM_MATRIX)
      mas=$(val MEM_MAS); ngx=$(val MEM_NGINX); lk=$(val MEM_LIVEKIT); edge=$(val MEM_EDGE); conns=$(val PG_MAX_CONNECTIONS)
      master=$(clampi $(( host_mb * 16 / 1024 )) 64 256)
      hz_need_R 2 "$master"; need_hz=$R

      # the services this box runs, their caps and needs
      names=(postgres horizon app etl scheduler rcache rqueue matrix mas nginx)
      caps=("$pg" "$hz" "$app" "$etl" "$sch" "$rc" "$rq" "$mtx" "$mas" "$ngx")
      needs=(1024 "$need_hz" 128 96 "$NEED_SCHED" 256 226 160 128 32)
      if [[ "$family" == cloud ]]; then names+=(sfu edge); caps+=("$lk" "$edge"); needs+=(256 128); fi
      sum=0; sum_need=0
      for i in "${!caps[@]}"; do sum=$(( sum + caps[i] )); sum_need=$(( sum_need + needs[i] )); done

      # L1, L3
      (( sum <= budget )) || breach "$tag: L1 caps ${sum}m exceed the budget ${budget}m"
      for i in "${!caps[@]}"; do (( caps[i] >= 8 )) || breach "$tag: L3 ${names[i]} cap is ${caps[i]}m"; done
      # L2, L5
      if (( sum_need <= budget )); then
        regime="needs held"
        for i in "${!caps[@]}"; do (( caps[i] >= needs[i] )) || breach "$tag: L2 ${names[i]} ${caps[i]}m is below its need ${needs[i]}m"; done
        grep -q "BELOW THE RESIDENT MINIMUM" "$ws/out.log" && breach "$tag: L5 a host that fits was warned"
        grep -q "every service keeps its need" "$ws/out.log" || regime="full shares"
      else
        regime="BELOW MINIMUM (needs ${sum_need}m)"
        grep -q "BELOW THE RESIDENT MINIMUM" "$ws/out.log" || breach "$tag: L5 a host below its minimum was not warned"
        grep -q "Minimum host for this set" "$ws/out.log" || breach "$tag: L5 the minimum host is not named"
      fi
      # L4
      cur="${caps[*]}"
      if [[ -n "$prev" ]]; then
        read -r -a p <<<"$prev"
        for i in "${!caps[@]}"; do (( caps[i] >= p[i] )) || breach "$tag: L4 ${names[i]} fell from ${p[i]}m to ${caps[i]}m as the host grew"; done
      fi
      prev="$cur"
      # L6: the lanes HostCapacity grants (its model, mirrored) and the recycle bound they get
      aw=2
      while (( aw < 512 )); do hz_need_R $(( aw + 1 )) "$master" 400; (( R <= hz )) || break; aw=$(( aw + 1 )); done
      conn_cap=$(( (conns - 30) / 3 )); (( conn_cap < 4 )) && conn_cap=4
      core_lanes=$(( (ncpu * 100 - 50) / 86 ))
      lanes=$aw; (( core_lanes < lanes )) && lanes=$core_lanes; (( conn_cap < lanes )) && lanes=$conn_cap; (( lanes < 2 )) && lanes=2
      fleet_R "$lanes"; room=$(( hz - master - (SUPS + R) * 64 )); (( room < 0 )) && room=0
      heavy=$(( host_mb * 64 / 1024 )); (( heavy < 512 )) && heavy=512; (( heavy > 2048 )) && heavy=2048
      recycle=$(( 64 + room / lanes )); (( recycle < 256 )) && recycle=256; (( recycle > heavy )) && recycle=$heavy
      if (( sum_need <= budget )); then
        lane_mb=256; (( lanes > 2 )) && lane_mb=400
        hz_need_R "$lanes" "$master" "$lane_mb"
        (( R <= hz )) || breach "$tag: L6 ${lanes} lanes need ${R}m, the cap is ${hz}m"
      fi

      if [[ "$family" == cloud ]]; then sfu="$lk"; ed="$edge"; else sfu="-"; ed="-"; fi
      printf '%-6s %-7s %-8s %7s | %6s %6s %6s %6s %6s %5s %5s %5s %4s %4s %4s %4s | %5s %6s | %s\n' \
        "$family" "$name" "$profile" "$budget" "$pg" "$hz" "$app" "$etl" "$sch" "$rc" "$rq" "$mtx" "$mas" "$ngx" "$sfu" "$ed" "$lanes" "$recycle" "$regime"
      rm -rf "$ws"
    done
  done
done

echo ""
if [[ "$FAILS" -eq 0 ]]; then
  echo "THE SIZE FAMILY HOLDS EVERY LAW"
  exit 0
else
  echo "$FAILS BREACH(ES)"
  exit 1
fi
