# Phase G Continuation — Design Round (synthesis)

Output of the 7-domain design round (2026-06-17). Each domain was designed by an
agent grounded in the constitution (`docs/extracted/fair_constitution.md`), the
roadmap (`CGA_PHASE_G_AND_BEYOND_ROADMAP.md`), the relevant exploration, and the
real code. Every section ends with its roadmap slot, open decisions, and risks.
Consolidated operator decisions live in [DECISIONS.md](DECISIONS.md).

**Status: DESIGN ONLY.** No implementation. Build proceeds in sequence after the
operator signs off the decisions, starting with GUI adoption.

---

## Roadmap mapping

The operator's vision is not one new phase — it **completes Phase G** and
**pre-designs contributions to the post-G letters**. Each domain slots as:

| # | Domain | Roadmap slot | Build now? | Detailed design |
|---|---|---|---|---|
| 1 | GUI adoption, both sides | **G3c** (Phase G Track A finish) | ✅ dev-stack, FIRST | [G3c](G3c-gui-adoption.md) |
| 2 | Operator ↔ mesh identity | **G-OP** (Phase G Track B, parallel to G-ID) | ✅ dev-stack | [G-OP](G-OP-operator-mesh-identity.md) |
| 3 | Legitimacy-gated peer versioning | **G-VER** (Phase G Track B; reach-tier wired when I lands) | ✅ dev-stack (core) | [G-VER](G-VER-legitimacy-gated-versioning.md) |
| 4 | Transport survival mesh + bootstrap | **G8b** (Phase G Track C, between G9 and G10) | ⚠ multiplex dev-stack; **host-daemon bootstrap is RIG-GATED** | [G8b](G8b-transport-survival-mesh.md) |
| 5 | Civic/org powers + official-record automation | **Phase I** (power-profile + reach firewall) + **Phase K** (debate record) + a standalone **Phase-C hardening** (auto-minutes) | ✅ dev-stack | [I/K](IK-civic-org-powers-and-record.md) |
| 6 | Social layer + Matrix + moderation | **Phase K-3 "The Mesh Commons"** (sub-phase of K; record plane K-1 first; Matrix federation rig-gated) | ⚠ single-instance dev-stack; cross-instance rig-gated | [K-3](K3-social-layer-matrix.md) |
| 7 | Fiscal civic stipend | **Phase M** (UBI differential) + key declarations in **Phase L** | ✅ dev-stack (when L/M land) | [L/M](LM-fiscal-civic-stipend.md) |

> The agents recommend **no new colliding letters** (per the MEMORY warning). The
> social layer's "own letter" was assigned to **K-3** to avoid forking Phase K's
> existing public-square/moderation ownership — flagged as an operator decision.

---

## Decisions locked (2026-06-17)

- **N1 → Phase K-3.** The social layer is a sub-phase of the existing Phase K
  ("The Mesh Commons"), not a new letter.
- **N2 → operator_accounts plane FIRST.** The full operator↔mesh identity layer
  (G-OP) is built *before* G3c and gates the host console — not the `is_operator`
  shortcut. This **resequences the build: G-OP precedes G3c.**
- **N3 → GEODATA_ORIGIN in G3c.** G3c includes the signed geospatial-dataset
  distribution channel, not just posture recording.

## Sequenced build plan

**A. Complete Phase G (dev-stack-buildable now, no rig):**
1. **G-OP — Operator mesh identity.** ← FIRST (per N2). Local operator account ↔
   mesh-wide identity (key-possession auth, never federates passwords), the
   two-plane firewall (operator identity never touches `RoleService`), founder
   gets an operator account at setup. Gates the G3c host console + underpins
   G-VER consent + traveler routing.
2. **G3c — GUI adoption (both sides).** Host console (mint/approve) gated by the
   G-OP operator plane, join-wizard negotiation, read-write request as a
   *governed* front door — **plus the GEODATA_ORIGIN signed-dataset pull** (per
   N3). Service layer already exists; this is controller + routes + Vue + the
   geodata channel.
3. **G-VER — Legitimacy-gated versioning (core).** Peer version tracking +
   operator-board / seated-legislature / peer-mesh agreement + the game-in-progress
   freeze (election-version pinning). Reach-tier added when Phase I lands.
4. **G8b — Transport multiplex + Yggdrasil + nearest-node routing** (dev-stack).
   The **universal cross-platform bootstrap** (installs Tor/Yggdrasil/Tailscale —
   host-modifying) is **rig-gated** for real certification, like G-V1/G-V2.

**B. Pre-designed for the post-G letters (build when each letter comes up):**
- **Phase I/K/C-hardening** — civic/org power-profiles + automated official-record.
- **Phase K-3** — Matrix "Mesh Commons" (record plane K-1 ships first).
- **Phase L/M** — the civic stipend (UBI differential via the F-LEG-031 lever).

Phase H (districting) remains the next *new* lettered phase after G is complete,
per the existing roadmap critical path G→H→I→J→K→L→M→N→O.

---

## Cross-cutting invariants every design preserves

- **Additive-only** — new tables + nullable columns; `audit_log`/`ballots`/
  `jurisdictions` migrations and the protected counting/validator core untouched.
- **Authority ≠ leadership** — version/transport/identity read
  `authoritative_server_id`, never Patroni/cluster-leader state (grep-pinned).
- **The protected triad never federates** — ballots, locations, credentials stay
  private-local; every new surface inherits `FORBIDDEN_SUBJECT_TYPES`.
- **Reach is chrome, never a gate (CI-1/CI-2)** — the legitimacy/reach metric
  decorates power profiles and selects a *consent tier* for upgrades, but never
  weights a vote, seat, franchise act, or moderation decision; `RoleService`
  derives byte-identically with or without it.
- **No new immutable rules** — everything new is `[POLICY]` (flexible layer);
  hardened floors (proportionality, supermajority, no-paywall) are reused, never
  re-implemented, and no consent can bypass them.
- **Operators = de-facto election board, anchored to R-08 + the bootstrap note**
  ("the system acts as the election board for the first election"), bound by
  Art. II §2 neutrality/transparency and Art. II §7 non-disruption — temporary
  standing, superseded by a seated government.
- **Read-write is a governed process, never an adoption checkbox** — Art. V §7
  dual supermajority; the GUI can compose/submit the request, never grant it.

## Verification-gap discipline (carried from the G-V2 saga)

amd64/Docker-Desktop cannot surface native-Linux/arm64 faults, and warm-vendor
cannot surface cold-build faults. The **host-daemon bootstrap (G8b)** and all
**cross-instance federation** (G-OP sync, G-VER mesh consent, K-3 Matrix S2S) are
therefore **rig-gated for real certification** — designed + dev-stack-verified now,
field-certified on the physical rig like G-V1/G-V2.
