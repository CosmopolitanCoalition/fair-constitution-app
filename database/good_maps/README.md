# Good Maps — the standard maps and their detailed statistics

**Standing order (operator, 2026-08-23):** the two manually finished maps below form the basis
for **Good Maps**. They and their detailed statistics are saved here durably — committed and
pushed — so they can never be lost the way the old good-map statistics were. Auto Districting is
then refined iteratively until it reaches these maps **or better on all counts**.

## The standards

| Legislature | legislature_id | THE STANDARD map | map_id | Districts | Seats |
|---|---|---|---|---|---|
| Earth | `60bf845b-b770-48b3-8689-4af40bc810a8` | **Manual Map Draft 1** | `2ed73bff-ec3e-4836-862a-f78e74a06627` | 282 | 2003 = budget, zero drift |
| United States of America | `ccdef50b-a409-4243-b089-b65f0a36777d` | **Manual Map Draft** | `3cdf1d8b-bdcf-4047-8670-b5adae480e46` | 100 | 702 = budget, zero drift |

Comparison baselines exported alongside (also live on the box as of 2026-08-23):
Earth **Auto Map Draft 1** `79782bdd-0832-4acc-ad50-3d8cd93af842` (283 districts / 2003) and
USA **Initial Map (bootstrap)** `a2e0df5a-eedd-409c-8e66-5162450d8e45` (105 / 702).
Earth's Initial Map (bootstrap) and "Copy of Initial" were deleted from the box before this
export; their measured stats survive in the historical tables at the bottom.

## What is saved

```
rows/<map>/map.csv            legislature_district_maps row (SELECT *)
rows/<map>/districts.csv      live districts, ORDER BY district_number, id (SELECT *)
rows/<map>/junctions.csv      legislature_district_jurisdictions rows for those districts
rows/<map>/subdivisions.csv   district_subdivisions owned by the map (geom/centroid = hex EWKB)
stats/districts_<map>.csv     per-district: scope, number, seats, fractional_seats, floor_override,
                              target/actual population, convex_hull_ratio, num_geom_parts,
                              is_contiguous, resolved member list (drawn members as sub:<label> (pop N))
stats/scope_rollup_<leg>.csv  per scope per map: districts, seats, frac_sum, fit_gap (Σ|frac−seats|),
                              noncontig, avg_chr, band_violations, floor_overrides
stats/fingerprints_<leg>.csv  per-scope membership fingerprint: md5 over sorted "seats:members"
                              district signatures; drawn members key on label+population+geom md5,
                              so identical cuts match across maps
tools/export_good_maps.sh     regenerates everything above from the game box (fc_postgres)
```

`rows/` is a restorable snapshot; `stats/` is the measurement record; `fingerprints` answer
"did the membership change" without eyeballing member lists.

## Map-level truth (measured 2026-08-23, all four live maps)

| Map | Districts | Seats | Non-contig | Avg CHR | Fit gap Σ | Band violations | Floor overrides |
|---|---|---|---|---|---|---|---|
| **Earth Manual Map Draft 1 (STANDARD)** | 282 | 2003 | 21 | .6320 | 37.21 | **0** | 15 |
| Earth Auto Map Draft 1 | 283 | 2003 | 27 | .6105 | 33.61 | 0 | 22 |
| **USA Manual Map Draft (STANDARD)** | 100 | 702 | 7 | .7677 | 10.96 | **0** | 3 |
| USA Initial Map (bootstrap) | 105 | 702 | 5 | .7451 | 10.43 | 0 | 9 |

- Band violations count `(seats < 5 AND NOT floor_override) OR seats > 9`. The one raw sub-floor
  district — UK #1 "Celtic remainder" (Northern Ireland + Scotland + Wales, 3 seats) — carries
  `floor_override = true` on both Earth maps: England is promoted out as its own scope and the
  remainder lawfully sits below the floor. Same shape on both maps.
- Every map lands its chamber budget exactly (Earth `type_a_seats` = 2003, USA = 702). Drift is
  always wrong; none exists here.
- Scope agreement (fingerprints): **Earth Manual vs Auto — 55/81 scopes identical, 26 differ**
  (Algeria, Bihār, Brazil, Canada, China, Earth, France, Fujian, Germany, Guangzhou, Hebei,
  Japan, Khyber Pakhtunkhwa, Mahārāshtra, Oromia, Philippines, Punjab, Russia, Spain, Sudan,
  Tamil Nādu, Ukraine, Tanzania, Uttar Pradesh, Viet Nam, Zhejiang).
  **USA Manual vs Initial — 19/30 identical, 11 differ** (California, Florida, Maryland,
  Michigan, New York, Ohio, Pennsylvania, South Carolina, Texas, USA root, Virginia).
