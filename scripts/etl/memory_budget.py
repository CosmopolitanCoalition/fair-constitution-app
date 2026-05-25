"""
memory_budget.py — Hardware-aware chunk-size profile selection (Phase N).

The ETL pipeline does most of its heavy work via large bulk INSERT statements
(jurisdictions geometries) and large UPDATE statements (population_within() per
chunk). The right chunk size depends on how much memory the etl container
actually has — not the host's total RAM. A Pi 4 4 GB running scoped imports
needs much smaller batches than a 32 GB workstation running the world.

This module auto-detects the active container's cgroup memory limit (or the
host's RAM in non-containerised setups) and maps it to a profile of chunk
sizes that the ETL modules import at module load. Same Python code runs on
hardware ranging from "Raspberry Pi 4 1 GB federated install" to "32 GB
workstation full-world load" without any user configuration.

Override with the ETL_MEMORY_BUDGET_BYTES env var (priority 1) for testing,
dev parity, or unusual hosts where auto-detection picks the wrong tier.

Profile tiers and their typical hardware:
    extreme      < 1 GB    Synthetic tests / very constrained
    pi-1gb       1–2 GB    Pi 3 / Pi 4 1 GB. Federated/scoped only.
    pi-2gb       2–4 GB    Pi 4 2 GB. Single-country installs.
    pi-4gb       4–8 GB    Pi 4 4 GB. Mid-size country full ADM tree.
    desktop      8–16 GB   Pi 4 8 GB / Pi 5 / small laptop. World OK.
    workstation  16+ GB    Dev rig / cloud VM. Bigger batches amortise round-trips.

Detection precedence:
    1. ETL_MEMORY_BUDGET_BYTES env var (explicit override)
    2. cgroup v2 limit at /sys/fs/cgroup/memory.max
    3. cgroup v1 limit at /sys/fs/cgroup/memory/memory.limit_in_bytes
    4. /proc/meminfo MemTotal (bare-metal Linux)
    5. Conservative 1 GB default (anything else)
"""
from __future__ import annotations

import logging
import os

logger = logging.getLogger(__name__)


# Sentinel: cgroup files sometimes report a giant value (e.g. 2^63 - some) when
# the limit is not actually set. Anything above ~1 PB is treated as "unbounded"
# and we fall through to the next detection mechanism.
_NO_LIMIT_SENTINEL_THRESHOLD = 1 << 50   # 1 PB


def detect_memory_budget_bytes() -> int:
    """
    Return the memory budget the ETL should size against, in bytes.

    Reads cgroup v2 first, then v1, then /proc/meminfo. The env var override
    takes priority over all of them. Falls back to a conservative 1 GB if
    nothing is detectable — better to under-budget and run slower than to
    over-budget and OOM.
    """
    # 1. Env override.
    env = os.environ.get("ETL_MEMORY_BUDGET_BYTES")
    if env:
        try:
            value = int(env)
            if value > 0:
                return value
        except ValueError:
            logger.warning(
                "ETL_MEMORY_BUDGET_BYTES=%r is not a positive int — ignoring", env
            )

    # 2. cgroup v2 — modern Docker / systemd-cgroup hosts.
    try:
        with open("/sys/fs/cgroup/memory.max") as f:
            raw = f.read().strip()
        if raw and raw != "max":
            limit = int(raw)
            if 0 < limit < _NO_LIMIT_SENTINEL_THRESHOLD:
                return limit
    except (FileNotFoundError, OSError, ValueError):
        pass

    # 3. cgroup v1 — older Docker hosts.
    try:
        with open("/sys/fs/cgroup/memory/memory.limit_in_bytes") as f:
            limit = int(f.read().strip())
        if 0 < limit < _NO_LIMIT_SENTINEL_THRESHOLD:
            return limit
    except (FileNotFoundError, OSError, ValueError):
        pass

    # 4. /proc/meminfo — bare-metal Linux without container limits.
    try:
        with open("/proc/meminfo") as f:
            for line in f:
                if line.startswith("MemTotal:"):
                    parts = line.split()
                    if len(parts) >= 2:
                        kb = int(parts[1])
                        return kb * 1024
    except (FileNotFoundError, OSError, ValueError):
        pass

    # 5. Conservative fallback — choose the smallest profile so a misconfigured
    # host runs slower rather than OOMing.
    return 1024 ** 3   # 1 GB


