# ATLAS_DESIGN — the public world-metrics surface

**Lane 4 (Simulated World Engine) · Wave 3 · 2026-07-29 · design deliverable**
**Status: DESIGN APPROVED (operator, 2026-07-29, via the desk). All §8 calls RULED — option (a) throughout.
The Atlas BUILD is queued as a Wave 4 order.**
Target mockup: `mockups/v3/atlas.html`. Data spine: lane 3's reach/legitimacy snapshots.

---

## 1. What the Atlas is

> *"A live heartbeat of the whole game."* — the mockup's own citation.

One public, read-only screen for the entire world: a living map (opt-in, approximate) plus the vital
signs of representation, justice, the executive, organizations, the economy, people, and the mesh —
and, at the centre, **reach & legitimacy**, lane 3's enrolment gauge, aggregated to the planet.

**It is a mirror, not a control panel.** There is **no action on this page**, by design — the same
rule `ReachController` already lives by. A surface that could *do* something with reach or a
headcount would be the first step toward the thing the charter forbids.

---

## 2. The one hard rail — CI-1, inherited whole

**A GAUGE, NEVER A LEVER.** Everything the Atlas shows is display-only. Nothing on it changes a vote,
a seat, or a right; nothing on it is ever consulted on a rights path. This is not a new rail — it is
`LegitimacyService`'s CI-1, and the Atlas is bound by it end to end:

| Rule | Consequence for the Atlas |
|---|---|
| **No per-person score, ever** | Only *places* are measured. No leaderboard of people. The map's people layer is anonymous opt-in pixels — no name, grid-snapped. |
| **k-anonymity floor = 5, complementary suppression** | The Atlas reads only **already-suppressed** snapshots. It never re-derives a count that a snapshot withheld. A suppressed night is a **gap**, never a zero. |
| **Snapshot, never live** | The Atlas **never runs a live `COUNT(*)`.** A live count would hand an observer sub-minute resolution and let them defeat k-anonymity by differencing — exactly `ReachController`'s stated warning. It reads rollup tables or it shows nothing. |
| **CI-6 — authority, not leadership** | Aggregates sum only what *this instance is authoritative for*, plus federated snapshots from peers. A mirror does not invent numbers for places it does not own. |
| **Approximate positions only** | The map is "orientation, not surveillance": city-level, grid-snapped, opt-in. No precise location is ever plotted. |

---

## 3. The architectural spine — a materialized world rollup

**The problem the fixtures hide:** the mockup reads every metric off in-memory `fixtures`. A live
world has **955,130 jurisdictions**. Computing the world card live — counts by tier, total
population, civic-active, seats, elections, cases, orgs — is the **~75-second `SimConsoleController::world()`
aggregate**. Running that per page load is impossible, and a live headcount would *also* break CI-1.

**Decision: the Atlas reads a nightly `world_stats` rollup, exactly as the Reach surface reads
`legitimacy_snapshots`.** One job writes one dated row of world totals; the Atlas reads the latest
row. This solves performance and privacy in one stroke — the same reason legitimacy is snapshotted.

| | Reach (built, the precedent) | Atlas world stats (proposed, same shape) |
|---|---|---|
| Writer | `SnapshotLegitimacyJob` nightly | `SnapshotWorldStatsJob` nightly (+ on demand for the sim) |
| Table | `legitimacy_snapshots` (per jurisdiction, per date) | `world_stats` (one row per date, JSON of domain totals) |
| Reader | `ReachController` — latest row, never live | `AtlasController` — latest row, never live |
| Privacy | suppression baked into the row | **no sub-k number ever enters the row** (see §4) |
| History | 90-night series → the spark | daily rows → the year growth trends |

**Aggregation cost is proportional to jurisdictions, run once a night, not per request** — and it
reuses the set-based, chunked, ETL-rule pattern already proven in `InstitutionProvisionService`
(keyset pages, committed chunks, one audit entry). The nightly job may piggyback on the legitimacy
run so the two snapshots share a pass.

---

## 4. Domain-by-domain data spine

Each of the mockup's nine vital-signs cards, mapped to its real source. **"Reads"** = what the
nightly job aggregates into `world_stats`; **"Never reads"** = the CI-1 boundary.

