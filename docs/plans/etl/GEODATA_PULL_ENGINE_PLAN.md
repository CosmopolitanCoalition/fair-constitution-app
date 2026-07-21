# Geodata Pull Engine — Design Plan (2026-07-20)

Retrofit the autoscale pull-engine pattern (claim ladder → worker pool → pump
liveness → review/requeue → one final acceptance gate) onto the geodata ETL,
so ingestion becomes multithreaded, incrementally reprocessable, and visible
per-worker — ending the "tweak → 9-hour full rerun" cycle. One end-to-end
verification runs ONCE, at the end, as the run's closing item.

Investigation basis (2026-07-20, code-verified): the ETL is SINGLE-THREADED —
zero multiprocessing/ThreadPool/concurrent.futures in `scripts/etl/`; the only
subprocess use is crash isolation (Phase 2 GDAL split) and the T.7
one-pair-at-a-time OOM guard. The item decomposition ALREADY exists
(progress.json per-unit statuses, `--countries/--adm-levels` filters,
pause-on-error control files, heartbeat single-slot display, T.7 checkpoint
report + APPLY gate, the geodata_flags/manifests/repairs repair plane, per-unit
idempotency via ON CONFLICT slugs / pure UPDATEs / DELETE-first raster loads).
Only the concurrency layer is missing. This plan adds exactly that layer and
changes as little of the importers as possible.

STATUS: PLAN ONLY — nothing here is built. Build order in §9.

---

## 1. The run model — phases and items

A geodata run is a phase DAG: parallel fan-outs separated by single-writer
barriers, exactly the autoscale claims-ladder shape.

Run phases (enum on `geodata_runs.phase`):

```
enumerating → boundaries → resolving → rasters → attribution → finalizing → scanning → done
```

Item kinds (on `geodata_items.kind`) and their pools:

| Phase        | kind               | Item = | Count (planet) | Bound by |
|--------------|--------------------|--------|----------------|----------|
| enumerating  | `manifest`         | the whole archive: discover files, validate manifest (boundary ISOs vs raster dirs, XKX→KOS alias map, whitelist ATA/VAT), INSERT all items | 1 | disk |
| boundaries   | `boundary_iso`     | one ISO's full ADM level chain (levels ordered INSIDE the item — parenting reads the level above; ISOs independent) | ~231 | PG inserts |
| resolving    | `resolve_global`   | Earth row + synthesize_missing_country_rows + post_pass_orphan_resolution + phase_s_resolve_cross_iso | 1 | PG (serial) |
| rasters      | `raster_iso`       | one ISO's tile load (`load_raster_to_db`: DELETE-first + batch INSERT) | ~198 | WAL |
| attribution  | `attribution_pair` | one (iso, adm_level) via T.7 `raster_attribution.attribute()` — the NumPy/rasterio path, CPU-bound, NOT PG-bound | ~483 | CPU |
| finalizing   | `finalize_global`  | rollup_planet_population + validate_national_population (findings → flags, not log warnings) | 1 | PG (serial) |
| scanning     | `acceptance_scan`  | `geodata:scan` detector suite → geodata_flags; run summary audit | 1 | PG |

SETTLED DECISIONS:
- **T.7 IS the population engine.** The seed's population fill uses
  `raster_attribution.attribute()` per pair (the WorldPop-discrepancy
  solution), not the old `population_within` SQL pass. ~10× per pair and
  CPU-parallel. The SQL path stays in the codebase untouched (runtime drawn-
  polygon measurement uses it) but the seed no longer walks it.
- **Ordering: LARGEST-FIRST within each fan-out phase** (`position` by
  est_cost DESC — file bytes for boundaries, raster bytes for rasters,
  n_polys for pairs). Opposite of autoscale's simplest-first: here there is
  no triage benefit, and IND L6 (649k polys) must start first or it defines
  the tail alone.