# Profile table — ordered by ceiling (exclusive). Each tuple is
# (budget_ceiling_bytes, profile_name, chunk_sizes_dict).
#
# The desktop tier (8–16 GB) deliberately matches today's pre-N hardcoded
# values (BATCH_BYTE_LIMIT=64 MB etc.) so dev rigs see no behavior change
# after Phase N lands.
_PROFILES: list[tuple[int, str, dict[str, int]]] = [
    (1 * 1024**3, "extreme", {
        "BATCH_BYTE_LIMIT":    4  * 1024 * 1024,    #  4 MB
        "BATCH_ROW_LIMIT":     500,
        "DB_FETCH_CHUNK_SIZE": 250,
        "RASTER_BATCH_SIZE":   10,
        # Phase T.7: pixel edge of the per-window raster array in
        # raster_attribution.py. Per-window peak memory ≈
        # window_px² × (4B pop + 1B claim + 2B count + 4B label) =
        # window² × 11 bytes ≈ 1024² × 11 = 11 MB per window plus
        # rasterio/Python overhead.
        "RASTER_WINDOW_PX":    1024,
    }),
    (2 * 1024**3, "pi-1gb", {
        "BATCH_BYTE_LIMIT":    8  * 1024 * 1024,    #  8 MB
        "BATCH_ROW_LIMIT":     1000,
        "DB_FETCH_CHUNK_SIZE": 500,
        "RASTER_BATCH_SIZE":   20,
        # 1024-px windows: ~11 MB per window. Many windows per big
        # iso (CAN: ~89° × 43° → 1500+ windows of 1024 px) but each
        # window fits comfortably even under heavy Python + rasterio
        # overhead. Verified against the 2 GB ETL container.
        "RASTER_WINDOW_PX":    1024,
    }),
    (4 * 1024**3, "pi-2gb", {
        "BATCH_BYTE_LIMIT":    16 * 1024 * 1024,    # 16 MB
        "BATCH_ROW_LIMIT":     2500,
        "DB_FETCH_CHUNK_SIZE": 1000,
        "RASTER_BATCH_SIZE":   30,
        "RASTER_WINDOW_PX":    2048,    # ~44 MB per window
    }),
    (8 * 1024**3, "pi-4gb", {
        "BATCH_BYTE_LIMIT":    32 * 1024 * 1024,    # 32 MB
        "BATCH_ROW_LIMIT":     5000,
        "DB_FETCH_CHUNK_SIZE": 1500,
        "RASTER_BATCH_SIZE":   40,
        "RASTER_WINDOW_PX":    4096,    # ~176 MB per window
    }),
    (16 * 1024**3, "desktop", {
        # Matches today's hardcoded values exactly — zero regression on
        # 8–16 GB hosts.
        "BATCH_BYTE_LIMIT":    64 * 1024 * 1024,    # 64 MB
        "BATCH_ROW_LIMIT":     5000,
        "DB_FETCH_CHUNK_SIZE": 2000,
        "RASTER_BATCH_SIZE":   50,
        "RASTER_WINDOW_PX":    4096,    # ~176 MB per window
    }),
    (1 << 50, "workstation", {
        "BATCH_BYTE_LIMIT":    128 * 1024 * 1024,   # 128 MB
        "BATCH_ROW_LIMIT":     10000,
        "DB_FETCH_CHUNK_SIZE": 4000,
        "RASTER_BATCH_SIZE":   100,
        "RASTER_WINDOW_PX":    8192,    # ~704 MB per window
    }),
]


def chunk_profile(budget_bytes: int | None = None) -> tuple[str, dict[str, int]]:
    """
    Return ``(profile_name, chunk_sizes_dict)`` for the given budget.

    If ``budget_bytes`` is ``None``, calls :func:`detect_memory_budget_bytes`
    to detect it.

    The returned dict is a copy so callers can mutate it without affecting the
    profile table.
    """
    if budget_bytes is None:
        budget_bytes = detect_memory_budget_bytes()

    for ceiling, name, values in _PROFILES:
        # `<=` so boundary cases (e.g. exactly 2 GB on the 2 GB ETL
        # container) land in the LOWER tier. A 2 GB container has
        # ~1.8 GB usable after Python + libs + OS overhead, so should
        # be treated as a 1-2 GB host (pi-1gb), not a 2-4 GB host
        # (pi-2gb). Empirical: at 4096-px windows, CAN L=2 in the
        # 2 GB container hit 1.78 GB RSS and would have OOM'd shortly.
        if budget_bytes <= ceiling:
            return name, dict(values)

    # Shouldn't be reachable — last tier ceiling is 1 PB — but be defensive.
    last = _PROFILES[-1]
    return last[1], dict(last[2])
