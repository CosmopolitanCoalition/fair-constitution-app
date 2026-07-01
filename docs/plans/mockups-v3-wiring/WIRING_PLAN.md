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

## Decisions the operator owns (each blocks only its own phase)

- **§C events-layer transport** (websockets/Reverb vs structured polling) — blocks Phase 5; Phase 3's
  today-feed ships poll-based interim either way.
- **Journey progress**: stateless rails (shipping in Phase 3) vs a per-user progress table later.
- **Groups meeting-type schema** shape + its mesh-replication classification — blocks the Phase-5 groups suite.
- **messages-v2 DMs**: no backend exists; in-scope via Matrix rooms, later phase, or out — flagged, not silently built.
- **Learn content storage**: static fixtures vs DB — decides Phase 6's learn slice.
- **Checkpoint timing** for Phases 4/5 relative to campaign pauses.
- **Atlas growth snapshots vs legitimacy_snapshots**: one shared mechanism or two jobs.
- **Economy/achievements design sign-off** — the explicit Phase-7 gate.