| Card | Reads (into the nightly rollup) | Existing source | Never reads |
|---|---|---|---|
| **The world** | jurisdiction counts by `adm_level`, Σ modelled population, Earth population, civic-active count | `jurisdictions` | — |
| **Reach & legitimacy** ⚑ | Σ *published* verified residents, # measured places, home-place reach dial + 30-night spark | **`legitimacy_snapshots` (already suppression-safe)** | any sub-k count, any live headcount, any per-person row |
| **Representation** | legislatures, seats, filled/open, elections open, seats up, candidates, petitions gathering, committees, bills | `legislatures`, `legislature_members`, `elections`, `candidacies`, `petitions`, `committees`, `bills` | who voted; ballot contents |
| **The executive** | departments, governor seats, worker-elected seats, civil-service workers, emergency powers active + days left | `executives`, `departments`, `boards` + `board_seats` | — |
| **The judiciary** | courts, cases open, constitutional challenges, juries seated, remedy windows | `judiciaries`, `cases`, `juries` | juror identities; sealed case content |
| **Organizations** | count by type, endorsements made, workers represented, public-domain works | `organizations`, `endorsements`, CGC IP register | private membership rolls |
| **The economy** *(planned)* | minted this cycle, stipend recipients (aggregate), stipend floor, market volume, public budget, agreements, ledgers, listings | lane 13 economy services | **any wallet balance — a wallet is private, like a ballot** |
| **People & achievements** *(planned)* | verified residents, named role-holders, orgs, registered advocates, achievement *tracks* + earned/total counts | `residency_confirmations` (aggregate), `social_profiles`, achievements | **individual achievements — private by default, no governance advantage, hard-separated from votes/seats/money** |
| **The mesh** | nodes, alive now, connected peers, on-latest version, transports up, caught-up count | `federation_peers`, `federation_transports`, operator readiness | operator private keys; node internals |

### 4.1 ⚑ Corrections against the LIVE schema (2026-07-29, during the build)

Three table names in the original §4 list were **phantoms** — carried from the mockup's
`fixtures` as if they were schema. Verified against `information_schema` on the dev box and
corrected above. Anyone writing the rollup SQL must use the right-hand names:

| Design said | Reality |
|---|---|
| `candidates` | **`candidacies`** (`App\Models\Candidacy`). Note `endorsements.candidate_id` points at it despite the column name. |
| `boards_of_governors` | **`boards`**, polymorphic via `boardable_type`/`boardable_id`; actual seats in **`board_seats`**. No table by the BoG name exists. |
| `personas` | **DOES NOT EXIST AND NEVER HAS.** "Persona" is a dev-tool impersonation concept (`app/Services/Dev/AssumeService.php`) with no backing table. Closest real per-user table is `social_profiles`. |

Two more shape corrections found the same way:

- **`jurisdictions` has no `centroid_lat`/`centroid_lng`.** It stores a PostGIS **`centroid`**
  point, SRID 4326 — `ST_X` is longitude, `ST_Y` is latitude. The map reads those.
- **`federation_peers` has no geometry at all**, and no `label`/`base_url`/`role`/`is_self`. The
  real columns are `name`, `url`, `status`, `relation` (sovereign|host|mirror),
  `last_synced_seq`, `server_id`; "this node" = the peer whose `server_id` matches the
  `instance_settings` singleton. **Consequence: the node and organization map layers render
  honestly EMPTY** until a coordinate source exists — a guessed pin on a map whose whole promise
  is orientation is the one thing this surface must not do. The mockup's peer-status vocabulary
  (`authoritative`/`healthy`/`degraded`) was fiction; the real one is `discovered`, `handshake`,
  `trust_established`, `syncing`, `conflict_resolution`, `border_settled`, `merged`, `departed`.

