"""
heartbeat.py — Progress signal for the Step 2 setup wizard.

Two control files, atomic-write pattern (mirror supervisor.write_atomic):

  /etl/control/current.json   — per-country preview ("which country is the
                                 minimap rendering right now"). Stays
                                 single-iso during WorldPop phase so the
                                 preview doesn't flicker per sub-jurisdiction.
                                 Shape (legacy fields kept for backward
                                 compat — frontend can ignore the ones it
                                 doesn't need):

                                   {
                                     "id":               "uuid|null",
                                     "name":             "United States",
                                     "iso_code":         "USA",
                                     "adm_level":        1,
                                     "phase":            "geoboundaries"|"transition"|"worldpop"|"cleanup",
                                     "sub_phase":        "free-form label",
                                     "started_at":       "ISO8601",
                                     "queue_preview":    ["URY","UZB"],
                                     "population":       null,
                                     "area_km2":         null,
                                     "progress_current": int|null,
                                     "progress_total":   int|null
                                   }

  /etl/control/bars.json      — Phase P stacked-progress-bar state. The UI
                                 renders these as a vertical stack of bars
                                 with X / Y (Z%) + elapsed + ETA. Schema:

                                   {
                                     "phase":  "geoboundaries"|"cleanup"|"worldpop"|"complete"|null,
                                     "geoboundaries_bars": [Bar, ...],
                                     "cleanup_bars":       [Bar, ...],
                                     "worldpop_country_summary": {
                                         "done":         int,    // countries finished
                                         "total":        int,    // countries total
                                         "current_iso":  str|null
                                     } | null,
                                     "worldpop_current_country_bars": [Bar, ...],
                                     "active_key": "wp:USA:adm0" | null
                                   }

                                 Each Bar:
                                   {
                                     "key":          "gb:adm0" | "cleanup:synth" | "wp:USA:adm2",
                                     "label":        "Boundaries — Country (ADM0)",
                                     "current":     int,
                                     "total":       int,
                                     "status":      "pending"|"running"|"done",
                                     "started_at":  "ISO8601" | null,
                                     "completed_at":"ISO8601" | null
                                   }

                                 Bar key category routes the bar into the right
                                 list:
                                   gb:*       → geoboundaries_bars
                                   cleanup:*  → cleanup_bars
                                   wp:*       → worldpop_current_country_bars
                                              (replaced wholesale on each
                                              worldpop_advance_country call)

API
---
write_current(**fields)             — write current.json (legacy; kept)
clear_current()                     — clear current.json
clear_all()                         — clear both files (start of run / end)

set_phase(phase)                    — write bars.json's `phase` field
bar_start(key, label, total)        — mark bar running, set started_at
bar_update(key, current)            — update current count (no status change)
bar_complete(key, current=None)     — mark bar done, set completed_at; defaults
                                     current to `total` if not provided
worldpop_advance_country(iso, idx,  — set worldpop_country_summary, reset
                        total)       worldpop_current_country_bars to []
"""

import json
import time
from datetime import datetime, timezone
from pathlib import Path

# Phase P.1.1: per-bar disk-write throttle. Allows callers to invoke
# bar_update at any cadence (even per-feature) without burning I/O — the
# disk write only fires when ≥ _BAR_THROTTLE_SEC has elapsed since the
# last write for this specific bar. Throttle-skipped values are NOT lost:
# the most-recent value is stashed in `_bar_pending` and gets flushed on
# the next non-throttled call (or by bar_complete, which bypasses the
# throttle entirely).
_BAR_THROTTLE_SEC: float = 0.25         # 4 Hz max disk write per bar
_bar_last_write_at: dict[str, float] = {}
_bar_pending_value: dict[str, int] = {}

CURRENT = Path("/etl/control/current.json")
BARS    = Path("/etl/control/bars.json")


# ─── Per-country preview heartbeat ──────────────────────────────────────────

