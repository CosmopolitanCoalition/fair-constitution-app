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
PHASE_FALLTHROUGH = {
    "boundaries": ("raster_iso",),
    "resolving":  ("raster_iso",),
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


def kind_cap(kind: str) -> int | None:
    """The operator's cap for a kind, or None (uncapped — the default).

    ATTRIBUTION IS THE EXCEPTION (2026-08-02, kill #32: seven light pairs
    ran legally-shared and summed past the container): a pair holds its
    whole level's geometry + window buffers (~500-700 MB observed), the
    fattest child class in the engine. Its width therefore derives from
    the memory budget — clamp(budget / 640 MB, 2, pool) — instead of
    running pool-wide. Heavies are additionally serialized by the
    giant-pair floor; this cap bounds the LIGHT class's sum."""
    env_key = _KIND_CAP_ENV.get(kind)
    if env_key is not None:
        raw = os.environ.get(env_key, "")
        if raw.isdigit() and int(raw) > 0:
            return int(raw)
    if kind == "attribution_pair":
        pool = int(os.environ.get("CGA_ETL_POOL_SIZE", "0") or 0) or 12
        try:
            from memory_budget import etl_budget_bytes
            return max(2, min(pool, etl_budget_bytes() // (640 * 1024 * 1024)))
        except Exception:
            return 3
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
            SELECT status, phase,
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
    cap = kind_cap(kind)
    with get_cursor(conn) as cur:
        cur.execute("SELECT pg_advisory_xact_lock(hashtext('cga_geodata_claim'))")
        if cap is not None:
            # The cap counts the kind's FAMILY (a country and its ranges
            # are one budget class; a pair stands alone).
            fam = {"boundary_iso":      ("boundary_iso", "boundary_range"),
                   "raster_iso":        ("raster_iso", "raster_range"),
                   "attribution_pair":  ("attribution_pair", "attribution_range"),
                   }.get(kind, (kind, kind))
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
                    ORDER BY {order}
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
             )
         RETURNING id::text AS id, kind, iso_code, adm_level, dry_run
            """,
            (token, run_id, kinds),
        )
        row = cur.fetchone()
    return dict(row) if row else None


def claim_next(conn, run_id: str, phase: str, token: str,
               lane: str = "small", skip_kinds: frozenset[str] = frozenset()) -> dict | None:
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

    if kind not in skip_kinds:
        row = _attempt_claim(conn, run_id, kind, lane, token)
        if row is not None:
            return row
    for fallback_kind in PHASE_FALLTHROUGH.get(phase, ()):
        if fallback_kind in skip_kinds:
            continue
        row = _attempt_claim(conn, run_id, fallback_kind, lane, token)
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
    cap = kind_cap(fam_parent)
    with get_cursor(conn) as cur:
        cur.execute("SELECT pg_advisory_xact_lock(hashtext('cga_geodata_claim'))")
        if cap is not None:
            cur.execute(
                """
                SELECT COUNT(*) AS n FROM geodata_items
                 WHERE run_id = %s AND status = 'running'
                   AND kind IN (%s, %s)
                """,
                (run_id, fam_parent, kind),
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
        return f"boundaries · {iso} L{lvl} (parallel range)"
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