**Query-correctness traps for the rollup** (the convention exceptions CLAUDE.md warns about,
confirmed live): `legislature_members` holds `elected` **and** `term_ended` rows, so a naive
`count(*)` over-reports current seats ~2.5× — filter `status = 'elected'`. `endorsements`,
`residency_confirmations` and `legitimacy_snapshots` carry **no `deleted_at` and no `status`**
(liveness is `is_active`; the snapshot's status-like column is named **`state`**).
`federation_transports` has `deleted_at` but no `status` (liveness is `enabled`).
`jurisdictions` has **no `status`** — it has `lifecycle_status` (NULL on this box) plus
`is_active`/`is_civic_active` booleans, and civic-active reads the boolean.

⚑ **The Reach card is the spine.** It is not a new metric — it is `LegitimacyService` summed to the
planet. `verifiedTotal` is a sum of **already-published** snapshot rows only; a place whose snapshot
is in the `activating` (suppressed) state contributes to "places gauged" but **not** a number. The
home-place dial and spark are the viewer's own place read straight from `ReachController`'s logic.

---

## 5. The living map

| Layer | Source | Privacy model |
|---|---|---|
| **Places** (dots, tier-coloured) | `jurisdictions` centroids | city-level, public boundaries already |
| **Organizations** (diamonds) | `organizations` with a public seat | public entities; approximate |
| **People** (pixels) | **opt-in only** | **grid-snapped, no name attached, one anonymous pixel.** Opt-out is the default. |
| **Nodes** (pulses) | `federation_peers` | operator chose to run a public node; label + uptime only |

The "Put yourself on the map" control is the **only** interactive element, and it is a personal
opt-in that plots a single approximate, nameless pixel. **Land is a drawn outline for orientation;
positions are approximate. This is the map's whole promise: orientation, not surveillance.**

---

## 6. The route & controller

Model it on `ReachController` — read-only, snapshot-fed, no action:

```
GET /atlas   →  AtlasController@index  →  Inertia render Pages/System/Atlas
```
- **Public-to-authenticated**, never operator-walled (watching the world is a citizen right — the
  same stance the `api.simworld.progress` poll takes). Driving anything is not offered here at all.
- Reads `world_stats` latest row (vital signs + hero), a 365-row window (growth trends), the viewer's
  home-place `legitimacy_snapshots` (reach dial + spark), and the public node directory.
- **Emits nothing that hasn't already passed a suppression decision.** No branch runs a live count.
- Demo-vs-real posture is surfaced honestly: a `scale_demo` instance labels the Atlas as the
  synthetic world (so the operator's demo never masquerades as a live civilization) via
  `InstanceClass::current()`.

---

## 7. Performance budget

| Path | Cost | How |
|---|---|---|
| Page load (`AtlasController@index`) | **O(1) reads** — a handful of latest-row queries | never touches the 955k tree live |
| Nightly rollup (`SnapshotWorldStatsJob`) | O(jurisdictions), chunked, once/night | ETL rule: keyset pages, committed chunks, one audit entry; may share the legitimacy pass |
| Sim refresh (demo) | on-demand rollup after a populate run | the sim already knows when a run finishes (`SimRun` phases) — trigger one rollup at `done`, not per tick |

**The 75-second `world()` aggregate is the anti-pattern this design exists to avoid.** It is fine as
a one-off console diagnostic; it must never sit behind a public page.

---

## 8. Judgment calls — RULED 2026-07-29 (operator, all option (a))

**Operator ruled all four via the desk; the Atlas BUILD is queued as a Wave 4 order. These are the
contract the build implements.**

- **Q1 — rollup cadence.** ✅ **RULED (a): nightly, shared with the legitimacy pass** — one pass,
  cheapest; the world does not change enough in an hour to justify more.
- **Q2 — federated rollup.** ✅ **RULED (a): each instance publishes its own `world_stats`, the Atlas
  sums them** — true to CI-6, matches how legitimacy already federates.
- **Q3 — home-place reach.** ✅ **RULED (a): the viewer's residency-confirmed place** (falling back to
  Earth), with a small picker — still a *place* gauge, never a per-person score.
- **Q4 — economy & people cards.** ✅ **RULED (a): render them `planned`** until lane 13 / achievements
  land — honest, and keeps the layout stable.

---

## 9. Build notes (for the wave that builds it)

- **New:** `world_stats` migration (real-dated, additive — one row/date, JSONB domain totals),
  `SnapshotWorldStatsJob` (ETL-rule chunked, audited), `AtlasController`, `Pages/System/Atlas.vue`
  (port the mockup's render — map SVG, nine domain cards, trends, CTAs, node directory).
- **Reuse, don't reinvent:** the reach dial + spark are `ReachController`/`social/legitimacy`
  components; the map projection and layer toggles are self-contained in the mockup already.
- **UI↔CLI parity:** a `world:stats` CLI twin that prints the latest rollup and can force a refresh.
  Add the parity row to `UI_CLI_PARITY_INVENTORY.md` when built.
  ⚑ **CORRECTION (2026-07-29, during the build): the forced refresh must NOT carry
  `GuardsSyntheticData`,** as this section originally specified. That guard's scope is *minting*
  synthetic people/governments/civic records — its own docblock says "synthetic data may be written
  ONLY where the world has declared itself not-real", and it refuses on a production instance. A
  rollup refresh recomputes a **real public aggregate** — exactly what the nightly job does — so
  gating it would deny ops the ability to refresh the gauge on a live node, which is precisely
  backwards for a read surface. The read is public; the refresh is idempotent and ungated. (This is
  an implementation-level correction to the *instrument*, not a change to any rule: the rail the
  design cares about — nothing synthetic is ever minted here — is untouched, because the rollup
  mints nothing.)
  ⚑ Note the precedent has a parity GAP to avoid copying: `SnapshotLegitimacyJob` has **no CLI twin
  at all** (verified — the only way to run the pass is the nightly schedule or a manual dispatch).
  The Atlas ships its command rather than inheriting that omission.
- **Pins:** the Atlas never runs a live count (source-scan pin, like the SimConsole control-marker
  pin); a suppressed snapshot never contributes a number to the rollup; the page carries no action.
- **Never:** an action control, a per-person figure, a wallet balance, an individual achievement, or
  anything that reads on a rights path. If a future card would need one of those, the card does not
  ship — the rail wins.

---

*Relates: `app/Services/LegitimacyService.php` + `app/Http/Controllers/ReachController.php` (the data
spine + the precedent) · `database/migrations/2026_07_25_000011_legitimacy_snapshots.php` (the model
for `world_stats`) · `app/Http/Controllers/Demo/SimConsoleController.php` (`world()` — the anti-pattern
to avoid live) · `SERVICE_SCALE_FORMULA.md` (what fills the world the Atlas then displays) ·
`mockups/v3/atlas.html` (the target).*