def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def write_current(**fields) -> None:
    """Write /etl/control/current.json atomically. Silently swallows errors
    so a broken heartbeat never takes down an import run."""
    try:
        CURRENT.parent.mkdir(parents=True, exist_ok=True)
        payload = {
            "id":               fields.get("id"),
            "name":             fields.get("name"),
            "iso_code":         fields.get("iso_code"),
            "adm_level":        fields.get("adm_level"),
            "phase":            fields.get("phase"),
            "sub_phase":        fields.get("sub_phase"),
            "started_at":       fields.get("started_at") or now_iso(),
            "queue_preview":    fields.get("queue_preview") or [],
            "population":       fields.get("population"),
            "area_km2":         fields.get("area_km2"),
            "progress_current": fields.get("progress_current"),
            "progress_total":   fields.get("progress_total"),
        }
        tmp = CURRENT.with_suffix(".tmp")
        tmp.write_text(json.dumps(payload, default=str))
        tmp.replace(CURRENT)
    except OSError:
        pass


def clear_current() -> None:
    """Remove the current-jurisdiction heartbeat file."""
    try:
        CURRENT.unlink(missing_ok=True)
    except OSError:
        pass


# ─── Stacked progress bars (Phase P.1) ──────────────────────────────────────

def _empty_bars_state() -> dict:
    return {
        "phase":                          None,
        "geoboundaries_bars":             [],
        "cleanup_bars":                   [],
        "worldpop_country_summary":       None,
        "worldpop_current_country_bars":  [],
        "active_key":                     None,
    }


def _load_bars() -> dict:
    try:
        return json.loads(BARS.read_text())
    except (OSError, ValueError):
        return _empty_bars_state()


def _write_bars(state: dict) -> None:
    try:
        BARS.parent.mkdir(parents=True, exist_ok=True)
        tmp = BARS.with_suffix(".tmp")
        tmp.write_text(json.dumps(state, default=str))
        tmp.replace(BARS)
    except OSError:
        pass


def _bar_list_for_key(state: dict, key: str) -> list:
    """Return the bar list (geoboundaries_bars / cleanup_bars /
    worldpop_current_country_bars) appropriate for this key prefix."""
    if key.startswith("gb:"):
        return state["geoboundaries_bars"]
    if key.startswith("cleanup:"):
        return state["cleanup_bars"]
    if key.startswith("wp:"):
        return state["worldpop_current_country_bars"]
    # Unknown prefix — store under cleanup_bars as a fallback so it still
    # surfaces somewhere rather than getting silently dropped.
    return state["cleanup_bars"]


def _find_bar(bar_list: list, key: str) -> dict | None:
    return next((b for b in bar_list if b.get("key") == key), None)


def set_phase(phase: str) -> None:
    """Write the top-level phase indicator. Frontend uses this to choose
    which sub-stack to highlight."""
    try:
        state = _load_bars()
        state["phase"] = phase
        _write_bars(state)
    except Exception:
        pass


def bar_register(key: str, label: str, total: int = 0, unit: str = "features") -> None:
    """Pre-register a bar in 'pending' state — visible in the UI but not
    yet active. Used at run start to surface the full pipeline (all ADM
    levels, all major cleanup steps) so the operator sees the WHOLE flow
    at a glance, with bars transitioning pending → running → done as the
    ETL works through them.

    `unit` is the plural noun the UI shows alongside the count ("counties"
    instead of generic "features"). Default keeps backward compat for
    callers that don't supply one.

    No-op if the bar already exists (so re-registration during resumes
    doesn't clobber a running/done bar's timestamps)."""
    try:
        state = _load_bars()
        bar_list = _bar_list_for_key(state, key)
        if _find_bar(bar_list, key) is not None:
            return   # already registered — preserve its current state
        bar_list.append({
            "key":          key,
            "label":        label,
            "current":      0,
            "total":        max(int(total or 0), 0),
            "unit":         unit or "features",
            "status":       "pending",
            "started_at":   None,
            "completed_at": None,
        })
        _write_bars(state)
    except Exception:
        pass


