#!/bin/bash
# Good Maps durable export — regenerates rows/ + stats/ from the game box (fc_postgres).
# Run from anywhere: writes into the repo's database/good_maps/. See ../README.md.
set -euo pipefail
OUT="$(cd "$(dirname "$0")/.." && pwd)"
PSQL="docker exec fc_postgres psql -U fc_user -d fair_constitution"

declare -A MAPS=(
  [earth_manual_draft1_2ed73bff]=2ed73bff-ec3e-4836-862a-f78e74a06627
  [earth_auto_draft1_79782bdd]=79782bdd-0832-4acc-ad50-3d8cd93af842
  [usa_manual_draft_3cdf1d8b]=3cdf1d8b-bdcf-4047-8670-b5adae480e46
  [usa_initial_a2e0df5a]=a2e0df5a-eedd-409c-8e66-5162450d8e45
)

mkdir -p "$OUT/stats"
for slug in "${!MAPS[@]}"; do mkdir -p "$OUT/rows/$slug"; done

for slug in "${!MAPS[@]}"; do
  MID="${MAPS[$slug]}"
  D="$OUT/rows/$slug"

  $PSQL -c "COPY (SELECT * FROM legislature_district_maps WHERE id = '$MID') TO STDOUT WITH CSV HEADER" > "$D/map.csv"

  $PSQL -c "COPY (SELECT * FROM legislature_districts WHERE map_id = '$MID' AND deleted_at IS NULL ORDER BY district_number, id) TO STDOUT WITH CSV HEADER" > "$D/districts.csv"

  $PSQL -c "COPY (SELECT ldj.* FROM legislature_district_jurisdictions ldj
    WHERE ldj.district_id IN (SELECT id FROM legislature_districts WHERE map_id = '$MID' AND deleted_at IS NULL)
    ORDER BY ldj.district_id, COALESCE(ldj.jurisdiction_id::text,''), COALESCE(ldj.subdivision_id::text,'')) TO STDOUT WITH CSV HEADER" > "$D/junctions.csv"

  $PSQL -c "COPY (SELECT * FROM district_subdivisions WHERE map_id = '$MID' AND deleted_at IS NULL ORDER BY label, id) TO STDOUT WITH CSV HEADER" > "$D/subdivisions.csv"

  $PSQL -c "COPY (
    SELECT sj.name AS scope, d.district_number, d.seats, d.fractional_seats,
           d.floor_override,
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
    ORDER BY sj.name, d.district_number) TO STDOUT WITH CSV HEADER" > "$OUT/stats/districts_$slug.csv"

  echo "exported $slug"
done

for pair in "earth:2ed73bff-ec3e-4836-862a-f78e74a06627,79782bdd-0832-4acc-ad50-3d8cd93af842" \
            "usa:3cdf1d8b-bdcf-4047-8670-b5adae480e46,a2e0df5a-eedd-409c-8e66-5162450d8e45"; do
  leg="${pair%%:*}"; ids="${pair#*:}"
  IN="'${ids/,/\',\'}'"
  $PSQL -c "COPY (
    SELECT m.name AS map, sj.name AS scope, count(*) AS districts, sum(d.seats) AS seats,
           round(sum(d.fractional_seats)::numeric,4) AS frac_sum,
           round(sum(abs(d.fractional_seats - d.seats))::numeric,4) AS fit_gap,
           count(*) FILTER (WHERE NOT d.is_contiguous) AS noncontig,
           round(avg(d.convex_hull_ratio)::numeric,4) AS avg_chr,
           count(*) FILTER (WHERE (d.seats < 5 AND NOT d.floor_override) OR d.seats > 9) AS band_violations,
           count(*) FILTER (WHERE d.floor_override) AS floor_overrides,
           d.jurisdiction_id AS scope_jurisdiction_id
    FROM legislature_districts d
    JOIN legislature_district_maps m ON m.id = d.map_id
    JOIN jurisdictions sj ON sj.id = d.jurisdiction_id
    WHERE d.map_id IN ($IN) AND d.deleted_at IS NULL
    GROUP BY m.name, sj.name, d.jurisdiction_id
    ORDER BY sj.name, m.name) TO STDOUT WITH CSV HEADER" > "$OUT/stats/scope_rollup_$leg.csv"

  $PSQL -c "COPY (
    WITH member_keys AS (
      SELECT d.map_id, d.jurisdiction_id AS scope_id, d.id AS district_id, d.seats,
             COALESCE(ldj.jurisdiction_id::text,
                      'sub:'||ds.label||':'||ds.population||':'||md5(ST_AsBinary(ds.geom))) AS mkey
      FROM legislature_districts d
      JOIN legislature_district_jurisdictions ldj ON ldj.district_id = d.id
      LEFT JOIN district_subdivisions ds ON ds.id = ldj.subdivision_id
      WHERE d.map_id IN ($IN) AND d.deleted_at IS NULL
    ), dsig AS (
      SELECT map_id, scope_id, district_id,
             seats::text || ':' || string_agg(mkey, ',' ORDER BY mkey) AS sig
      FROM member_keys GROUP BY map_id, scope_id, district_id, seats
    )
    SELECT m.name AS map, sj.name AS scope, md5(string_agg(sig, '|' ORDER BY sig)) AS fingerprint,
           count(*) AS districts, s.scope_id AS scope_jurisdiction_id
    FROM dsig s
    JOIN legislature_district_maps m ON m.id = s.map_id
    JOIN jurisdictions sj ON sj.id = s.scope_id
    GROUP BY m.name, sj.name, s.scope_id
    ORDER BY sj.name, m.name) TO STDOUT WITH CSV HEADER" > "$OUT/stats/fingerprints_$leg.csv"

  echo "rollup+fingerprints $leg"
done
echo "done -> $OUT"
