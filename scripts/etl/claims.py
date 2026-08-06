"""
claims.py — the geodata pull engine's shared claim SQL, Python side
(GEODATA_PULL_ENGINE_PLAN.md §3).

Mirrors app/Support/GeodataClaims.php: the ladder is plain SQL so both sides
share it. A worker registers a lease (its claim token), then loops claim →
execute → record. Each claim is ONE atomic UPDATE … RETURNING with
FOR UPDATE SKIP LOCKED against the CURRENT phase's kind, so any number of
worker processes partition the phase's work-list with no orchestrator.

Ordering is LARGEST-FIRST: enumeration stores position = -est_cost, so
ORDER BY position ASC starts the heaviest unit first (IND L6, 649k polys).
"""

from __future__ import annotations

import os
import uuid

from db import get_cursor

# The single item kind claimable in each phase (barriers are one-item pools).
# This governs PHASE-DRAIN (phaseDrained() / GeodataClaims.php gate on this
# kind alone) — untouched by the fallthrough below.
PHASE_KIND = {
    "enumerating": "manifest",
    "boundaries":  "boundary_iso",
    "resolving":   "resolve_global",
    "rasters":     "raster_iso",
    "attribution": "attribution_pair",
    "finalizing":  "finalize_global",
    "scanning":    "acceptance_scan",
}

# Overlap ingest (2026-08-02, reviewed external plan INGEST_OVERLAP_PLAN.md,
# adopted with the worker.py memory-denominator fix below): boundaries and
# rasters are independent fan-outs — raster_iso/_range never touch
# jurisdictions — serialized only by phase convention, which left N-1 lanes
# idle behind the single-item resolve_global barrier and the boundaries
# giant-gate yields. A lane that finds nothing pending in its phase's OWN
# kind falls through to these instead of sleeping. Phase-drain semantics
# are NOT affected — a phase still only advances when its own PHASE_KIND
# settles, so rasters keep flowing through boundaries+resolving regardless
# of when the boundaries/resolving gate itself opens.
# Just "raster_iso" — _attempt_claim already expands it to the
# (raster_iso, raster_range) family, so listing raster_range too would
# only add a redundant second query.
# Phase order (mirrors GeodataRun::PHASES): the two GROUPS are consecutive —
# INGEST = boundaries, rasters ; DERIVE = resolving, attribution. Within a group
# the members overlap via fallthrough; ACROSS groups nothing leaks (that leak is
# what let resolve start before rasters finished).
PHASE_FALLTHROUGH = {
    # INGEST overlap: rasters run concurrently during boundaries.
    "boundaries": ("raster_iso",),
    # …and boundaries during rasters, so a boundary requeued by the INGEST
    # review (e.g. Canada) is claimable while the phase sits at `rasters`.
    "rasters":    ("boundary_iso",),
    # DERIVE overlap (early attribution, 2026-08-03): idle lanes attribute while
    # the resolve ladders grind. raster_iso is GONE from here — rasters belong to
    # the ingest group and are already done + review-free before resolving opens.
    "resolving":  ("attribution_pair",),
    # …and resolve during attribution, so a resolve item requeued by the DERIVE
    # review is claimable while the phase sits at `attribution`.
    "attribution": ("resolve_global",),
}

# Per-kind concurrency caps — OPERATOR DIALS ONLY (2026-08-02). The derived
# caps were retired with the pool-memory clamp: the operator demonstrated
# 10-wide boundaries work on the reference box, and every crash was the
# PG-side giant transient — which the shared/exclusive gate in
# bulk_insert_jurisdictions now governs at the exact grain (a giant insert
# runs ALONE; the field runs at full pool width). Memory on the Python side
# is governed by the per-child budget slices + chunk profiles. So by
# default every kind runs as wide as the pool (autoscaler philosophy: ONE
# concurrency limiter — the pool — plus a heavy-lane gate); an env dial
# caps a kind only when the operator says so.
_KIND_CAP_ENV = {
    "boundary_iso":     "CGA_ETL_CAP_BOUNDARY",
    "raster_iso":       "CGA_ETL_CAP_RASTER",
    "attribution_pair": "CGA_ETL_CAP_ATTRIBUTION",
}


