# v3 Mockup Wiring — Gap Matrix + Phased Plan

**Lane charter (2026-07-01).** Integrate the `mockups/v3` environment (113 pages, shell-v2,
plainCodes, Learn drawer, 121-stop tour) into the wired app. All work lands on
**`feature/v3-wiring`**, merged to `main` **only at numbered operator checkpoints** — the live
multibox campaign pulls `main`, so `main` stays campaign-stable (hotfixes only) between checkpoints.
`mockups/` itself is **Designer-owned: read-only** to this lane (integrity fixes get *filed*, not
committed here).

Source analysis: 5-agent gap-analysis workflow `wf_3a9d7b5d-414` (inventories of the v3 surface, the
wired app, and the backend, crossed into the matrix below).

---

## The gap matrix (14 modules)

| Module | v3 screens | Status | Effort |
|---|---|---|---|
| Elections | 8 | **wired_current** — 1:1 onto 8 modern pages, backend fully served | small |
| Executive | 5 | **wired_current** — 1:1, served | small |
| Judiciary / Courts | 6 | **wired_current** — 1:1, served | small |
| Organizations | 7 | mixed — 6 restyles; org-profile net-new (economy blocks → Planned) | small |
| System / Records | 7 | mixed — 3 restyles; **clocks + amendments = backend-ready, UI missing** | small |
| Legislature | 12 | mixed — 11 restyles; bill-redline needs a clause model; Show 5.2k monolith retires | medium |
| Global shell / launchpad | 8 | mixed — launchpad + my-civic-life replace Home/MyRecord; today-feed needs events layer | medium |
| Atlas | 1 | mixed — nodes/counts queryable today; people-layer + growth series need backend | medium |
| Journeys (game layer) | 13 | mixed — 10 are pure orchestration UI over wired routes; 3 blocked on economy | medium |
| Commons / Live Civic Room | 11 | mixed — **the keystone**; Plane A/B plumbing served, needs the events layer | large |
| Operator / Mesh console | 9 | **wired_out_of_step** — Federation.vue (795 ln) replaced by operator/* suite | large |
| Jurisdictions & Federation (citizen) | 7 | mixed — browser + district-mapper extraction; 4 process pages backend-ready | large |
| Learn / Tour / Translation / Support | 12 | mixed — 6 onboarding restyles; support/learn/translation need small backends | large |
| Economy | 14 | **needs_new_backend** — operator-settled PLANNED; zero code; forms reserved-not-registered | large |

**Replace-class pages (only 3):** `Legislature/Show` (5,173 ln — mapper extracted to
jurisdictions/district-mapper, rest → legislature-home), `Jurisdictions/Show` (1,239 ln → one browser
page, keeping the GeoJSON/raster/pmtiles APIs), `Jurisdictions/Federation.vue` (795 ln → operator/*
suite + a citizen federation page via a guard-split of its ~20 POSTs). **Everything else modern is
RESTYLE-in-place.** **Keep untouched:** Setup/*, JoinHost, OperatorLogin, Operator/Operations.vue
(infra plane), Auth, Invite, Dev kits.

**Cross-cutting spine (the real Phase-1 work):** v2.css token set → AppShell/Header/Sidebar restyle
(floating command bar, hide-on-scroll header + jurisdiction chips; Inertia persistence untouched) →
16-section nav.js rewrite with `plannedFlag()` (dead links become structurally impossible) →
plainCodes inversion over `config/cga/surfaces.php` (F-/CLK-/Art. codes demoted to tooltips + Learn
drawer; citations kept) → Learn drawer + "Where this fits" + report footer (fed by
`fixtures-flows.js`, 80 WF-* inverted byScreen) → 121-stop tour as a route-keyed composable →
manifest.json ↔ surfaces.php **registry unification** with a drift-failing test.

---

## The 7 phases (each ends in an operator checkpoint merge)

1. **Shell-v2 spine + registry unification** *(the recipe phase; low-med risk)* — tokens, shell
   restyle, nav rewrite, plainCodes pass, Learn drawer + tour composable, registry unification, the
   one additive backend touch (a minimal `/support/report?ref=` intake), `#cga-live` announce rail.
   Proven end-to-end on **2 pilot pages** (Elections/Index + a System/Records page) before any wave.
2. **Restyle wave over served backends** *(low risk — the safest early merge)* — ~36 pages: Elections
   8, Executive 5, Judiciary 6, Orgs 6, System 3, petitions 2, onboarding 6; plus two thin new pages
   over existing services: **/system/clocks** (ClockService, 21 clocks) and **/system/amendments**
   (SettingChange + amendment door) that retire today's dead-link placeholders.