def bar_start(key: str, label: str, total: int, unit: str = "features") -> None:
    """Mark a bar as running. Creates the bar if absent; transitions an
    existing 'pending' bar to 'running' (sets started_at); resets a
    previously-done bar (re-run scenarios). `unit` is the UI's plural
    noun (e.g. 'counties', 'cantons')."""
    try:
        state = _load_bars()
        bar_list = _bar_list_for_key(state, key)
        existing = _find_bar(bar_list, key)
        bar = {
            "key":          key,
            "label":        label,
            "current":      0,
            "total":        max(int(total or 0), 0),
            "unit":         unit or "features",
            "status":       "running",
            "started_at":   now_iso(),
            "completed_at": None,
        }
        if existing:
            existing.update(bar)
        else:
            bar_list.append(bar)
        state["active_key"] = key
        _write_bars(state)
    except Exception:
        pass


def bar_update(key: str, current: int, force: bool = False, total: int | None = None) -> None:
    """Update the current count on a bar without changing its status.

    Disk writes throttle to ~4 Hz per bar by default — callers can invoke
    this per-feature without burning I/O. When throttled, the latest value
    is stashed in `_bar_pending_value[key]` and gets flushed on the next
    non-throttled call. `force=True` bypasses the throttle entirely (used
    by bar_complete and by the outer per-country bar advance that needs
    to land the country's final tally).

    `total` is optional — when the caller couldn't supply a final total at
    bar_start time (e.g. WorldPop raster load doesn't know the tile count
    until rasterio opens the file), pass it along on the first update so
    the bar can render with a proper percentage instead of stuck at 0/0.
    """
    current_int = max(int(current or 0), 0)
    try:
        if not force:
            now = time.monotonic()
            last = _bar_last_write_at.get(key, 0.0)
            if (now - last) < _BAR_THROTTLE_SEC:
                # Within throttle window — stash the value for next flush
                _bar_pending_value[key] = current_int
                return

        state = _load_bars()
        bar_list = _bar_list_for_key(state, key)
        bar = _find_bar(bar_list, key)
        if bar is None:
            return  # silently ignore — caller should bar_start first
        bar["current"] = current_int
        if total is not None and total > 0:
            bar["total"] = int(total)
        if bar.get("status") != "running":
            bar["status"] = "running"
            if not bar.get("started_at"):
                bar["started_at"] = now_iso()
        state["active_key"] = key
        _write_bars(state)
        _bar_last_write_at[key] = time.monotonic()
        _bar_pending_value.pop(key, None)
    except Exception:
        pass


def bar_complete(key: str, current: int | None = None, total: int | None = None) -> None:
    """Mark a bar done. Defaults current to total when not provided so the
    bar visually fills to 100 % even when the caller didn't track exact
    final count. Always bypasses the bar_update throttle so the final
    state lands on disk regardless of how recent the previous write was.

    `total` lets callers correct the bar's headline denominator at
    completion time — needed when the running phase counted "iterations"
    (e.g. raster tile grid slots, including skipped ones) but the final
    "done" headline should reflect "actual work" (loaded tiles only). Pass
    the same value as `current` to render 100 % naturally."""
    try:
        state = _load_bars()
        bar_list = _bar_list_for_key(state, key)
        bar = _find_bar(bar_list, key)
        if bar is None:
            # Caller never started — synthesise a "done" bar so the UI sees
            # the step happened.
            bar = {
                "key":          key,
                "label":        key,
                "current":      current if current is not None else 0,
                "total":        total if total is not None
                                else (current if current is not None else 0),
                "status":       "done",
                "started_at":   now_iso(),
                "completed_at": now_iso(),
            }
            bar_list.append(bar)
        else:
            if current is not None:
                bar["current"] = max(int(current), 0)
            elif bar.get("total"):
                bar["current"] = bar["total"]
            if total is not None and total > 0:
                bar["total"] = int(total)
            bar["status"]       = "done"
            bar["completed_at"] = now_iso()
        if state.get("active_key") == key:
            state["active_key"] = None
        _write_bars(state)
        # Clear throttle state for this key so a future bar_start reset
        # doesn't get stale-throttled.
        _bar_last_write_at.pop(key, None)
        _bar_pending_value.pop(key, None)
    except Exception:
        pass