# ── STAGE READINESS (operator dependency model, 2026-08-03) ────────────────
# "Once Boundaries is Review Free and Done the Resolver lanes can kick on.
#  Once Boundaries AND Rasters are Review Free and Done the attribution
#  lanes can kick on."
#
# The phase POINTER stopped being the real gate the moment overlap
# fallthrough landed — it is a progress indicator now. These predicates are
# the actual dependency order, enforced where work is handed out. REVIEW
# FREE is the operator's word and it is load-bearing: a country still in
# review has rows missing, so resolving parent chains over it would compute
# against an incomplete tree and attribution would verdict against holes.
# CORRECTED 2026-08-05 (operator, definitive): the pipeline is
#   BOUNDARIES + RASTERS  →  REVIEW  →  RESOLVE + ATTRIBUTION  →  REVIEW  →  FINALIZE  →  SCAN
# so RESOLVE is in the DERIVE group and waits for the ENTIRE ingest group —
# boundaries AND rasters — to be done and review-free, exactly like attribution.
# The old list had resolve waiting on boundaries only, which let it start while
# rasters were still running (operator-caught live, twice). Resolve does not
# NEED population, but the operator's group model gates it with attribution, and
# stage_ready already treats review/failed as not-ready, so this one change also
# makes a boundary or raster still in review block resolve until its review
# clears — the "→ REVIEW →" step between the two groups.
STAGE_PREREQS: dict[str, tuple[str, ...]] = {
    "resolve_global":      ("boundary_iso", "boundary_range",
                            "raster_iso", "raster_range"),
    "resolve_range":       ("boundary_iso", "boundary_range",
                            "raster_iso", "raster_range"),
    "attribution_pair":    ("boundary_iso", "boundary_range",
                            "raster_iso", "raster_range"),
    "attribution_decompose": ("boundary_iso", "boundary_range",
                              "raster_iso", "raster_range"),
    "attribution_range":   ("boundary_iso", "boundary_range",
                            "raster_iso", "raster_range"),
}


def stage_ready(conn, run_id: str, kind: str) -> bool:
    """Are `kind`'s prerequisite stages DONE and REVIEW-FREE?

    Cheap (one indexed count) and called only on the claim path. Kinds with
    no prerequisites are always ready."""
    prereqs = STAGE_PREREQS.get(kind)
    if not prereqs:
        return True
    with get_cursor(conn) as cur:
        cur.execute(
            """
            SELECT COUNT(*) AS n FROM geodata_items
             WHERE run_id = %s AND kind = ANY(%s)
               AND status IN ('pending', 'running', 'review', 'failed')
            """,
            (run_id, list(prereqs)),
        )
        return int(cur.fetchone()["n"]) == 0


# ── ATTRIBUTION'S MEASURED COST MODEL (2026-08-04) ─────────────────────────
# Replaces a bare `640 * 1024 * 1024` literal whose only justification was a
# comment reading "~500-700 MB observed". That hardcode violated law 1 of the
# ETL paradigm (derive from the host, never hardcode) and, being a single
# number for wildly unequal work, it capped the whole field at whatever the
# fattest pair might cost.
#
# MEASURED on this box, each pair run ALONE, peak RSS:
#     ESP L3  1.00M verts -> 442 MB      IRL L2  1.32M -> 433 MB
#     EST L4  1.29M verts -> 467 MB      PAN L4  5.20M -> 742 MB
# Least-squares through those four: ~371 MB fixed + ~71 MB per million
# vertices. (Import baseline is only 78 MB — numpy/rasterio/shapely/scipy —
# so the fixed term is real work: parse caches, window buffers, the
# decomposition's piece lists. GDAL_CACHEMAX was tested and is NOT a factor:
# capping it at 64 MB moved peak RSS by 1 MB.)
#
# A SLICE IS NOT CHEAPER THAN A PAIR. Measured IND L6 bands of 416 windows:
# 871 MB and 776 MB — heavier than any whole pair above, because band-scoped
# metadata still admits a large share of 649,771 features. So slices are
# costed by the same model against their PARENT's geometry, not waved
# through as "small children".
ATTR_FIXED_MB    = int(os.environ.get("CGA_ETL_ATTR_FIXED_MB", "0") or 0) or 371
ATTR_MB_PER_MVER = int(os.environ.get("CGA_ETL_ATTR_MB_PER_MVERT", "0") or 0) or 71
# The lane-side cost of a RUNNING item beyond its child's RSS: pipe buffers,
# roster bookkeeping, the psycopg connection — measured ~80 MB per live item
# (2026-08-05, the "parents uncharged" gap in the admission-model audit:
# charged 1.79 GB vs actual 2.01+). Invisible inside the headroom at width
# 4; ~800 MB at width 10, so the wide field must charge it per item.
ATTR_PARENT_MB   = int(os.environ.get("CGA_ETL_ATTR_PARENT_MB", "0") or 0) or 80


def attribution_cost_mb(est_cost: int) -> int:
    """Predicted peak RSS for an attribution item, from its vertex weight."""
    return ATTR_FIXED_MB + int(ATTR_MB_PER_MVER * (max(0, int(est_cost)) / 1_000_000.0))