3. **Launchpad + profile + monolith retirements + journey rails** *(medium)* — index launchpad,
   my-civic-life tabbed profile (wallet/achievements tabs = plannedFlag), Legislature/Show split
   (**district-mapper becomes its own page** — directly serves the districting lane),
   Jurisdictions/Show → browser, 10 journeys as static rails deep-linking wired routes, chamber-v2
   seating (static).
4. **Operator/Mesh console harmonization** *(the operator-flagged one; merge only at a declared
   campaign pause)* — operator/* suite replaces Federation.vue; guard-split of its routes; citizen
   federation page; HTTP endpoint-ification of the CLI-first broker/cert/role flows (wrapping, never
   modifying, the mesh services); dedicated union/restoration/disintermediation/bootstrap pages with
   consent meters. Legacy Federation.vue stays routable behind a flag until parity is confirmed on
   real mesh operations.
5. **Real-time events layer + Live Civic Room keystone** *(high risk, biggest value)* — the one big
   shared backend (live tallies/presence/floor; vote numbers strictly engine snapshots); social-home
   + live-room ×8 replace the thin social pages; groups suite (additive meeting-type schema);
   constituent-request queue; civic/today goes live. Consumes (never rebuilds) the settled
   player↔Matrix identity-bridge plan.
6. **New-backend long tail** *(medium)* — Atlas (public aggregate endpoint now; opt-in people layer +
   growth snapshots as a privacy-gated second slice), learn (fixture-static first), support tickets,
   translation workflow, bill clause/redline model (additive; BillVersion stays whole-text).
7. **Economy + achievements/legitimacy** *(hard-gated on operator design sign-off)* — register the 18
   reserved forms FIRST, then models honoring the never-federated wallet rail + Art. V §5 currency
   root-reservation; flip every plannedFlag live. Never merged near an active campaign gate.

---

## Recorded baseline (2026-07-01, full suite on the worktree box)

**677 passed / 15 failed (293,705 assertions).** Every failure is **pre-existing live-data
coupling** in ONE domain — the mesh-role/consent tests colliding with this box's REAL mesh state
(voice.sfu + etl capabilities established 2026-06-28; the operator's live campaign has since added
real adoption approvals, role-grant proposals, and pinned peers on this box):
MeshNamedRoleTest ×2, MeshRoleBoardTest ×1, MeshRoleGrantTest ×3, PeerUpgradeAgreementTest ×4
(documented), RoleGrantDeliveryTest ×1, ServiceReachTest ×1 (unique-collision with the real
capability row — verified), VoiceReachTest ×2 (same, verified against the partial unique index),
FederationConsolePropsTest ×1 (documented roles.pending count).

Zero failures in elections/legislature/judiciary/orgs/social/setup/foundation — the domains this
lane touches. **The wiring green bar = 677 passing + no NEW failures; the 15 are tracked as
test-isolation debt** (the tests assume a pristine box; a proper fix isolates them from live mesh
state — filed for the constitutional-cleanup phase, not churned mid-campaign).

## Campaign-safety rules (enforced at every checkpoint merge)

1. Full suite green at the recorded baseline (+ the phase's new tests).
2. Migrations **additive-only**; anything touching mesh-replicated tables reviewed against the
   replication/append-only-ledger list before merge.
3. Federation/mesh code untouched until Phase 4 — and Phase 4 only *wraps* services in endpoints.
4. KEEP-class campaign surfaces are never restyled or moved this campaign.
5. `mockups/` is read-only; Designer-lane fixes are filed (drop stale `civic/my-record.html` +
   `operations.html` records, register `shared/bill.html`, fix stop-count prose).
6. The events layer is node-local state — it must never become federated writes.
7. Phases 4/5 merge only at operator-declared campaign pauses.

## SETTLED decisions (operator, 2026-07-01) — supersede the open list

0. **Mockups are no longer read-only.** The Designer lane is disengaged (re-engaged only if the
   operator opens a new design phase). This lane now owns BOTH `mockups/` and the app. Mandate: a
   **simplification pass over v3** — make it novice-friendly, remove the redundancy/patchwork from
   its authoring — EARLY (before the module waves wire redundancy in). Manifest integrity fixes
   now commit directly here.
1. **Events transport: polling-first behind the `EventFeed` interface — ratified, with boundaries.**
   The events layer serves browser↔own-node governance UI state ONLY. It never touches (a)
   node↔node record propagation — that stays the CLK-20 FF&C tail, race-safe because tallies are
   engine snapshots with sequence numbers — nor (b) Matrix/LiveKit, whose native sub-second
   transports are never proxied or risked. Reverb remains a drop-in transport upgrade if wanted.
2. **Journeys: DURABLE per-user completion (overrides "stateless").** Journeys ARE the lesson layer
   (the single-player simulations under the Learn flyout/tour). Completion earns an **achievement on
   the player's PUBLIC PROFILE** (public-record-like → syncs; confers NO game power — no vote/
   office/authority effect) and a **one-time stipend bonus** (the payout hook lands with the economy
   phase; completion tracking lands with journeys). Journeys **soft-gate** live actions: a player's
   first live use of a surface nudges the tutorial first (dismissible, never blocking).
3. **Rooms: ground on Matrix-native structure; the app models the BINDING, Matrix owns replication.**
   Standing rooms for institutions within jurisdictions (a courthouse has many courtrooms; a
   legislature many committee rooms) + orgs + individuals; **nesting/categories via Matrix Spaces**
   (Discord-like), adaptable and scalable to match real institutions. Reachability is cross-node by
   construction: a client on any node reaches any public room (Matrix S2S; live calls pick a host
   node others backend-connect to — the existing voice-reach model). Do NOT reinvent replication.
   Management surfaces (create/organize/categorize standing rooms per institution) are in scope.
4. **DMs: thin UI over the existing private-room/Matrix primitive** (2-person room), Phase 5 with
   the groups suite. Ratified as recommended.
5. **Learn content: BUILT-IN (static, ships with the app)** — journeys are the interactive layer on
   top. The v3 simplification pass (item 0) shapes this material first.
6. **Merges: on green suite + operator walkthrough** (campaign suspended). Endgame after the wiring
   campaign: blow all containers away → Box A from main (no worktree) → **full-scale demo** → then a
   Box B demo. Phase-4 keeps legacy Federation.vue flag-routable until that demo proves parity.
7. **Snapshots: one shared mechanism, ratified** (operator indifferent on backend method).
   Collectors must serve the per-jurisdiction activation gates (player population vs potential
   population) as well as Atlas growth.
8. **Economy: design round still required, now seeded with operator input:** Earth legislature (the
   topmost) sets regulations; UBI stipend units are operator-default constitutional settings like
   everything else; **UBI = base amount per interval + a bonus on the same interval for operators
   and civic/elected/appointed role-holders** (encourages volunteering); **orgs may issue their own
   units** to workers/members/owners under their own agreements; users transfer/trade directly or
   via a common marketplace (fungible + non-fungible); tax/inflation controls are UIs operated by
   the people empowered to use them; **no basket of goods** — but as a centralized game currency,
   currency-distribution telemetry should let tax structures auto-manage inflation within bounds
   (⚑ that mechanism deserves its own design round).
   **⚑ SUPERSEDED by the operator: the "never-federated wallet rail."** One consistent game world:
   **everything syncs between nodes** — wallet state included — minus only per-node-unique identity
   and services a node can't host.

## ⚑ New architecture directives (operator, 2026-07-01)

- **Collapse the read vs read-write operator distinction.** One operator process to peerage: any
  node that can get a cert and take clients becomes a full, EQUAL peer. The only differentiators:
  services a node can't host (e.g. the social layer, by hardware) and trust-elevated roles
  (broker/DNS). Implementation mapping — how this reshapes the mirror/write-guard/authority model
  (G3c read-write petitions become vestigial; write-forwarding is the norm) — is a Phase-4 design
  note to work through explicitly, not silently.
- **Pretty URLs everywhere (cross-cutting, adopt in Phase 1-2):** slugs over UUIDs wherever a
  unique slug will do; shareable public links for rooms/streams/pages; where a generated id is
  needed, use short YouTube-style encodings (base62/sqids), never raw UUIDs in URLs; avoid
  GET-parameter noise on shareable surfaces.
