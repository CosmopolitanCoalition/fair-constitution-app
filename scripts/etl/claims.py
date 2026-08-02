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
    with get_cursor(conn) as cur:
        cur.execute(
            """
            UPDATE geodata_items
               SET status = 'running', claim_token = %s,
                   started_at = COALESCE(started_at, now()), updated_at = now()
             WHERE id = (
                   SELECT id FROM geodata_items
                    WHERE run_id = %s AND status = 'pending' AND kind = %s
                    ORDER BY position
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
             )
         RETURNING id::text AS id, kind, iso_code, adm_level, dry_run
            """,
            (token, run_id, kind),
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
                claim_label: str | None = None) -> None:
    """Heartbeat the lease; stamp/clear the current claim for the UI strip."""
    with get_cursor(conn) as cur:
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
