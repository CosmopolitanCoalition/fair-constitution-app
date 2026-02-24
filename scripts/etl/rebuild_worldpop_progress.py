"""
rebuild_worldpop_progress.py

Rebuild the worldpop section of progress.json from current DB state.
Run once before starting the full global worldpop run when progress.json
has been lost/emptied but population data already exists in the DB.

Usage (inside ETL container):
    python rebuild_worldpop_progress.py
"""
import json
import os
from datetime import datetime, timezone
from pathlib import Path

from db import get_connection, get_cursor

PROGRESS_FILE = Path("/etl/progress.json")


def main():
    conn = get_connection()
    with get_cursor(conn) as cur:
        # Find all (iso_code, adm_level) pairs where population has been set
        cur.execute("""
            SELECT iso_code, adm_level,
                   COUNT(*) FILTER (WHERE population IS NOT NULL) AS updated_rows
            FROM jurisdictions
            WHERE source IN ('geoboundaries', 'synthetic')
              AND adm_level >= 1
              AND deleted_at IS NULL
            GROUP BY iso_code, adm_level
            HAVING COUNT(*) FILTER (WHERE population IS NOT NULL) > 0
            ORDER BY iso_code, adm_level
        """)
        rows = cur.fetchall()
    conn.close()

    if not rows:
        print("No population data found in DB — nothing to rebuild.")
        return

    # Load existing progress.json (preserves geoboundaries section, timestamps, etc.)
    if PROGRESS_FILE.exists():
        progress = json.loads(PROGRESS_FILE.read_text())
        print(f"Loaded existing progress.json ({PROGRESS_FILE})")
    else:
        progress = {"started_at": datetime.now(timezone.utc).isoformat()}
        print("No existing progress.json — creating fresh.")

    wp = progress.setdefault("worldpop", {})

    now = datetime.now(timezone.utc).isoformat()
    country_levels: dict[str, list[int]] = {}

    for iso_code, adm_level, updated_rows in rows:
        key = f"{iso_code}:adm{adm_level}"
        wp[key] = {
            "status":    "done",
            "updated":   updated_rows,
            "timestamp": now,
        }
        country_levels.setdefault(iso_code, []).append(adm_level)

    # Write top-level country key for each fully-processed country
    for iso_code, levels in country_levels.items():
        total_updated = sum(
            wp[f"{iso_code}:adm{lvl}"]["updated"] for lvl in levels
        )
        wp[iso_code] = {
            "status":    "done",
            "updated":   total_updated,
            "timestamp": now,
        }

    # Atomic save: write to .tmp then os.replace (crash-safe)
    tmp = PROGRESS_FILE.with_suffix(".json.tmp")
    tmp.write_text(json.dumps(progress, indent=2, default=str))
    os.replace(tmp, PROGRESS_FILE)

    print(f"\nRebuilt worldpop progress: {len(country_levels)} countries, "
          f"{len(rows)} (iso3, adm_level) pairs marked done.\n")
    print(f"{'ISO3':<8}  ADM levels processed")
    print("-" * 40)
    for iso_code in sorted(country_levels):
        levels = sorted(country_levels[iso_code])
        level_str = ", ".join(f"adm{l}" for l in levels)
        total = wp[iso_code]["updated"]
        print(f"  {iso_code:<6}  [{level_str}]  ({total:,} rows)")

    print(f"\nProgress saved to {PROGRESS_FILE}")
    print("You can now run: python seed_database.py")


if __name__ == "__main__":
    main()
