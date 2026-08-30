# Resource Derivation Audit (2026-08-29)

Lane 2, ordered by the operator 2026-08-29. Every place the codebase assigns memory,
cores, workers, connections, or sizing was located and classified. Sorted by status
(operator order, same day): the issue first, the already-derived last. Reads only;
no fixes applied yet.

## IMPLEMENTATION STATUS (2026-08-30, operator order "implement the changes that remain")

DONE — live in code or applied at recreate: the re-derive mechanism
(DERIVED_KEYS ledger + --rederive/-Rederive, both installers), redis
queue/cache split with derived sizing, autovacuum family, pg parallel
posture (env-driven compose), per-lane session work_mem, scan-clamp
derivation, php memory_limit 6G, max_connections derivation, install-formula
ceilings lifted (pg cap/effective cache/maintenance/max_wal), min_wal +
wal_buffers + shm_size derived, horizon master memory derived, supervisor-1
share-of-lanes, worker recycle thresholds derived (proven values as
floors), long-running width reads count(GeodataFlag::CATEGORIES),
supervisor shared budget enforced at sim's seeding plane (dynamic, yields
to live autoscale lanes), prewarm tile write race-fixed (temp+rename) and
width derived, singles workers + subtree batch + purge chunks derived,
etl pool wait-aware + connection-budget, download courtesy cap named,
busy-factor re-measure command (scripts/measure-busy-factor.sh),
HostCapacity docblock rewritten.

KEPT DELIBERATELY (the constraint IS the formula): shared_buffers ≤ 512MB
(the headroom law), global work_mem ≤ 64MB (lanes raise their own
sessions), provisioning param chunk 65,535 (protocol maximum).

