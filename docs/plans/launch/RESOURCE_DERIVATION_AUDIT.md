# Resource Derivation Audit (2026-08-29)

Lane 2, ordered by the operator 2026-08-29. Every place the codebase assigns memory,
cores, workers, connections, or sizing was located and classified. Reads only; no
fixes applied yet.

Status vocabulary:
- **DERIVED**: computed from the live host at runtime.
- **INSTALL-DERIVED**: computed from the host ONCE by `get-started.sh
  configure_host_memory()` and frozen into `.env` (write-if-absent). Correct on the
  install-day host, stale after a resize.
- **HARD**: plain hard-coded number.
- **HARD (design)** / **HARD (protocol)**: hard for a stated lawful reason, not a
  resource guess.

## The table

| # | Setting | Where | Value today | Status |
|---|---|---|---|---|
| 1 | postgres container memory cap | docker-compose.yml:135 + get-started.sh:222 | env `POSTGRES_MEM_LIMIT`, install writes 60% of host, **clamp 1 to 16 GB**; compose fallback 5g | INSTALL-DERIVED (16 GB ceiling) |
| 2 | postgres shm_size | docker-compose.yml:122 | 1gb | HARD |
| 3 | shared_buffers | docker-compose.yml:172 + get-started.sh:224 | install pg/9, **ceiling 512MB**; fallback 512MB | INSTALL-DERIVED, ceiling deliberate (headroom law 2026-08-02) |
| 4 | effective_cache_size | docker-compose.yml:174 | install 80% of pg, **ceiling 13 GB**; fallback 4GB | INSTALL-DERIVED |
| 5 | work_mem | docker-compose.yml:176 | install pg/72, **ceiling 64MB**; fallback 64MB | INSTALL-DERIVED |
| 6 | maintenance_work_mem | docker-compose.yml:178 | install pg/9, **ceiling 512MB** | INSTALL-DERIVED |
| 7 | max_wal_size | docker-compose.yml:180 | install 160% of pg, **ceiling 16 GB**; fallback 8GB | INSTALL-DERIVED |
| 8 | min_wal_size, wal_buffers | docker-compose.yml:182,186 | 1GB, 64MB | HARD |
| 9 | pg parallel workers (4 knobs) | docker-compose.yml:192-198 | 8 / 8 / 4 / 4 | HARD |
| 10 | **max_connections** | docker-compose.yml:200 | **200** | **HARD** (governs the 56-lane universe) |
| 11 | redis maxmemory | docker-compose.yml:343 | 768mb, allkeys-lru | HARD |
| 12 | etl container memory | docker-compose.yml:498 | env `ETL_MEM_LIMIT`, default 0 = uncapped, live wall | DERIVED |
| 13 | app / horizon / nginx / synapse / mas caps | docker-compose.yml | none (host governs) | DERIVED by absence |
| 14 | php memory_limit | docker/php/php-local.ini:12 | 8G | HARD |
| 15 | php-fpm pm.max_children (+spares) | docker/php/zz-pool.conf:25-28 | 16 / 4 / 2 / 8 | HARD |
| 16 | nginx worker_processes / connections | image nginx.conf:3,10 (read live from fc_nginx) | auto / 1024 | DERIVED (auto) / HARD (1024) |
| 17 | synapse, mas, livekit tuning | none in repo | image defaults | HARD (image) |
| 18 | **worker lanes (the one dial)** | app/Support/HostCapacity.php:60 | (cores−0.5)/0.86, conn cap (max_conn−30)/3, floor 2, cap by conn budget; env dials | DERIVED (constants measured on the planet run) |
| 19 | autoscale / sim / long-running supervisors | config/horizon.php:259,283,318 | = lanes (long-running capped 6 by detector design) | DERIVED |
| 20 | supervisor-1 (default queue) | config/horizon.php:309,338 | 10 production / 3 local | HARD |
| 21 | supervisor-prewarm | config/horizon.php:296,332 | 1 | HARD (design: single shared-disk writer) |
| 22 | per-worker memory recycle | config/horizon.php:208,243,262,286,299 | 128 / 512 MB | HARD (leak-recycle threshold, not capacity) |
| 23 | heavy-scope concurrent cap | app/Support/AutoscaleClaims.php:67 | min(20% lanes, (hostGB−3)/2) | DERIVED |
| 24 | top-down lane share | app/Support/AutoscaleClaims.php:93 | 20% of lanes | DERIVED (ratio is the 2026-07-22 ruling) |
| 25 | sim heavy share | app/Support/SimClaims.php:52 | 20% of lanes | DERIVED |
| 26 | subtree-boot / ingest-tail / sweep lanes | ActivateSubtreeJob.php:90, IngestTailProvisionJob.php:82, MapSweepCommand.php:98 | min(lanes, open work) | DERIVED |
| 27 | pump worker target | AutoscalePumpCommand.php:256 | = lanes | DERIVED |
| 28 | singles-batch workers | config/cga.php:64 | 4 (env dial) | HARD default |
| 29 | subtree activation batch | config/cga.php:32 | 500 rows (env dial; keyset walk, memory-flat) | HARD default (bounded unit) |
| 30 | fresh-purge delete chunks | SetupController.php:1431,1438 | 50,000 / 20,000 rows | HARD (bounded unit) |
| 31 | provisioning chunking | SetupController.php:3110 | 65,535 pg params | HARD (protocol) |
| 32 | scan detector session clamps | Geodata/GeodataFlagService.php:92-94 | work_mem 32MB, hash 1.0, gather 0 | HARD (protective, caps down) |
| 33 | **etl pool width** | scripts/etl/supervisor.py:431-433 | max(2, min(**12**, cores−2)), env `CGA_ETL_WORKERS` | DERIVED with HARD CAP 12 |
| 34 | etl memory budget | scripts/etl/memory_budget.py:28 | env, else cgroup v2, else v1, else host RAM | DERIVED |
| 35 | etl admission wall | scripts/etl/claims.py:282-291 | MemAvailable + own usage, read per claim | DERIVED (live) |
| 36 | etl chunk profiles | scripts/etl/etl_unit.py:1624 area | budget-scaled | DERIVED |
| 37 | download workers | scripts/etl/download_datasets.py:1161 | max(2, min(**8**, 2×cores)) | DERIVED with HARD CAP 8 |
| 38 | HostCapacity fallbacks | HostCapacity.php:76,91,110 | cores 4, mem 8 GB, conns 100 when unreadable | DERIVED (safe floors) |