def kind_cap(kind: str) -> int | None:
    """The operator's cap for a kind, or None (uncapped — the default).

    Attribution no longer uses a count cap — admission is by MEASURED COST
    (see _attempt_claim): a pair is admitted while the running total of
    predicted peaks still fits the container. A count cap cannot express
    that, because a 440 MB pair and an 870 MB India slice are not
    interchangeable units. CGA_ETL_CAP_ATTRIBUTION still forces a hard
    count when the operator wants one."""
    env_key = _KIND_CAP_ENV.get(kind)
    if env_key is not None:
        raw = os.environ.get(env_key, "")
        if raw.isdigit() and int(raw) > 0:
            return int(raw)
    return None


# TWO-ENDED QUEUE, HALF AND HALF (operator ruling 2026-08-02, superseding
# the 80/20 width: "start largest to smallest on half and smallest to
# largest on the other half — pre split for 5 lanes where a split is
# needed"). ONE pile — countries AND pre-split monster ranges together,
# ordered by est_cost. The small-first half of the pool flies through most
# countries smallest → largest; the big-first half eats the pile largest →
# smallest, which lands them on the monsters' pre-split ranges at once
# (a split makes as many ranges as there are big lanes, so the whole big
# half converges on one monster level together). No drain checks, no
# stop/start — the two directions meet in the middle and every lane is
# busy until the pile is empty. CGA_ETL_BIG_LANES overrides the width.