- **Ukraine**: the auto engine already promotes Ukraine to its own 2×5 scope (the recursive
  nth-order giant pass, `DistrictingService::computeSeatBudget`). Auto's east/west cut measures
  tighter than the manual one (fit gap .0154 vs .1712, avg CHR .7379 vs .7048) — the fingerprint
  difference is the cut line, not a missing promotion.

## The operator's revealed priority order (the scoring order for Good Maps)

1. **Legality** — seat totals conserved everywhere (chamber budget exact, per-scope budgets
   exact), zero unsanctioned band violations.
2. **Contiguity** — every land-fixable non-contiguity fixed; water/giant-separated groupings
   are deliberately kept (registry below).
3. **Compactness** — CHR raised wherever shape allows (up in the large majority of scopes he
   touched).
4. **Deviation** — a small fit-gap price is accepted when it buys shape (Earth fit 33.61 → 37.21
   against 27 → 21 non-contig and CHR .6105 → .6320).

An auto map "reaches the standard" when: budgets exact everywhere · 0 unsanctioned band
violations · its non-contiguous set is contained in (or equivalent to) the registry below, with
no new land-fixable flags · per-scope avg CHR at-or-above the standard's · fit gap at-or-below
the standard's, scored in the priority order above (a CHR win never excuses a legality/contiguity
loss).

## Acceptable non-contiguity registry — Earth Manual (21, deliberate)

Water- or promoted-giant-separated groupings the operator kept on the standard. An auto map
flagging exactly these clusters is at parity; flagging a NEW land-fixable cluster is a defect.

| District | Seats | Members (abbrev.) |
|---|---|---|
| Earth #1 | 6 | Bahrain, Kuwait, Oman, Qatar, UAE (Gulf) |
| Earth #2 | 9 | Djibouti, Eritrea, Saudi Arabia (Red Sea) |
| Earth #5 | 6 | Armenia, Azerbaijan, Georgia, Turkmenistan (Caspian) |
| Earth #13 | 6 | Benin, Eq. Guinea, São Tomé, Togo (Gulf of Guinea) |
| Earth #30 | 8 | Costa Rica, Ecuador, Nicaragua, Panama (Colombia promoted out) |
| Earth #34 | 8 | Belarus, Estonia, Finland, Latvia, Lithuania, Sweden (Baltic) |
| Earth #36 | 9 | Albania, Bosnia, Croatia, Greece, Kosovo, Malta, Montenegro, N. Macedonia, Tunisia (Adriatic/Med) |
| Earth #48 | 7 | Andorra, Denmark, Faroes, Greenland, Guernsey, Iceland, Ireland, Isle of Man, Norway, Portugal (N. Atlantic) |
| Bangladesh #2 | 7 | Rangpur, Sylhet (split by giant Dhaka side) |
| Canada #1 | 5 | Maritimes + Prairies (Quebec/Ontario giants between) |
| China #3 | 9 | Inner Mongolia, Ningxia, Qinghai |
| East Java #1 | 6 | Madura + mainland pieces |
| Ethiopia #3 | 5 | Afar, Beneshangul Gumu, Dire Dawa, Hareri, Somali, Tigray |
| India #4 | 9 | Chandīgarh, Ladākh, Punjab |
| India #5 | 9 | Himalayan + NE states + Andaman & Nicobar |
| India #8 | 9 | Chhattīsgarh, Dādra & NH & Damān & Diu, Goa, Lakshadweep, Puducherry |
| Indonesia #4 | 7 | Banten, Jakarta, Yogyakarta |
| Indonesia #5 | 9 | Kalimantan + Sulawesi + Gorontalo |
| Japan #4 | 8 | Kyūshū/Shikoku/W-Honshū + Okinawa chain |
| Uganda #2 | 6 | Eastern + Western (Kampala/Central between) |
| Tanzania #1 | 8 | Mainland north + Zanzibar/Pemba |

## Acceptable non-contiguity registry — USA Manual (7, deliberate)

| District | Seats | Members (abbrev.) |
|---|---|---|
| Los Angeles #1 (drawn) | 8 | LA drawn piece incl. Channel Islands |
| Michigan #2 | 8 | Upper Peninsula + N. Lower Peninsula (straits) |
| New York #1 | 7 | Kings, Richmond (harbor) |
| New York #3 | 9 | Bronx, Nassau, Suffolk (Long Island Sound) |
| USA #9 | 9 | Nebraska, New Mexico, South Dakota (Colorado/Wyoming promoted out) |
| USA #16 | 6 | Delaware, Rhode Island, Vermont |
| Virginia #3 | 7 | Tidewater incl. Eastern Shore (Accomack) |