- **Failures never sink the run** (autoscale posture): a phase's barrier
  opens when the pool has zero pending+running — done, review, and failed
  all count as settled. A refused ISO's absence is honest; the scan flags it.
- **Dry-run on reprocess**: requeued `attribution_pair` items honor a
  `dry_run` flag (item column, default false) — results land in the item's
  metrics + a flag row instead of UPDATEs, preserving the T.7
  review-then-APPLY workflow for discrepancy iteration. The founding seed
  applies directly.

## 2. Schema (one REAL-dated additive migration)

`2026_07_XX_000001_geodata_pull_engine.php` — three tables, mirroring
autoscale's proven shapes:

- `geodata_runs`: id (uuid), status, phase, data_root, options jsonb
  (countries filter, adm_levels, fresh, dry_run), counters
  (items_total/done/review per kind — refreshed by pump), halt_requested_at,
  paused_until, pg_fingerprint, initiator_user_id, phase timestamps jsonb
  (per-phase started/finished — the benchmark instrumentation), timestamps.
- `geodata_items`: id, run_id, kind, iso_code (nullable), adm_level
  (nullable), status (pending/running/done/review/failed), claim_token,
  reason, position, est_cost bigint, dry_run bool, metrics jsonb (rows
  inserted, tiles, n_polys, elapsed, pre/post sums for pairs),
  started_at/finished_at, timestamps.
  Indexes: (run_id, status, position) partial WHERE status='pending';
  (run_id, kind, status).
- `geodata_worker_leases`: id, run_id, started_at, last_seen_at, claim_type,
  claim_label, claim_started_at — byte-compatible with the autoscale lease
  display so the Step-2 worker strip reuses the Step-3 component.

progress.json is RETIRED for pull-engine runs (the DB is the state; that is
what makes halt/resume/requeue and the UI trivial). The legacy
`seed_database.py` CLI path keeps progress.json untouched for bare-metal use.
`rebuild_worldpop_progress.py` becomes legacy-only.

## 3. The claim ladder

`GeodataClaims::next(run, token)` (PHP, for pins) and the identical SQL in
Python (`claims.py`) — the ladder is plain SQL so both sides share it:

1. Honor `paused_until` / `halt_requested_at` → no claim.
2. Claim = `UPDATE geodata_items SET status='running', claim_token=:t,
   started_at=COALESCE(started_at, now()) WHERE id = (SELECT id FROM
   geodata_items WHERE run_id=:r AND status='pending' AND kind = ANY(:kinds)
   ORDER BY position FOR UPDATE SKIP LOCKED LIMIT 1) RETURNING *`.
3. `:kinds` = the single kind matching `run.phase` (barriers are one-item
   pools — the same SQL claims them; no special case).

Phase ADVANCE lives in the pump (never in workers): when the current phase's
pool has zero pending+running, set the next phase + stamp phase timestamps.
`enumerating` is advanced by the manifest item itself completing (the pump
sees zero pending and moves on, same rule).

## 4. Python side — supervisor becomes a worker-pool manager

`supervisor.py` keeps its request.json entry (one RUN at a time — a run is
the unit of exclusivity now, not a job) and gains the pool loop:

