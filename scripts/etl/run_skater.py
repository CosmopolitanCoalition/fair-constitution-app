"""
run_skater.py — SKATER regionalization for legislative district drawing.

STATUS: Phase 2 stub — not yet implemented.

This module will use the SKATER (Spatial 'K'luster Analysis by Tree Edge
Removal) algorithm from the spopt library to generate computationally
optimal legislative districts for a given jurisdiction.

Constitutional constraints to be enforced during district generation:
  - Population equality: each district must be within ±5% of ideal
  - Spatial contiguity: all districts must be geographically connected
  - Seat count: target_seats must be in [legislature_min_seats (5),
    legislature_max_seats (9)] from constitutional_settings

Dependencies (all installed in the ETL Dockerfile):
  spopt==0.6.0
  libpysal==4.9.2
  scipy==1.13.0
  geopandas==0.14.4

Usage (once implemented):
    docker compose exec etl python run_skater.py \\
        --jurisdiction-id <UUID> \\
        --target-seats 7

Inputs (when implemented):
  - Jurisdiction UUID (parent jurisdiction to be districted)
  - Population grid from WorldPop raster (already loaded in DB)
  - Sub-jurisdictions at adm_level+1 (the building blocks for districts)
  - Target seat count from constitutional_settings

Outputs (when implemented):
  - New rows in a `districts` table (to be created in a future migration)
  - Updated legislature_members.district_id foreign keys
"""

import logging

logger = logging.getLogger(__name__)


def run_skater(
    jurisdiction_id: str,
    target_seats: int,
    progress: dict = None,
    log: logging.Logger = None,
) -> list[str]:
    """
    Generate legislative districts for a jurisdiction using SKATER.

    Args:
        jurisdiction_id: UUID of the parent jurisdiction to district
        target_seats:    Number of districts to generate
        progress:        Shared progress dict
        log:             Logger instance

    Returns:
        List of generated district UUIDs (once implemented)

    Raises:
        NotImplementedError: Always — this is a Phase 2 stub
    """
    raise NotImplementedError(
        "SKATER regionalization is a Phase 2 feature. "
        "Complete import_geoboundaries and import_worldpop first, "
        "then implement district drawing in Phase 2."
    )


if __name__ == "__main__":
    import argparse
    parser = argparse.ArgumentParser(description="SKATER district generator (Phase 2 stub)")
    parser.add_argument("--jurisdiction-id", required=True, help="UUID of parent jurisdiction")
    parser.add_argument("--target-seats", type=int, required=True, help="Number of districts")
    args = parser.parse_args()

    logging.basicConfig(level=logging.INFO)
    run_skater(
        jurisdiction_id=args.jurisdiction_id,
        target_seats=args.target_seats,
    )
