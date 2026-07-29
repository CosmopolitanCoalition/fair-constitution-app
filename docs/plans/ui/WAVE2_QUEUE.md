# Wave 2 queue — inputs accumulated during Wave 1

*Desk file. Items land here as Wave 1 surfaces them; each gets folded into the owning lane's
Wave 2 marching order. Nothing here is an order yet.*

## For lane 2 (G) — federation, from lane 4's D7 build (2026-07-29)

1. **Handshake identity payload should carry `game_mode`** — the refined peer rail (ruling 4)
   reads `metadata->>'game_mode'`, and a peer counts as demo ONLY when its signed handshake
   declared it. One payload field lights up sandbox dev meshes (Pi-style multibox playtests).
2. **Demo-mesh time advance needs ONE coordinating node** — per-node advances skew shared
   deadlines. Recorded in the gate docblock by lane 4, deliberately not built. Design + build
   ride lane 2's multibox work.
3. **The mirror/adoption exchange carries no `instance_class` at all** — adoption-minted demo
   meshes stay time-frozen until it does.
4. (Already ordered, ruling 2): `DisintermediationService` fix — constituents inherit.

## For lane 3 (I) — judiciary surfaces

- ~~CaseDetail.vue: `:interactive="isDemoMode"` flip~~ — **DONE at the desk 2026-07-29**
  (one attribute + docblock, on lane 4's flag; announce-fix-record, no waiting).
- **Challenge-tracker "simulate" buttons: desk disposition — client-side simulation DROPPED.**
  If the teaching value is wanted, it returns as a D5 scenario preset that files REAL forms
  through the engine — never a client-side fake (same doctrine as the consent-slider drop).
  Revisit only if lane 3 or the operator asks.

## D6 dispositions (desk rulings on lane 4's scope truth, 2026-07-29)

| Affordance | Recon claimed | Truth | Disposition |
|---|---|---|---|
| CaseLifecycle Back/Advance | built-but-disabled | BUILT | flipped on (world-keyed), desk-applied |
| Judiciary-home consent sliders | built-but-disabled | never built; docblocks say "DO NOT ship" | **DROPPED** — a slider faking consent contradicts the engine-snapshot rail (lane 4's recommendation, adopted) |
| Challenge-tracker simulate | built-but-disabled | never built | client-side fake DROPPED; possible D5 real-filing preset |

## Small fixes applied at the desk during Wave 1

- `Districts.vue` dev-seat gate: `import.meta.env.DEV ||` leak removed — world-keyed only
  (lane 4's flag; the nav.js registry warning pattern).

## D5 ruling (desk, 2026-07-29)

**Full async once** — queued-run + poll pair for the scenario presets (lane 4's
recommendation, adopted): `elections:demo`-class seeders run minutes; the async plumbing also
serves D4's relocation path. V1-sync-only rejected.