## Historical baselines (maps since deleted from the box)

Measured 2026-08-23 before deletion — Earth, all-map comparison at analysis time:

| Map | Districts | Non-contig | Avg CHR |
|---|---|---|---|
| Earth Initial Map (bootstrap) | 282 | 30 | .604 |
| Earth Copy of Initial (`19574085`) | 281 | 21 | .623 |

Earth Initial → Manual per-scope diff (the record of what he changed; 26/81 scopes at final
state vs Auto, 25 + new Ukraine vs Initial at analysis time), format
`districts | seats | noncontig | avgCHR | fit-gap`:

- Earth scope 50→49 | 361→351 | 8→8 | .480→.550 | 12.99→12.26 (−10 seats = Ukraine promoted out)
- Ukraine NEW 2 | +10 | 0 | .705 | 0.17 (was a 10-seat at-large tile on Initial)
- Guangzhou 5→4 | 30 | 1→0 | .556→.739 | .28→.06 · China 10 | 76 | 3→1 | .569→.619 | 2.68→2.50
- Philippines 4 | 28 | 2→0 | .287→.368 | .83→.61 · Brazil 5 | 41 | 1→0 | .716→.737 | 1.56=
- France 2 | 16 | 1→0 | .307→.399 | .19→.34 · Spain 2 | 12 | 1→0 | .343→.409 | .22→.73
- Russia 4 | 36 | 1→0 | .555→.574 | .16→.10 · Canada 2 | 10 | 1→1 | .482→.540 | .00→.06
- Japan 4 | 31 | 1→1 | .336→.320 | 1.09→.72 · Tanzania 2 | 16 | 1→1 | .575→.693 | .03→.21
- Germany 3 | 21 | 0 | .673→.786 | .59→.77 · Hebei 2 | 18 | 0 | .570→.713 | .26→.00
- Fujian 2 | 10 | 0 | .704→.751 | .07→.00 · Zhejiang 2 | 16 | 0 | .637→.684 | .15→.08
- Sudan 2 | 12 | 0 | .741→.805 | .04→.37 · Oromia 2 | 13 | 0 | .690→.746 | .48→.60
- Algeria 2 | 11 | 0 | .785→.809 | .05→.32 · Uttar Pradesh 7 | 61 | 0 | .668→.722 | .29→.68
- Punjab 4 | 31 | 0 | .700→.736 | .28→.67 · Bihār 4 | 32 | 0 | .703→.721 | .23→.52
- Tamil Nādu 4 | 20 | 0 | .627→.692 | .17→.66 · Mahārāshtra 4 | 32 | 0 | .672→.674 | .10→.51
- Khyber Pakhtunkhwa 2 | 10 | 0 | .695→.711 | .05→.13 · Viet Nam 3 | 25 | 0 | .426→.362 | .06→.33

USA Initial → Manual per-scope diff (11/30 scopes):

- Texas 10→6 | 50 | 0 | .724→.796 | 1.11→.50 · California 10→9 | 66 | 0 | .714→.834 | 1.46→2.07
- New York 6→5 | 40 | 0→2 | .669→.679 | 1.42→1.52 · USA root 16→17 | 116 | 2→2 | .725→.735 | 4.23→3.74
- Florida 6 | 50 | 0 | .691→.665 | .38→.69 · Maryland 2 | 13 | 0 | .538→.521 | .03→.26
- Michigan 3 | 22 | 1 | .560→.707 | .13→.11 · Ohio 3 | 23 | 0 | .810→.849 | .02→.06
- Pennsylvania 3 | 26 | 0 | .828→.824 | .13→.37 · South Carolina 2 | 10 | 0 | .831→.844 | .00→.11
- Virginia 3 | 19 | 1 | .706→.694 | .16=.16

## Restoring a map from `rows/`

Same-database restore (the FK targets — legislature, jurisdictions — must exist; ids are
preserved):

```bash
docker exec -i fc_postgres psql -U fc_user -d fair_constitution \
  -c "\copy legislature_district_maps FROM 'map.csv' WITH CSV HEADER"
# districts next, then subdivisions, then junctions:
#   subdivisions.csv is ordered by label — parent_subdivision_id is a self-FK, so load with
#   constraints deferred (BEGIN; SET CONSTRAINTS ALL DEFERRED; \copy ...; COMMIT;) or two passes.
# geom/centroid columns are hex EWKB — PostGIS ingests them directly via COPY.
```

If the map row still exists (soft-deleted), clear `deleted_at` instead of re-inserting.
Re-export after any change to the standards: `bash tools/export_good_maps.sh`.
