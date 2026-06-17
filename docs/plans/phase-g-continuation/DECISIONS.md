# Phase G Continuation — Consolidated Operator Decisions

Every open decision the 7 design sections surfaced, grouped by domain, each with a
recommendation. Most are **per-phase** — decide them when that phase is built. A
small **DECIDE NOW** subset gates the build sequence / roadmap structure.

---

## ★ DECIDE NOW (gates the first build + roadmap shape)

| # | Decision | Options | Recommendation |
|---|---|---|---|
| **N1** | **Social layer: its own letter, or Phase K-3?** | (a) new top-level letter; (b) **K-3 "The Mesh Commons"** sub-phase of the existing K | **K-3** — avoids forking K's existing public-square/moderation/`social_*` ownership; the agents warn against colliding letters. (Operator originally said "own letter" — confirm.) |
| **N2** | **Operator authorization for the GUI host console (G3c)** | (a) reuse existing `users.is_operator` now, build the full `operator_accounts` mesh plane in **G-OP**; (b) build `operator_accounts` up front | **(a)** — `is_operator` already exists (the founder has it); ship the host console gated on it now, layer the mesh-identity plane in G-OP. |
| **N3** | **Geodata channel depth in G3c** | (a) record the geodata *posture* only, defer the signed-dataset pull to Phase H (first runtime raster consumer); (b) build `GEODATA_ORIGIN` transport now | **(a)** — keeps G3c scoped to adoption; no geodata federation endpoint exists today. |

---

## G3c — GUI adoption
- **co_member flag** stays advisory at adoption; the real read-write ask is the separate `ReadWriteRequest`. *(Recommend: confirm.)*
- **Who opens the autonomy vote** for a read-write request → the instance authoritative for the subtree opens its own chamber vote (Art. V §7 dual supermajority). *(Recommend.)*
- **Nav placement** for `/federation` (currently URL-only) — top-level vs nested under "System/Instance".

## G-OP — Operator mesh identity
- **Upgrade-consent threshold/quorum** ([POLICY]) — simple majority of active mesh operators (default), and: does a single-operator mesh auto-consent or still click?
- **Who may mint a mesh operator identity** — self-serve vs peer-vouch (mirror the mirror-adoption vouch model?).
- **Authoritative home** for a mesh operator — fixed `genesis_server_id` vs a movable operator-home pointer.
- **Founder bootstrapping** — create the founder's `operator_account` at setup, mesh-link opt-in later. *(Recommend.)*
- **Routing locality signal** — RTT probe vs static region hint vs GeoIP.

## G-VER — Legitimacy-gated versioning (the constitutional core)
- **`constitutional_version`: derived (hash of the hardened surface) vs declared** — *recommend derived* (cannot drift); needs a canonical "what is hardened surface" manifest.
- **Operator-board leg: single operator vs N-of-M co-sign quorum** — policy choice; MJV substrate supports either.
- **`upgrade_reach_gate_pct` default** — recommend keying primarily on *seatedness* (a seated legislature always gates), reach as a secondary signal.
- **Subtree-granular vs whole-instance upgrades** — *recommend subtree-granular* (faithful to federation).
- **Does a `schema_bump` ever need the freeze** — *recommend no* (wire-shape, not vote-math); if it ever co-changes a counted payload it's a `constitutional_bump` by definition.

## G8b — Transport survival mesh
- **Yggdrasil public-peers** — ship a curated default list vs operator-supplied.
- **Geo-routing fine location** — allow opt-in rounded coordinates vs jurisdiction-pick / country-GeoIP only (strictest privacy).
- **GeoIP source** — bundled offline DB (privacy-correct) vs hosted lookup.
- **Bootstrap installer trust** — distro package managers vs pinned vendor installers + checksums.
- **macOS parity** — fold Darwin into `deploy.{sh,ps1}` vs only in the new `bootstrap.*`.
- **Advertise onion/yggdrasil publicly** vs to handshaked peers only.
- **`censorship_floor_first`** — global instance posture vs per-request.

## Phase I/K — Civic/org powers + official record
- **`CivicPowerProfile`** strictly derived (no cache) vs a non-authoritative nightly materialized view.
- **`PowerLapseSweepJob`** cadence; gate on `authoritative_server_id IS NULL` (recommend yes).
- **How much of "statements made" auto-publishes** vs stays manual (the compelled-speech line).
- **Auto-minutes authorship** — system actor vs generate-then-ratify by Speaker/Admin.
- **Org co-determination countdown** as chrome.
- **`PowerCatalog` source** — handler-derived (gate-truth) + chart text for `basis`, with a pin they agree.

## Phase K-3 — Social layer / Matrix
- **Synapse vs Dendrite** — recommend Dendrite default (lighter on the Pi) + `MATRIX_IMPL=synapse` override.
- **One Ed25519 key for CGA-mesh + Matrix S2S, or two** — needs a key-rotation security review.
- **Embedded client vs link-out to Element** — recommend embed `#square`/`#halls`, link-out for power users.
- **Matrix federation: mesh-only (default) vs open-to-fediverse** — recommend mesh-only, opt-in wider.
- **Keep the bespoke `social_*` civic-record plane** (recommend) vs thin it to a Matrix index.
- **Voice/video (LiveKit)** in K-3 vs deferred K-3b (likely can't run on the Pi).
- **Demo (`scale_demo`)** — federation forced off; does it even have a square?
- **Legitimacy gauge** is display-only, never a moderation input (confirm CI-1).

## Phase L/M — Fiscal civic stipend
- **Funding source** — minted (UBI-style injection, default) vs drawn from a fee-funded treasury account.
- **Which offices qualify** (`stipend_officeholder_roles`) — all R-08…R-30, or only high-burden; pay *neutral* officers (R-08 board, R-29 admin) at all?
- **Operator-grant authority** — seated government by chamber act, Treasury BoG ack, or both; do pre-government (unactivated) jurisdictions' operators qualify?
- **Dual-door vs ordinary majority** — recommend dual-door (constituents whose money is spent must consent).
- **k-anonymity floor** for suppressing small-class stipend aggregates.
- **Flat per-role vs scaled** (by uptime/hours) — recommend flat (scaling re-introduces surveillance).
- **Cap: absolute number vs ratio of `ubi_amount_per_period`** — recommend ratio (capture-resistant).