def worldpop_register_summary(total: int) -> None:
    """Phase P.1.1: pre-register the WorldPop "Countries X / Y" summary
    bar in a pending state at the START of Phase 2 — before any country
    is processed — so the operator sees the population progress slot
    before the first country lands. The first worldpop_advance_country()
    call then promotes it from idle 0/N to 0/N current_iso=...."""
    try:
        state = _load_bars()
        # Don't clobber an in-progress summary on warm re-runs.
        existing = state.get("worldpop_country_summary")
        if existing and existing.get("started_at"):
            return
        state["worldpop_country_summary"] = {
            "done":        0,
            "total":       max(int(total), 0),
            "current_iso": None,
            "started_at":  now_iso(),
        }
        _write_bars(state)
    except Exception:
        pass


def worldpop_advance_country(iso: str, idx: int, total: int) -> None:
    """Move WorldPop processing to a new country. Resets the current-country
    bars list (the previous country's bars are consolidated into the
    summary count). idx is 1-based (1st country → idx=1)."""
    try:
        state = _load_bars()
        existing = state.get("worldpop_country_summary") or {}
        state["worldpop_country_summary"] = {
            "done":        max(int(idx) - 1, 0),
            "total":       max(int(total), 0),
            "current_iso": iso,
            # Preserve the started_at from worldpop_register_summary so the
            # phase-elapsed timer doesn't reset every country.
            "started_at":  existing.get("started_at") or now_iso(),
        }
        state["worldpop_current_country_bars"] = []
        state["active_key"] = None
        _write_bars(state)
    except Exception:
        pass


def worldpop_mark_complete() -> None:
    """Called when the last WorldPop country finishes. Marks done == total
    in the summary so the UI reads as 100 %."""
    try:
        state = _load_bars()
        summ = state.get("worldpop_country_summary")
        if summ:
            summ["done"] = summ.get("total", summ.get("done", 0))
            summ["current_iso"] = None
        state["worldpop_current_country_bars"] = []
        state["active_key"] = None
        _write_bars(state)
    except Exception:
        pass


def clear_bars() -> None:
    """Remove bars.json (call at clear_current() / start of new --fresh run)."""
    try:
        BARS.unlink(missing_ok=True)
    except OSError:
        pass


def clear_all() -> None:
    """Clear both heartbeat files. Useful at run start (--fresh) and end."""
    clear_current()
    clear_bars()


# ─── Phase P.3: structured event markers ────────────────────────────────────
#
# Operator-relevant events (orphan flags, warnings, errors, post-pass summary)
# get surfaced in the UI as toasts / badges instead of buried in the scrolling
# log. Python emits a marker line that supervisor.py forwards to /etl/etl.log;
# SetupController::mapDataProgress regex-parses the marker and returns a
# structured `events` array alongside `bars`. Frontend's <EventToasts />
# renders new errors as persistent banners, warnings as auto-dismissing toasts,
# info events as a scrolling feed.
#
# Marker format (one line, JSON suffix — easy to regex + JSON.parse):
#   [EVT] {"level":"warn","type":"orphan_inline","iso":"FRA","name":"Cayenne",...}
#
# Standard fields (all optional except level, type):
#   level:    "info" | "warn" | "error"
#   type:     short slug — "orphan_inline" | "post_pass_summary" | "raster_load_failed" | ...
#   iso:      ISO3 code, when relevant
#   name:     jurisdiction or feature name, when relevant
#   adm_level: app-level integer, when relevant
#   phase:    "geoboundaries" | "worldpop" | "cleanup", when relevant
#   msg:      free-text human-readable description
#   ...:      any other typed metadata the consumer cares about

import logging as _logging
_event_log = _logging.getLogger("seed_database.events")


def emit_event(level: str, type: str, **fields) -> None:
    """Emit a structured event marker for the wizard UI to surface.
    Best-effort — never raises (a failed event log shouldn't kill the import).
    """
    try:
        payload = {"level": str(level or "info"), "type": str(type or "event")}
        for k, v in fields.items():
            if v is None:
                continue
            payload[k] = v
        _event_log.info("[EVT] %s", json.dumps(payload, default=str))
    except Exception:
        pass