DEFERRED with reason — needs a generated-include design (php ini and
fpm/nginx conf formats cannot consume env vars; a committed-with-defaults
generated file would leave every box's git tree dirty): php-fpm children,
opcache sizing, nginx worker_connections, synapse/mas/livekit knobs. None
gate burst-box throughput; revisit with the cloud image build.

Status vocabulary:
- **HARD**: plain hard-coded number. The issue.
- **INSTALL-DERIVED**: computed from the host ONCE by `get-started.sh
  configure_host_memory()` and frozen into `.env` (write-if-absent). Correct on the
  install-day host, stale after a resize, and every formula carries a small-box
  ceiling.
- **DERIVED + CAP**: a live derivation wearing a hard ceiling.
- **DERIVED**: computed from the live host at runtime. No issue.

## 1. HARD (17 items)

| Setting | Where | Value today | Note |
|---|---|---|---|
| max_connections | docker-compose.yml:200 | 200 | caps every lane everywhere at 56 |
| pg parallel workers (4 knobs) | docker-compose.yml:192-198 | 8 / 8 / 4 / 4 | |
| min_wal_size, wal_buffers | docker-compose.yml:182,186 | 1GB, 64MB | |
| postgres shm_size | docker-compose.yml:122 | 1gb | sized for work_mem × parallel workers |
| redis maxmemory | docker-compose.yml:343 | 768mb allkeys-lru | |
| php memory_limit | docker/php/php-local.ini:12 | 8G | |
| php-fpm pm.max_children (+spares) | docker/php/zz-pool.conf:25-28 | 16 / 4 / 2 / 8 | |
| nginx worker_connections | image nginx.conf:10 (read live) | 1024 | worker_processes itself is auto |
| synapse / mas / livekit tuning | none in repo | image defaults | |
| supervisor-1 (default queue) | config/horizon.php:309,338 | 10 production / 3 local | |
| supervisor-prewarm | config/horizon.php:296,332 | 1 | shared-disk write race is the stated reason |
| per-worker memory recycle | config/horizon.php:208,243,262,286,299 | 128 / 512 MB | leak-recycle threshold |
| singles-batch workers | config/cga.php:64 | 4 (env dial) | |
| subtree activation batch | config/cga.php:32 | 500 rows (env dial) | keyset walk, memory-flat |
| fresh-purge delete chunks | SetupController.php:1431,1438 | 50,000 / 20,000 rows | bounded units |
| provisioning param chunk | SetupController.php:3110 | 65,535 | postgres wire-protocol maximum |
| scan detector session clamps | Geodata/GeodataFlagService.php:92-94 | work_mem 32MB, hash 1.0, gather 0 | caps down, protective |

## 2. INSTALL-DERIVED (6 items, all at get-started.sh:222-228)

Frozen at first install; a resized box keeps day-one numbers. First wall on the
cloud burst: a 256 GB machine gets a 16 GB postgres.

| Setting | Formula at install | Frozen ceiling |
|---|---|---|
| POSTGRES_MEM_LIMIT | 60% of host | 16 GB |
| PG_SHARED_BUFFERS | pg/9 | 512 MB (the 2026-08-02 headroom comment records why it is small: (cap − shared_buffers) must fund the largest single-feature insert transient) |
| PG_EFFECTIVE_CACHE | 80% of pg | 13 GB |
| PG_WORK_MEM | pg/72 | 64 MB |
| PG_MAINTENANCE_MEM | pg/9 | 512 MB |
| PG_MAX_WAL | 160% of pg | 16 GB |

Compose fallbacks when `.env` is silent: 5g / 512MB / 4GB / 64MB / 512MB / 8GB
(docker-compose.yml:135,172-180).

## 3. DERIVED + CAP (4 items)

| Setting | Where | Derivation | The cap |
|---|---|---|---|
| etl pool width | scripts/etl/supervisor.py:431-433 | cores − 2, env `CGA_ETL_WORKERS` | 12; missed the 2026-08-29 wait-aware + connection-budget upgrade the PHP side got |
| download workers | scripts/etl/download_datasets.py:1161 | 2 × cores | 8 |
| long-running supervisor | config/horizon.php:240,318 | min(lanes, …) | 6 = the detector count, so partly work-derived |
| lane formula constants | app/Support/HostCapacity.php:46-47 | busy 0.86, reserve 0.5, env-tunable | measured once on the 12-core box; no re-measure command |

## 4. DERIVED (11 items, no issue)

| Setting | Where |
|---|---|
| worker lanes, the one dial | app/Support/HostCapacity.php:60 ((cores−0.5)/0.86, conn cap (max_conn−30)/3, floor 2) |
| autoscale / sim supervisors and pump target | config/horizon.php:259,283; AutoscalePumpCommand.php:256 |
| heavy-scope concurrent cap | AutoscaleClaims.php:67 (min(20% lanes, (hostGB−3)/2)) |
| top-down and sim shares | AutoscaleClaims.php:93, SimClaims.php:52 (20% of lanes) |
| subtree-boot / ingest-tail / sweep lanes | ActivateSubtreeJob.php:90, IngestTailProvisionJob.php:82, MapSweepCommand.php:98 |
| etl container memory | docker-compose.yml:498 (default 0 = uncapped, live wall) |
| etl memory budget | scripts/etl/memory_budget.py:28 (env → cgroup v2 → v1 → host RAM) |
| etl admission wall | scripts/etl/claims.py:282-291 (MemAvailable + own, per claim) |
| etl chunk profiles | budget-scaled (etl_unit.py:1624 area) |
| app / horizon / nginx / synapse / mas containers | no caps; host governs. nginx worker_processes auto |
| HostCapacity fallbacks | HostCapacity.php:76,91,110 (cores 4, mem 8 GB, conns 100; only when the host is unreadable) |

Tally: 17 HARD + 6 INSTALL-DERIVED + 4 DERIVED+CAP = 27 of 38 need work; 11 already
derive. (Superseded by the addendum below: 44 tracked, 33 need work.)

## Addendum: external review folded in (2026-08-29, desk-verified)

A second AI reviewed this audit against HEAD dc2120f. Every checkable claim was
re-verified against the code before acceptance. Verdict: all corrections real,
one proposed remedy rejected with cause, one risk confirmed LIVE.

Corrections to existing rows:
- Provisioning param chunk is at SetupController.php:3154 in the working tree
  (3110 at the earlier read). Cause identified: the district-drawing session is
  actively editing that file today; both line numbers were correct at their
  timestamps.
- Scan session clamps set a FOURTH value: `jit = off`
  (Geodata/GeodataFlagService.php:96).
- Row 18 addendum: the lane formula also floors the connection cap at 4
  (`max(4, $connCap)`, HostCapacity.php:60). And HostCapacity.php's own
  docblock is stale: the header still documents `clamp(cores − 2, 2, 12)` and
  an inline comment says "cap 16", while the code is the wait-aware +
  connection-budget formula. The file misdescribes itself; agents read that
  header. Fix queued with the derivation build (the file sits next to the
  active district session's work; not edited mid-run).

New rows (44 tracked total):

| Setting | Where | Value | Status |
|---|---|---|---|
| horizon master memory_limit | config/horizon.php:186 | 64 MB | HARD |
| opcache sizing | docker/php/php-local.ini:49-51,30 | 256M, 20,000 files, 16M interned, 4M realpath | HARD |
| postgres autovacuum family | absent from docker-compose.yml | image defaults (3 workers, cost limit 200) | HARD (by omission) — a real knob for day-long write-heavy runs |
| etl resolve pool | scripts/etl/import_geoboundaries.py:1411-1422 | min(pool width, 6), env `CGA_ETL_RESOLVE_WORKERS` | DERIVED + CAP (cap 6, per-task-weight rationale stated in code) |
| redis queue/cache colocation | docker-compose.yml:343 + .env.example:133 + config/database.php:167-186 | queues (redis DB 0) and cache (DB 1) share ONE 768mb allkeys-lru instance; eviction policy is instance-wide | STRUCTURAL RISK — **confirmed live 2026-08-29: evicted_keys 31,612, used 709/768 MB, during the mapping run.** Hot queue keys are LRU-protected in practice; cold job payloads and leases are structurally evictable |
| supervisor double-booking | config/horizon.php:259,283 | supervisor-autoscale and supervisor-sim EACH size to the full host; simultaneous work = 2× lanes on one box | STRUCTURAL — needs a shared budget or a stated sequencing guarantee |

New tally: 20 HARD + 6 INSTALL-DERIVED + 5 DERIVED+CAP + 2 STRUCTURAL = 33 of 44
need work; 11 derive.

Fix-list additions from the review (all accepted):
- Fix HostCapacity.php's stale header and cap-16 comment (queued, see above).
- `.env` marker mechanism for script-written values (`# fc-derived` suffix) so
  re-derivation refreshes derived lines and never touches hand-set ones.
- Session-scoped work_mem RAISES for districting lanes (the inverse of
  clampSessionMemory): `SET work_mem` ≈ (pg_cap − shared_buffers −
  transient_reserve) / lanes / 2 clamped, plus gather = 0. Derived, scoped,
  leaves ingest safety untouched. Owner note: districting lane files are under
  the active district session; coordinate before touching.
- Autovacuum derivation: workers from cores, cost limit scaled, per-table scale
  factors on hot districting tables, log_autovacuum_min_duration=0 during runs.
- Redis split: queue instance with noeviction, cache instance stays
  allkeys-lru; fold the derive-maxmemory-from-host row in, 768mb floor.
  **The reviewer's alternative (volatile-lru on the single instance) is
  REJECTED for this codebase**: the map GeoJSON cache is
  rememberForever/no-TTL (docker-compose.yml:337-340), so under volatile-lru
  nothing is evictable, the instance fills, and ALL writes fail, queue pushes
  included.
- Prewarm: conditional; if a backlog shows, per-key temp file + atomic rename,
  then derive the lane count.
- Shared budget across autoscale + sim supervisors, or a code comment stating
  the sequencing guarantee if one exists.
- Minor derivations: horizon master memory_limit, opcache sizing from host
  memory.

Frame note: the reviewer's deferral list (max_connections, the etl wait-aware
upgrade, download lanes) is correct for the 12-core laptop this week and exactly
inverted for the cloud burst box, where those three are the throughput levers.
Both priority orders stand, each in its own frame.

## The complete fix list (operator order 2026-08-29: nothing excluded)

Every HARD, INSTALL-DERIVED, and DERIVED+CAP row, each with its proposed
derivation. Where the current number encodes a real constraint, the constraint
becomes PART of the formula (a floor, a reserve, a data-driven term). It is never
a reason to leave the number fixed.

| Group | Item | Proposed derivation |
|---|---|---|
| INSTALL | postgres memory cap (60%, ceiling 16 GB, frozen) | re-derive on every deploy/boot; drop the 16 GB ceiling; mark script-written `.env` values so hand-set ones are never clobbered |
| INSTALL | shared_buffers (pg/9, ceiling 512MB) | derive: fraction × cap, bounded so (cap − shared_buffers) always funds the largest single-feature insert transient (a measured, data-driven reserve, not a fixed 512MB) |
| INSTALL | effective_cache_size (ceiling 13 GB) | derive 80% of cap, no ceiling |
| INSTALL | work_mem (ceiling 64MB) | derive from cap and max_connections, transient-reserve aware |
| INSTALL | maintenance_work_mem (ceiling 512MB) | derive from cap |
| INSTALL | max_wal_size (ceiling 16 GB) | derive from cap and disk headroom |
| HARD | max_connections 200 | derive from the postgres memory cap (widens the 56-lane universe) |
| HARD | pg parallel workers 8/8/4/4 | derive from cores |
| HARD | min_wal_size 1GB, wal_buffers 64MB | derive with max_wal / from cap |
| HARD | shm_size 1gb | derive from work_mem × parallel workers + headroom |
| HARD | redis maxmemory 768mb | derive host fraction, current value as floor |
| HARD | php memory_limit 8G | derive from host memory |
| HARD | php-fpm children 16/4/2/8 | derive from cores and memory |
| HARD | nginx worker_connections 1024 | derive from cores / expected fpm children |
| HARD | synapse/mas/livekit image defaults | expose and derive what the images allow (for example synapse cache factor via env) |
| HARD | supervisor-1 10 prod / 3 local | derive as a share of lanes |
| HARD | supervisor-prewarm 1 | make the shared-disk write race-safe and derive, or derivation = min(1, …) with the race named in code |
| HARD | worker recycle 128/512 MB | derive from host memory per lane |
| HARD | singles workers 4 | derive as a share of lanes |
| HARD | subtree batch 500 | derive from the memory budget |
| HARD | purge chunks 50k/20k | derive from memory budget and row width |
| HARD | provisioning param chunk 65,535 | protocol maximum; keep the protocol limit, chunk count derives from it |
| HARD | scan session clamps (32MB) | derive from the postgres budget divided by detector count |
| CAP | etl pool cap 12 | derive via the same wait-aware + connection-budget formula as HostCapacity |
| CAP | download workers cap 8 | derive from cores; any per-source courtesy limit becomes a named constant per source |
| CAP | long-running cap 6 | derive = min(detector count, lanes); detector count read from the code, not typed |
| CAP | busy factor 0.86 / reserve 0.5 | add the re-measure recipe as a command so they re-derive per host instead of riding the 12-core measurement |
| DERIVED | HostCapacity fallbacks 4/8/100 | keep only as unreadable-host floors; they never apply when the host can be measured |
