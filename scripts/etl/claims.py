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
PHASE_KIND = {
    "enumerating": "manifest",
    "boundaries":  "boundary_iso",
    "resolving":   "resolve_global",
    "rasters":     "raster_iso",
    "attribution": "attribution_pair",
    "finalizing":  "finalize_global",
    "scanning":    "acceptance_scan",
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
    """The operator's cap for a kind, or None (uncapped — the default)."""
    env_key = _KIND_CAP_ENV.get(kind)
    if env_key is None:
        return None
    raw = os.environ.get(env_key, "")
    if raw.isdigit() and int(raw) > 0:
        return int(raw)
    return None


# THE HEAVY LANE (2026-08-02, cgroup OOM kills at full field — AUTOSCALE
# PARITY: AutoscaleClaims.HEAVY_TIER + heavyWorkerCap, the one cap the
# autoscaler never retired). A giant boundary FILE costs Python-side memory
# no chunk profile can shrink — the raw multi-hundred-MB feature string must
# exist to be parsed — so ten concurrent monsters bust the etl cgroup and
# the kernel reaps children (exit -9). At most ceil(20% of pool) workers may
# hold a HEAVY boundary item at once; everyone else flows light work at full
# width. DRAIN RULE (autoscale parity): when only heavy work remains, the
# cap effectively opens because light claims return nothing and workers idle
# only while the heavy slots are full — the tail still drains serially,
# which is exactly the safe posture for monsters.
_HEAVY_BYTES_DEFAULT = 48 * 1024 * 1024   # a file above ~48 MiB carries giant features


def heavy_bytes() -> int:
    raw = os.environ.get("CGA_ETL_HEAVY_BYTES", "")
    if raw.isdigit() and int(raw) > 0:
        return int(raw)
    return _HEAVY_BYTES_DEFAULT


def heavy_cap() -> int:
    raw = os.environ.get("CGA_ETL_HEAVY_CAP", "")
    if raw.isdigit() and int(raw) > 0:
        return int(raw)
    pool = int(os.environ.get("CGA_ETL_POOL_SIZE", "0") or 0)
    if pool <= 0:
        pool = 10
    return max(1, -(-pool // 5))   # ceil(20% of pool) — 10 → 2


def heavy_drain_cap() -> int:
    """THE DRAIN RULE (autoscale parity, operator observation 2026-08-02:
    'light is cleared, that should leave room for the big stuff'): when NO
    light work remains pending, the heavy door widens — but memory-derived,
    never flung open (ten concurrent giants IS the crash we already had).
    One giant's Python residency ≈ its largest feature + batch, observed
    ~300–600 MB; the drain cap funds ~one giant per 512 MiB of container
    budget, floor = the normal cap. 1.9 GiB box → 3. Env-overridable."""
    raw = os.environ.get("CGA_ETL_HEAVY_DRAIN_CAP", "")
    if raw.isdigit() and int(raw) > 0:
        return int(raw)
    try:
        from memory_budget import etl_budget_bytes
        by_mem = int(etl_budget_bytes() // (512 * 1024 * 1024))
    except Exception:
        by_mem = 0
    return max(heavy_cap(), by_mem)


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


def claim_next(conn, run_id: str, phase: str, token: str) -> dict | None:
    """Claim the next pending item of the phase's kind, atomically, or None."""
    kind = PHASE_KIND.get(phase)
    if kind is None or kind == "acceptance_scan":
        # The acceptance scan is the ONE Laravel-side item (the pump dispatches
        # GeodataAcceptanceScanJob — no docker-in-docker). A Python worker must
        # never claim it: closing it here would skip the scan entirely.
        return None

    # Memory governor, HARD since the 02:59 postgres OOM: the count-then-claim
    # pair runs inside ONE transaction serialized by an advisory xact lock, so
    # two workers can never both see a free slot (the soft cap collapsed under
    # the 10-worker cold-start burst — all five monsters claimed at once and
    # their concurrent giant-geometry inserts OOM-killed a PG backend at
    # 2.4 GB anon-rss). Mirrors AutoscaleClaims::claimScope's heavy-lane
    # gate. Serializing ALL claims is fine: a claim is milliseconds against
    # minutes-to-hours of item work.
    cap = kind_cap(kind)
    with get_cursor(conn) as cur:
        cur.execute("SELECT pg_advisory_xact_lock(hashtext('cga_geodata_claim'))")
        if cap is not None:
            cur.execute(
                """
                SELECT COUNT(*) AS n FROM geodata_items
                 WHERE run_id = %s AND kind = %s AND status = 'running'
                """,
                (run_id, kind),
            )
            if int(cur.fetchone()["n"]) >= cap:
                return None

        # Heavy lane (boundaries only — where the giant-file evidence is):
        # when the heavy slots are full, this claim is restricted to LIGHT
        # items; heavy items wait for a slot. DRAIN RULE: once no light work
        # remains pending, the door widens to the memory-derived drain cap.
        # Runs under the same advisory lock, so the counts can never race.
        heavy_filter = ""
        params_extra: tuple = ()
        if kind == "boundary_iso":
            hb = heavy_bytes()
            cur.execute(
                """
                SELECT COUNT(*) AS n FROM geodata_items
                 WHERE run_id = %s AND kind = %s AND status = 'running'
                   AND est_cost > %s
                """,
                (run_id, kind, hb),
            )
            heavy_running = int(cur.fetchone()["n"])
            cap = heavy_cap()
            if heavy_running >= cap:
                cur.execute(
                    """
                    SELECT 1 FROM geodata_items
                     WHERE run_id = %s AND kind = %s AND status = 'pending'
                       AND est_cost <= %s LIMIT 1
                    """,
                    (run_id, kind, hb),
                )
                light_pending = cur.fetchone() is not None
                if not light_pending:
                    cap = heavy_drain_cap()
            if heavy_running >= cap:
                heavy_filter = " AND est_cost <= %s"
                params_extra = (hb,)

        cur.execute(
            f"""
            UPDATE geodata_items
               SET status = 'running', claim_token = %s,
                   started_at = COALESCE(started_at, now()), updated_at = now()
             WHERE id = (
                   SELECT id FROM geodata_items
                    WHERE run_id = %s AND status = 'pending' AND kind = %s{heavy_filter}
                    ORDER BY position
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
             )
         RETURNING id::text AS id, kind, iso_code, adm_level, dry_run
            """,
            (token, run_id, kind) + params_extra,
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
        cur.execute(
            """
            UPDATE geodata_items
               SET status = %s, reason = %s, metrics = %s::jsonb,
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
    if kind == "resolve_global":
        return "resolving global (Earth + orphans + cross-ISO)"
    if kind == "raster_iso":
        return f"rasters · {iso}"
    if kind == "attribution_pair":
        return f"attribution · {iso}" + (f" L{lvl}" if lvl is not None else "")
    if kind == "finalize_global":
        return "finalizing (planet rollup + validation)"
    if kind == "acceptance_scan":
        return "acceptance scan"
    return kind