def big_lane_count(pool: int) -> int:
    raw = os.environ.get("CGA_ETL_BIG_LANES", "")
    if raw.isdigit() and int(raw) > 0:
        return min(int(raw), max(1, pool - 1))
    return max(1, pool // 2)   # half the pool — 10 → 5


def get_active_run(conn) -> dict | None:
    """The single active run (oldest running/halted), or None."""
    with get_cursor(conn) as cur:
        cur.execute(
            """
            SELECT id::text AS id, status, phase, data_root, options,
                   halt_requested_at, paused_until
              FROM geodata_runs
             WHERE status IN ('running', 'halted')
             ORDER BY created_at
             LIMIT 1
            """
        )
        row = cur.fetchone()
    return dict(row) if row else None


def run_control(conn, run_id: str) -> dict:
    """Fresh {status, phase, halt, paused} for the worker's stop checks."""
    with get_cursor(conn) as cur:
        cur.execute(
            """
            SELECT status, phase, review_pass,
                   (halt_requested_at IS NOT NULL) AS halt,
                   (paused_until IS NOT NULL AND paused_until > now()) AS paused
              FROM geodata_runs WHERE id = %s
            """,
            (run_id,),
        )
        row = cur.fetchone()
    if row is None:
        return {"status": "gone", "phase": "done", "halt": True, "paused": False}
    return dict(row)


def _attempt_claim(conn, run_id: str, kind: str, lane: str, token: str) -> dict | None:
    """Claim the next pending item of ONE kind, atomically, or None. The
    body of the old single-kind claim_next, unchanged — factored out so
    claim_next can try several kinds in sequence (overlap fallthrough)
    without duplicating the lock/cap/claim SQL.

    Memory governor, HARD since the 02:59 postgres OOM: the count-then-claim
    pair runs inside ONE transaction serialized by an advisory xact lock, so
    two workers can never both see a free slot (the soft cap collapsed under
    the 10-worker cold-start burst — all five monsters claimed at once and
    their concurrent giant-geometry inserts OOM-killed a PG backend at
    2.4 GB anon-rss). Mirrors AutoscaleClaims::claimScope's heavy-lane
    gate. Serializing ALL claims is fine: a claim is milliseconds against
    minutes-to-hours of item work."""
    # Dependency gate BEFORE anything else: resolve waits for a done and
    # review-free boundaries; attribution waits for boundaries AND rasters.
    if not stage_ready(conn, run_id, kind):
        return None

    cap = kind_cap(kind)
    heavy_clause = ""
    heavy_params: tuple = ()
    with get_cursor(conn) as cur:
        cur.execute("SELECT pg_advisory_xact_lock(hashtext('cga_geodata_claim'))")

        # ADMISSION BY MEASURED COST, not by a count (2026-08-04). Attribution
        # items are wildly unequal — 433 MB for IRL L2, 742 MB for PAN L4,
        # 871 MB for an IND L6 band — so one number cannot describe "how many
        # fit". Admit while the running total of PREDICTED PEAKS still fits
        # the container, and exclude only candidates that would overflow it.
        # This is the same advisory-locked transaction as the claim, so two
        # workers can never both see the same headroom.
        if kind == "attribution_pair" and cap is None:
            # THE LIVE WALL (operator ruling 2026-08-06): capped container →
            # static cgroup ceiling as always; uncapped → the wall breathes
            # (MemAvailable + own usage, read per claim), so admission
            # widens into whatever postgres and the app are not using and
            # narrows when they fatten. Own incumbents stay charged at
            # predicted peaks — cc63c80 stands.
            try:
                from memory_budget import (admission_wall_bytes,
                                           cgroup_limit_bytes,
                                           host_memtotal_bytes)
                budget_mb = admission_wall_bytes() // (1024 * 1024)
                capped = cgroup_limit_bytes() is not None
            except Exception:
                budget_mb, capped = 2048, True
            env_h = os.environ.get("CGA_ETL_ATTR_HEADROOM_MB", "")
            if env_h.isdigit() and int(env_h) > 0:
                headroom = int(env_h)
            elif capped:
                headroom = 256
            else:
                # No cgroup teeth → others can GROW after we admit (a
                # postgres transient, an app spike). Reserve a host-derived
                # share for them; the OOM tilt covers the residual bet.
                try:
                    total_mb = (host_memtotal_bytes() or 0) // (1024 * 1024)
                except Exception:
                    total_mb = 0
                headroom = max(256, total_mb // 10)
            # Per-item charge = child peak + the parent lane's overhead.
            fixed_mb = ATTR_FIXED_MB + ATTR_PARENT_MB
            fam = ("attribution_pair", "attribution_range")
            cur.execute(
                """
                SELECT COALESCE(SUM(est_cost), 0) AS w, COUNT(*) AS n
                  FROM geodata_items
                 WHERE run_id = %s AND status = 'running' AND kind IN %s
                """,
                (run_id, fam),
            )
            r = cur.fetchone()
            used_mb = (int(r["n"]) * fixed_mb
                       + int(ATTR_MB_PER_MVER * (int(r["w"]) / 1_000_000.0)))
            free_mb = budget_mb - headroom - used_mb

            # ACTUAL-VS-BUDGET FILL (operator ruling 2026-08-05: "monitor the
            # actual against the budget to fill the lanes"). The charged model
            # sums PREDICTED PEAKS, but incumbents past their heavy windows
            # hold far less than their charge (measured live: 4 lights charged
            # 371 each while holding ~210) — real slack the charge can't see.
            # The CANDIDATE is still charged its full predicted peak, and the
            # actual path keeps a LARGER reserve (incumbents below peak can
            # still climb), so the OOM bet is bounded on both sides. Reader
            # failure → None → charged model only, never a guess.
            # ACTUALS BOOST REVERTED (2026-08-05, ten exit -9s in one minute:
            # incumbents sit BELOW their eventual peaks, so actual-free looks
            # generous, admission widens, then the peaks arrive TOGETHER and
            # the kernel collects — PAN L4, a pair the linear model already
            # under-charges, among the bodies. The 512MB bet lost. Charged
            # peak-sum math only: it is the model that survives simultaneity.

            # HEAVY RESERVATION (same ruling: "if it's a big one you wait for
            # that number of lanes to clear to give it space, then send it").
            # Without this, opportunistic lights re-fill every freed MB and
            # the heaviest pending pair starves at the head of the big-first
            # queue. While the top pending pair cannot fit the CURRENT free
            # space — but could fit an emptier field — freed space is RESERVED
            # for it: nothing else admits, the field drains, the heavy seats.
            # A pair too big for even an EMPTY field is excluded (that is the
            # decompose path's problem, not a reason to drain forever).
            # The reservation scans the whole FAMILY (2026-08-06): a pending
            # decompose BAND is claimable through this same query and often
            # outweighs every pending pair — with top_w computed from pairs
            # alone, the est_cost >= top_w arm would admit the band against
            # a top_peak measured for something lighter (the exit-9 class).
            cur.execute(
                """
                SELECT COALESCE(MAX(est_cost), 0) AS top_w
                  FROM geodata_items
                 WHERE run_id = %s AND status = 'pending'
                   AND kind IN %s
                """,
                (run_id, fam),
            )
            top_w = int(cur.fetchone()["top_w"])
            top_peak = fixed_mb + int(ATTR_MB_PER_MVER * (top_w / 1_000_000.0))
            # TWO-SIDED ADMISSION — THE DRAINING PARADIGM (operator,
            # 2026-08-05: two directions per pile, and "the smalls never
            # stop"). While a seatable monster waits, its peak is a STANDING
            # RESERVATION: lights admit only into the space BESIDE it (so
            # they can never re-block it — the flaw in both earlier forms),
            # and the monster itself admits the instant the field drains
            # enough to hold it. Small-first churns, big-first seats, the
            # pile drains from both ends toward the middle.
            if top_w > 0 and top_peak <= (budget_mb - headroom):
                lights_room = free_mb - top_peak
                max_w_light = (
                    int((lights_room - fixed_mb) / max(1, ATTR_MB_PER_MVER) * 1_000_000)
                    if lights_room >= fixed_mb else -1
                )
                heavy_ok = free_mb >= top_peak
                if max_w_light < 0 and not heavy_ok:
                    return None      # pure drain window — nothing fits yet
                if heavy_ok and max_w_light >= 0:
                    heavy_clause = " AND (est_cost <= %s OR est_cost >= %s) "
                    heavy_params = (max_w_light, top_w)
                elif heavy_ok:
                    heavy_clause = " AND est_cost >= %s "
                    heavy_params = (top_w,)
                else:
                    heavy_clause = " AND est_cost <= %s "
                    heavy_params = (max_w_light,)
            else:
                if free_mb < fixed_mb:
                    return None                  # not even a minimal item fits
                # Largest vertex weight that still fits the remaining headroom.
                max_w = int((free_mb - fixed_mb) / max(1, ATTR_MB_PER_MVER) * 1_000_000)
                heavy_clause = " AND est_cost <= %s "
                heavy_params = (max_w,)

        if cap is not None:
            # The cap counts the kind's FAMILY (a country and its ranges
            # are one budget class; a pair stands alone).
            fam = {"boundary_iso":      ("boundary_iso", "boundary_range"),
                   "raster_iso":        ("raster_iso", "raster_range"),
                   "attribution_pair":  ("attribution_pair", "attribution_range"),
                   }.get(kind, (kind, kind))
            # THE CAP COUNTS EVERY PAIR (reverted 2026-08-04 to the 4 PM
            # behaviour). Exempting "light" pairs let ten run at once instead
            # of three, which cut each one's share of the 2345 MB container
            # from 780 MB to 234 MB — and that, not any splitting decision, is
            # why pairs that had always run whole began dying. PHL L4 needs
            # ~400 MB: it fits in 780 and does not fit in 234.
            cur.execute(
                """
                SELECT COUNT(*) AS n FROM geodata_items
                 WHERE run_id = %s AND status = 'running' AND kind IN %s
                """,
                (run_id, fam),
            )
            if int(cur.fetchone()["n"]) >= cap:
                return None

        # Two-ended queue: general lanes take the smallest pending item, big
        # lanes take the largest. SKIP LOCKED resolves the meeting point.
        # ONE PILE (pre-split ruling 2026-08-02): during the boundaries
        # phase, countries and pre-split monster RANGES share the pile —
        # a range's est_cost is its feature count, so monster ranges sit at
        # the large end where the big-first half of the pool converges on
        # them immediately, while the small-first half keeps eating whole
        # countries. The old two-tier claim (ranges only after the country
        # pile drained) is gone with the drain-triggered split machinery.
        order = "est_cost DESC, position, id" if lane == "big" \
            else "est_cost ASC, position, id"

        # GIANT GATE AT THE CLAIM (2026-08-03, replaces the churn of gating
        # inside the unit). The unit-side crowd check made the RIGHT call —
        # never start a Nunavut-class parse in a crowded container — at the
        # WRONG layer: the item was already claimed, a subprocess already
        # spawned, and the yield went back to the head of the pile, so PHL
        # and CAN were picked up and put down every ~45s for half an hour
        # while the panel showed them "working" (operator-caught, twice).
        # Here, inside the SAME advisory-locked transaction as the claim, a
        # giant country is simply NOT ELIGIBLE while the field is crowded or
        # another giant runs: no claim, no subprocess, no churn — the item
        # sits honestly pending until the tail, then claims once and runs.
        # The atomic-lock context also closes the unit-check's race where
        # three giants claimed in the same second each saw the other two as
        # "only 2 running" and all proceeded.
        # EXPERIMENT CONCLUDED (operator order 2026-08-05, run 019fd200): the
        # crowded field held until two IRREDUCIBLE giants coincided — CAN ADM1
        # (5.39M vertices) and the IND raster load were SIGKILLed in the same
        # minute (both exit -9, co-residency blew the cgroup). The gate is
        # back ON by default; CGA_ETL_GIANT_CROWD_GATE=0 restores the crowded
        # experiment if ever wanted.
        giant_clause = ""
        giant_params: tuple = ()
        if kind == "boundary_iso" and os.environ.get("CGA_ETL_GIANT_CROWD_GATE", "1") == "1":
            _gv = int(os.environ.get("CGA_ETL_GIANT_VERTICES", "0") or 0) or 1_000_000
            _solo = int(os.environ.get("CGA_ETL_GIANT_SOLO_OPEN", "0") or 0) or 2
            cur.execute(
                """
                SELECT (SELECT COUNT(*) FROM geodata_items
                         WHERE run_id = %s AND status = 'running') AS crowd,
                       EXISTS (SELECT 1 FROM geodata_items gi
                                JOIN geoboundary_metadata m ON m.iso_code = gi.iso_code
                                                           AND m.max_vertices >= %s
                                WHERE gi.run_id = %s AND gi.status = 'running'
                                  AND gi.kind IN ('boundary_iso','boundary_range')) AS giant_busy
                """,
                (run_id, _gv, run_id),
            )
            g = cur.fetchone()
            if int(g["crowd"]) > _solo or bool(g["giant_busy"]):
                giant_clause = (
                    " AND iso_code NOT IN (SELECT DISTINCT iso_code "
                    "   FROM geoboundary_metadata WHERE max_vertices >= %s) "
                )
                giant_params = (_gv,)

        if kind == "boundary_iso":
            kinds = ("boundary_iso", "boundary_range")
        elif kind == "raster_iso":
            kinds = ("raster_iso", "raster_range")
        elif kind == "attribution_pair":
            kinds = ("attribution_pair", "attribution_range")
        elif kind == "resolve_global":
            # RESOLVE FAN-OUT (2026-08-03): resolve used to be ONE claimable
            # item — internally threaded, but one LANE, so nine lanes idled
            # ~30 minutes behind it and the panel's bar moved three times in
            # half an hour (set-based strategy UPDATEs are invisible until
            # commit). resolve_global is now a COORDINATOR that enumerates
            # per-country resolve_range children into this same pile, so
            # free lanes work countries in parallel and each shows its own
            # strip with elapsed time.
            kinds = ("resolve_global", "resolve_range")
        else:
            kinds = (kind, kind)

        # (Operator ruling 2026-08-02, superseding the floor-aware claim
        # predicate the same afternoon before it ever activated: "instead
        # of limiting the lanes, measure down the chunks and leverage the
        # lanes." Under the T7 fast path a heavy pair's footprint is its
        # parse cache, window buffers derive from the per-worker budget
        # slice, and the two-ended order mixes one monster with smaller
        # pairs — so heavies run IN LANES like everything else, bounded
        # by the budget-derived width cap alone. Children run instead of
        # spawn-yield-flashing, which also ends the popping bars.)
        cur.execute(
            f"""
            UPDATE geodata_items
               SET status = 'running', claim_token = %s,
                   started_at = COALESCE(started_at, now()), updated_at = now()
             WHERE id = (
                   SELECT id FROM geodata_items
                    WHERE run_id = %s AND status = 'pending' AND kind IN %s
                      {giant_clause}{heavy_clause}
                    ORDER BY {order}
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
             )
         RETURNING id::text AS id, kind, iso_code, adm_level, dry_run
            """,
            (token, run_id, kinds) + giant_params + heavy_params,
        )
        row = cur.fetchone()
    return dict(row) if row else None


def claim_next(conn, run_id: str, phase: str, token: str,
               lane: str = "small", skip_kinds: frozenset[str] = frozenset(),
               pref: str | None = None) -> dict | None:
    """Claim the next pending item of the phase's kind, atomically, or None.

    lane='small' (the general lanes) claims from the SMALL end of the pile
    (est_cost ASC); lane='big' claims from the LARGE end (est_cost DESC).
    One queue, two directions — the mapper's shape.

    Overlap fallthrough (2026-08-02, INGEST_OVERLAP_PLAN.md, adopted): if
    the phase's own kind has nothing claimable (drained, or every candidate
    sits behind a giant-gate yield), try PHASE_FALLTHROUGH's kinds before
    giving up — boundaries and rasters are independent fan-outs, so an
    idle lane can do real work instead of sleeping. Phase-drain still gates
    on PHASE_KIND alone; this only widens what a lane may claim.

    skip_kinds: kinds this call must not attempt even if otherwise eligible
    (worker.py's post-yield self-skip-list — see run_worker). Without this,
    "try the phase's own kind first" would just re-claim the SAME giant a
    lane was just yielded off of, since a freed 'pending' item sorts back
    to the head of its own est_cost ordering."""
    kind = PHASE_KIND.get(phase)
    if kind is None or kind == "acceptance_scan":
        # The acceptance scan is the ONE Laravel-side item (the pump dispatches
        # GeodataAcceptanceScanJob — no docker-in-docker). A Python worker must
        # never claim it: closing it here would skip the scan entirely.
        return None

    # QUADRANT PREFERENCE (operator 50/50 spec, 2026-08-03). "Own kind first,
    # fallthrough second" was itself the artificial suppression: with 232
    # boundary items pending, a lane essentially never fell through, so the
    # raster pile ran on scraps while boundaries hogged every lane. A lane's
    # pref decides which INGEST pile it tries first; when its pile is empty
    # it falls through to the complement, so the halves rebalance on their
    # own as one drain finishes. Kinds outside the pref axis (resolve,
    # attribution, finalize) keep the standard order.
    try_kinds = [kind, *PHASE_FALLTHROUGH.get(phase, ())]
    if pref == "raster" and "raster_iso" in try_kinds:
        try_kinds.remove("raster_iso")
        try_kinds.insert(0, "raster_iso")
    elif pref == "boundary" and "boundary_iso" in try_kinds:
        try_kinds.remove("boundary_iso")
        try_kinds.insert(0, "boundary_iso")

    for try_kind in try_kinds:
        if try_kind in skip_kinds:
            continue
        row = _attempt_claim(conn, run_id, try_kind, lane, token)
        if row is not None:
            return row
    return None


def claim_range(conn, run_id: str, iso: str, file_level: int | None, token: str,
                kind: str = "boundary_range") -> dict | None:
    """The COORDINATOR's claim: the next pending range of ITS OWN (iso, level).
    Same advisory lock as claim_next, so coordinator and free lanes never race.
    kind selects the range family (boundary_range | raster_range |
    attribution_range — bands carry adm_level NULL, hence IS NOT DISTINCT
    FROM). Self-claims RESPECT the family width cap (observed live: three
    coordinators' self-participation pushed the attribution family to 7
    concurrent children past the 6-lane cap the memory math assumes)."""
    fam_parent = {"boundary_range": "boundary_iso",
                  "raster_range": "raster_iso",
                  "attribution_range": "attribution_pair",
                  "resolve_range": "resolve_global"}.get(kind, kind)
    # Coordinators self-claim through here too — same dependency gate, or a
    # coordinator could pull its children past a stage that is not ready.
    if not stage_ready(conn, run_id, kind):
        return None
    cap = kind_cap(fam_parent)
    with get_cursor(conn) as cur:
        cur.execute("SELECT pg_advisory_xact_lock(hashtext('cga_geodata_claim'))")
        if cap is not None:
            # Attribution's cap counts HEAVY family members only (see
            # _attempt_claim) — a window slice or a light pair never holds
            # the width against the memory math written for monsters.
            heavy_filter = ""
            hp: tuple = ()
            if fam_parent == "attribution_pair":
                heavy_filter = " AND est_cost >= %s"
                hp = (int(os.environ.get("CGA_ETL_ATTR_HEAVY_EST", "0") or 0) or 5_000_000,)
            cur.execute(
                f"""
                SELECT COUNT(*) AS n FROM geodata_items
                 WHERE run_id = %s AND status = 'running'
                   AND kind IN (%s, %s) {heavy_filter}
                """,
                (run_id, fam_parent, kind) + hp,
            )
            if int(cur.fetchone()["n"]) >= cap:
                return None
        # iso=None means ANY iso of this range kind (the resolve coordinator
        # owns the whole per-country pile, not one country's slices). The
        # window/feature range kinds still pass their own (iso, level) and
        # stay scoped to it.
        scope = "" if iso is None else \
            "AND iso_code = %s AND adm_level IS NOT DISTINCT FROM %s"
        params = (token, run_id, kind) if iso is None \
            else (token, run_id, kind, iso, file_level)
        cur.execute(
            f"""
            UPDATE geodata_items
               SET status = 'running', claim_token = %s,
                   started_at = COALESCE(started_at, now()), updated_at = now()
             WHERE id = (
                   SELECT id FROM geodata_items
                    WHERE run_id = %s AND status = 'pending'
                      AND kind = %s
                      {scope}
                    ORDER BY position, id
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
             )
         RETURNING id::text AS id, iso_code, adm_level, metrics
            """,
            params,
        )
        row = cur.fetchone()
    return dict(row) if row else None


def record_outcome(conn, item_id: str, token: str, status: str,
                   reason: str | None = None, metrics: dict | None = None) -> bool:
    """Write a claimed item's terminal state (done|review|failed).

    Guarded by claim_token: if the pump reclaimed this item (stale) and another
    worker re-claimed it, OUR write must not clobber theirs. Returns whether
    the write landed."""
    import json
    with get_cursor(conn) as cur:
        # MERGE metrics (never replace): range items carry their window
        # definition in metrics; an error-path outcome replacing the column
        # would orphan the range from its own coordinates.
        cur.execute(
            """
            UPDATE geodata_items
               SET status = %s, reason = %s,
                   metrics = COALESCE(metrics, '{}'::jsonb) || COALESCE(%s::jsonb, '{}'::jsonb),
                   finished_at = now(), updated_at = now()
             WHERE id = %s AND claim_token = %s AND status = 'running'
            """,
            (status, reason, json.dumps(metrics) if metrics is not None else None,
             item_id, token),
        )
        return cur.rowcount > 0


def heartbeat_claim(conn, item_id: str, token: str) -> bool:
    """Bump the claimed item's updated_at so the pump's 30-min stale reclaim
    never false-fires on a LIVE long unit (IND L6 attribution, a USA boundary
    chain — the autoscale Falklands lesson). Returns False when the claim is
    no longer ours (reclaimed) — the worker should abandon the unit."""
    with get_cursor(conn) as cur:
        cur.execute(
            """
            UPDATE geodata_items SET updated_at = now()
             WHERE id = %s AND claim_token = %s AND status = 'running'
            """,
            (item_id, token),
        )
        return cur.rowcount > 0


# ── Worker leases (per-worker liveness + the UI claim strip) ────────────────

def register_lease(conn, run_id: str) -> str:
    """Insert a lease row; its id is the worker's claim token. Returns it."""
    token = str(uuid.uuid4())
    with get_cursor(conn) as cur:
        cur.execute(
            """
            INSERT INTO geodata_worker_leases (id, run_id, started_at, last_seen_at)
            VALUES (%s, %s, now(), now())
            """,
            (token, run_id),
        )
    return token


def touch_lease(conn, token: str, claim_type: str | None = None,
                claim_label: str | None = None, run_id: str | None = None) -> None:
    """Heartbeat the lease; stamp/clear the current claim for the UI strip.

    UPSERT when run_id is known: a lease row that vanished (operator cleanup,
    pump cull racing a long claim) silently re-creates instead of leaving a
    LIVE worker invisible on the dashboard forever."""
    with get_cursor(conn) as cur:
        if run_id is not None:
            cur.execute(
                """
                INSERT INTO geodata_worker_leases
                    (id, run_id, started_at, last_seen_at,
                     claim_type, claim_label, claim_started_at)
                VALUES (%s, %s, now(), now(), %s, %s,
                        CASE WHEN %s IS NULL THEN NULL ELSE now() END)
                ON CONFLICT (id) DO UPDATE SET
                    last_seen_at = now(),
                    claim_type = EXCLUDED.claim_type,
                    claim_label = EXCLUDED.claim_label,
                    claim_started_at = CASE WHEN EXCLUDED.claim_type IS NULL
                                            THEN NULL
                                            ELSE COALESCE(geodata_worker_leases.claim_started_at, now())
                                       END
                """,
                (token, run_id, claim_type, claim_label, claim_type),
            )
        else:
            cur.execute(
                """
                UPDATE geodata_worker_leases
                   SET last_seen_at = now(),
                       claim_type = %s, claim_label = %s,
                       claim_started_at = CASE WHEN %s IS NULL THEN NULL ELSE now() END
                 WHERE id = %s
                """,
                (claim_type, claim_label, claim_type, token),
            )


def clear_lease(conn, token: str) -> None:
    with get_cursor(conn) as cur:
        cur.execute("DELETE FROM geodata_worker_leases WHERE id = %s", (token,))


def live_worker_count(conn, run_id: str) -> int:
    """Leases seen in the last 2 minutes — the supervisor's top-up gate."""
    with get_cursor(conn) as cur:
        cur.execute(
            """
            SELECT COUNT(*) AS n FROM geodata_worker_leases
             WHERE run_id = %s AND last_seen_at > now() - interval '2 minutes'
            """,
            (run_id,),
        )
        return int(cur.fetchone()["n"])


def label(claim: dict) -> str:
    """A human-readable line for the per-worker claim strip."""
    iso = claim.get("iso_code") or "?"
    lvl = claim.get("adm_level")
    kind = claim["kind"]
    if kind == "manifest":
        return "enumerating the archive"
    if kind == "boundary_iso":
        return f"boundaries · {iso}"
    if kind == "boundary_range":
        # boundary_range items carry the FILE ADM level (they window a
        # geoBoundaries file); display the APP level = file + 1. IND's
        # neighborhoods file is ADM5 → app L6 — the strip said "L5" while
        # visibly summing 650k neighborhoods (operator-caught mislabel).
        app_lvl = (int(lvl) + 1) if lvl is not None else "?"
        return f"boundaries · {iso} L{app_lvl} (parallel range)"
    if kind == "resolve_global":
        return "resolving global (Earth + orphans + cross-ISO)"
    if kind == "resolve_range":
        return f"resolving · {iso} (parent chains)"
    if kind == "raster_iso":
        return f"rasters · {iso}"
    if kind == "raster_range":
        return f"rasters · {iso} (parallel band)"
    if kind == "attribution_pair":
        return f"attribution · {iso}" + (f" L{lvl}" if lvl is not None else "")
    if kind == "attribution_range":
        return f"attribution · {iso} L{lvl} (window slice)"
    if kind == "finalize_global":
        return "finalizing (planet rollup + validation)"
    if kind == "acceptance_scan":
        return "acceptance scan"
    return kind