## Violations of the derive law (fix candidates, ranked by cloud impact)

1. **The install-derived postgres block freezes at first boot and its ceilings are
   small-box sized** (rows 1, 4, 5, 6, 7): 16 GB postgres cap, 13 GB cache, 64 MB
   work_mem, on a 128 to 256 GB burst box. Write-if-absent means a resize never
   re-derives. Needs a re-derive step that runs on boot or on demand and still
   never clobbers a hand-set value (requires marking script-written values, for
   example a `# host-derived` suffix comment in `.env`).
2. **max_connections 200** (row 10): plain hard, and it is the number that caps
   every lane everywhere at 56. Should derive from the postgres memory cap.
3. **etl pool width cap 12** (row 33): the PHP side got the wait-aware plus
   connection-budget upgrade 2026-08-29; the python pool kept the old cores−2
   cap-12 shape. Same generalization applies.
4. **pg parallel workers 8/8/4/4** (row 9): derive from cores.
5. **redis maxmemory 768mb** (row 11): derive as a host fraction with the current
   value as the floor.
6. **php memory_limit 8G, fpm children 16** (rows 14, 15): derive from host memory
   and cores.
7. **shm_size 1gb** (row 2): should scale with work_mem × parallel workers once
   rows 5 and 9 derive.
8. Minor: supervisor-1 10/3 (row 20), download cap 8 (row 37, partly a courtesy
   cap toward the source servers), nginx worker_connections 1024 (row 16).

## Deliberate ceilings, excluded from the fix list unless overruled

- shared_buffers small (row 3): the headroom law, operator ruling 2026-08-02. A
  giant boundary insert's transient is set by the data, not the host, so
  (cap − shared_buffers) must fund it.
- prewarm single writer (row 21), the 20% shares (rows 24, 25), the protocol and
  bounded-unit constants (rows 29 to 32), recycle thresholds (row 22).
