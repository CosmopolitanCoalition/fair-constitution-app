#!/bin/bash
# Export the per-district stats CSV for ONE map (any map_id) to stdout.
# Usage: export_map_stats.sh <map_id> > out.csv
set -euo pipefail
MID="$1"
docker exec fc_postgres psql -U fc_user -d fair_constitution -c "COPY (
  SELECT sj.name AS scope, d.district_number, d.seats, d.fractional_seats,
         d.floor_override, d.bonus_seats,
         d.target_population, d.actual_population,
         d.convex_hull_ratio, d.num_geom_parts, d.is_contiguous,
         (SELECT string_agg(COALESCE(mj.name, 'sub:'||ds.label||' (pop '||ds.population||')'), '; '
                            ORDER BY COALESCE(mj.name, 'sub:'||ds.label||' (pop '||ds.population||')'))
          FROM legislature_district_jurisdictions ldj
          LEFT JOIN jurisdictions mj ON mj.id = ldj.jurisdiction_id
          LEFT JOIN district_subdivisions ds ON ds.id = ldj.subdivision_id
          WHERE ldj.district_id = d.id) AS members,
         d.id AS district_id, d.jurisdiction_id AS scope_jurisdiction_id
  FROM legislature_districts d
  JOIN jurisdictions sj ON sj.id = d.jurisdiction_id
  WHERE d.map_id = '$MID' AND d.deleted_at IS NULL
  ORDER BY sj.name, d.district_number) TO STDOUT WITH CSV HEADER"