- On an active run (status not halted/done/paused): maintain
  `CGA_ETL_WORKERS` worker processes (default `min(cores-2, 12)`; the
  attribution phase is CPU-bound so this dial matters more than autoscale's).
- Each **worker process** (`worker.py`): register/refresh its lease row
  (~every 15s and at claim boundaries — claim_type/claim_label like
  `attribution · IND L6`, cleared between claims); loop: claim → execute →
  write outcome → next. Honors halt/pause at every claim boundary; SIGTERM
  forwards to the active subprocess (the seed_database handler pattern).
- Each claimed item executes in a **fresh subprocess** (`etl_unit.py
  --run R --item I`) — the T.7 OOM lesson is LAW here: rasterio caches, heap
  fragmentation, NumPy temporaries all die with the child. Result contract =
  one JSON line on stdout (the run_t7_pair.py contract, generalized); missing
  JSON or nonzero exit → item status='review' with the tail of stderr as
  reason. The parent worker owns the DB status write.
- `etl_unit.py` dispatch per kind, calling EXISTING functions:
  - `manifest` → `discover_geoboundaries_files()` + raster-dir census +
    alias map + chunked item INSERTs (25k chunks — THE ETL RULE, though
    ~1k rows here) + est_cost fill.
  - `boundary_iso` → `import_geoboundaries(countries=[iso],
    no_global_passes=True)` — ONE new kwarg that skips Earth/synthesis/
    orphan/phase_s (those move to the barrier). The per-file loop, meta
    index, slug idempotency all run unchanged.
  - `resolve_global` → `import_geoboundaries(global_passes_only=True)`.
  - `raster_iso` → `load_raster_to_db(iso)` unchanged (DELETE-first =
    idempotent).
  - `attribution_pair` → the `run_t7_pair.py` body (iso, level, apply
    unless item.dry_run).
  - `finalize_global` → `rollup_planet_population()` +
    `validate_national_population()` writing geodata_flags rows
    (class `national_delta_gt5`) instead of WARN lines.
  - `acceptance_scan` → invoke `php artisan geodata:scan --run=R` via the
    app container? NO — no docker-in-docker. The scan runs Laravel-side:
    the pump fires it when phase flips to `scanning` (queued job), and the
    job closes the item. The ONE exception to "workers execute items".
- `heartbeat.py` retired for pull runs (leases replace it); `etl.log` stays
  unified with a `[w3]` worker prefix per line.

## 5. Laravel side

- **`geodata:pump`** (everyMinute while a run is live — scheduler entry
  mirrors autoscale:pump): stale-claim reclaims (>30 min → pending, token
  cleared), pg-fingerprint breaker (pause ~10 min on restart — pause-only,
  never a governor), phase advance, counter refresh, lease-row cull,
  `acceptance_scan` dispatch, completion (status=done + summary audit
  append; hash-chained audit_log like autoscale.completed).
- **Step-2 UI** (`Step2_MapData.vue` or current equivalent): per-phase bars
  (items done/total per kind from one GROUP BY), the per-worker claim strip
  (same component contract as Step-3's), review census with reasons, flags
  count, phase timeline with elapsed — all from the three tables via a
  `geodataProgress` endpoint. The start endpoint writes `geodata_runs`
  (status=enumerating) + request.json for the supervisor; Laravel never
  reads /archive.
- **Requeue recipe** (the batch-fix cycle, verbatim from run-6):

```sql
WITH r AS (SELECT id FROM geodata_items WHERE run_id=:r
            AND status IN ('review','failed') [AND kind/iso filter])
UPDATE geodata_items SET status='pending', claim_token=null, reason=null,
       started_at=null, finished_at=null, position=0, updated_at=now()
 WHERE id IN (SELECT id FROM r);
```

  Requeue may target any settled item (done included) — reprocessing a
  healthy ISO after a code tweak is the POINT. Requeuing a `boundary_iso`
  auto-requeues its dependent `resolve_global`+downstream barrier items
  (they re-run; all barrier passes are idempotent by design — verify in
  build for phase_s).
- **`geodata:revert --iso=XXX [--run=R]`** — surgical per-ISO teardown:
  chunked DELETE of that ISO's purgeable-source jurisdictions +
  constitutional_settings + worldpop_rasters rows, VACUUM ANALYZE after
  (tx-guarded), requeue the ISO's items. `--fresh` (full purge) stays the
  founding-stage fast path (THE ETL RULE posture: TRUNCATE-class resets only
  at founding).

## 6. Flags — ONE system, not two

- Item-level failures = `geodata_items.status='review'` + reason (the
  censusable stream, same queries as run-6 watching).
- Data-quality findings = `geodata_flags` rows written by the barrier/gate
  items with (run_id, iso, adm_level, class, payload). Ready-made classes
  from the run-1 verdict: `raster_absent_alias` (XKX/KOS), `clip_sanity`
  (Greenland ADM0/Σraster ratio), `cross_iso_undercount`,
  `national_delta_gt5`, `l1_name_mismatch` (ind-1-puducherry class),
  `dual_coverage_roster` (informational). The existing repair plane
  (geodata_repairs apply/export) acts on them — no new repair machinery.
- The discrepancy iteration loop this whole design serves:
  tweak `raster_attribution.py` → requeue affected pairs (optionally
  dry_run=true first) → inspect metrics/flags → apply → `acceptance_scan`
  once at the end. No planetary rerun anywhere in the loop.

## 7. Concurrency + postgres posture (encode, don't rediscover)

- Worker dial `CGA_ETL_WORKERS`, default `min(cores-2, 12)`.
- Raster phase is WAL-heavy: for bench/seed runs set `max_wal_size=8GB`,
  `checkpoint_timeout=15min` (compose override); shm_size 1gb already law.
- Global barrier passes: `SET max_parallel_workers_per_gather=0` (the
  /dev/shm lesson), VACUUM ANALYZE churned tables at phase boundaries
  (pump does it on phase advance, tx-guarded).
- Boundary phase insert contention: ~8–12 writers is the knee; fine at the
  default dial. Attribution phase barely touches PG (lazy geom fetch +
  final UPDATE batch) — it scales to the CPU dial cleanly.

## 8. Tests

- **PHP pins** (`GeodataPullEngineTest`, live-pg, synthetic items — NO real
  ETL execution): claim order largest-first; barrier gating (pool drains →
  phase advances; review items don't block); stale reclaim; halt/resume
  round-trip; breaker pause; requeue recipe resets + head position;
  revert --iso removes exactly one ISO's rows and requeues its items;
  counters. Mirrors AutoscalePinTest's mechanics coverage.
- **Python smoke** (pytest in the etl container, `tests/fixtures/etl-mini/`:
  two fake ISOs, hand-written tiny geojsons + two generated micro-TIFFs,
  committed): full ladder end-to-end with 2 workers → all phases done,
  populations attributed, flags written, rerun of one pair idempotent.
- DistrictingDoctrineTest + autoscale pins untouched and must stay green
  (nothing here touches districting or PROTECTED files).

## 9. Build order (each step lands green before the next)

1. Migration + models + `GeodataClaims` + `geodata:pump` + PHP pins.
2. Python `claims.py` + `worker.py` + `etl_unit.py` dispatch + importer
   kwargs (`no_global_passes` / `global_passes_only`) — legacy CLI path
   proven unchanged (run `--countries NZL` smoke both ways).
3. supervisor pool mode + mini-archive pytest smoke.
4. Step-2 UI (bars + worker strip + review census + flags).
5. `geodata:revert --iso` + requeue wiring + flags classes in the barriers.
6. Benchmark: compose override `fc_bench_postgres` (:5436, WAL-tuned) +
   bench env for the etl container → full-planet seed at the worker dial;
   per-phase timestamps in geodata_runs ARE the report. Baseline: 9h
   single-threaded. Expected: boundaries ~20–30 min, rasters ~15–30 min,
   attribution ~30–45 min, barriers ~15–20 min → **~1–1.5 h end-to-end
   (6–8×)**; the barrier serial floor and the IND-L6 straggler set the
   limit, not worker count. A 1-worker control run quantifies pull-engine
   overhead if wanted.

## 10. Non-goals / guardrails

- No change to the accepted-noise doctrine, denominator choices, or any
  districting/PROTECTED code.
- No docker-in-docker; Laravel↔Python coordination is exclusively through
  the DB + request.json.
- No harmonization passes, no new repair actions — flags feed the EXISTING
  repair plane.
- seed_database.py's sequential CLI survives as-is for bare-metal/legacy.
