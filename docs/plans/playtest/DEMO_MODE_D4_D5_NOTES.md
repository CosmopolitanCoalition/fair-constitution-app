# Demo mode — D4/D5 design notes + the D6 re-scope

*Lane 4, 2026-07-28. Wave 1, Slice 2 (V3_SYNTHESIS_PLAN §3). D1–D3+D7 are
shipped (`5fa4ab3`, `d9b0b22`); these are the notes the marching order
requires before the two remaining builds, plus one scope correction found
by reading the code rather than the plan.*

---

## D4 — "Assume a resident/role of a place": one composed endpoint

**What it replaces.** Today the walkthrough's first step is three manual
moves: find a user who fits (`GET /dev/users` + guesswork), maybe grant
them residency somewhere (`POST /dev/residency/grant`), then become them
(`POST /dev/login-as`). The mockup's demo bar promises one act: pick a
place, pick a role, be that person.

**The endpoint.**

```
POST /dev/assume        { jurisdiction_id, role }        → dev.assume
php artisan dev:assume  {place-slug} {role}              (parity from birth)
```

1. **FIND** — a user who already holds `role` in that jurisdiction, using
   the REAL role derivation (roles are derived, never stored — Phase 1
   law). Seated roles (R-08 board, R-09 legislator, R-19/R-20 court) can
   ONLY be found: a seat exists or it does not, and this endpoint will
   never seat anyone.
2. **RELOCATE** (residency-derived roles only, and only when nobody fits)
   — pick the least-entangled existing synthetic user (no seat, no
   candidacy, no board) and run the REAL dev residency grant into the
   target place. This WRITES residency records, which is what makes the
   gate below the right one.
3. **BECOME** — the login-as session switch, full reload client-side
   (identity change = session rotation; the DevPersonaSwitcher rule).

**Refusals are answers.** No user holds R-09 there → the honest response
is "nobody is seated there — run an election or seat one", not a minted
legislator. The endpoint NEVER creates users (creation stays with the
seeders under `GuardsSyntheticData`) and never touches a seat.

**Gate: `DevTimeControlsEnabled`** — not the softer toolbox gate — because
step 2 manufactures residency on demand. Same family as moving deadlines:
fine on a demo mesh, fabrication on a node any real node trusts. The
audit marker (`dev/assume`, actor = who you were, target = who you became,
`dev_control: true`) files BEFORE the session switch.

**UI.** One row in `DevPlaytestPanels` (the flyout): place search
(reusing the jurisdiction picker idiom), role select limited to roles the
place can actually answer for, Assume button, full reload after.

**Building now** — the order said note-then-build for D4. Objections
redirect it mid-flight.

## D5 — Scenario presets (ruling 10: BUILD; note sent before building)

**Shape.** Demo-flyout buttons over the REAL demo seeders — a preset is
`(command, args, world-state precondition)`, nothing more. No new
capability, no bypass: the buttons queue the exact artisan commands the
terminal runs today, `GuardsSyntheticData` intact server-side.

```
POST /dev/scenario/{preset}   → queues the seeder, returns a run token
GET  /dev/scenario/state      → which presets CAN run here (precondition
                                probe) + last run's tail per preset
```

Async because `elections:demo` runs minutes, not milliseconds: the POST
dispatches a queued job that shells the command; the flyout polls state
and renders the seeder's own honest output tail (these commands already
report refusals verbatim — the `blocked` ledger idiom).

**The preset table is the mockups' scenario vocabulary mapped to what
exists** (survey 2026-07-28, corrected against every seeder's actual
requirements):

| mockup flag | preset backing | state |
|---|---|---|
| `election: approval/ranked/certifying` | `elections:demo {slug}` (compressed phases) | ready |
| `challenge` | `institutions:demo-e` | ready |
| `bicameral` | `elections:demo` on San Marino | ready |
| `marketplace` | `institutions:demo-treasury` | ready |
| `ubiRun` | `institutions:demo-treasury` (stipend half) | ready |
| `countbackFailed` | `vacancy:declare --sync` | PARTIAL — exhaustion not guaranteed |
| `emergency` · `quorumFails` · `restoration` · `unionDrill` · `liveSession` · `groupForming` · `tradeTalk` | **no seeder exists** | grayed out, labeled "no seeder yet" — honest absence, never a fake button |

Two of the nine seeders (`institutions:demo-lawmaking`,
`institutions:demo-treasury`) carry NO `GuardsSyntheticData` today — the
preset wave adds the guard to both (they mint users/wallets; the guard
audit already flagged this class). `vacancy:declare` gets it too if it
becomes a preset.

**Gate:** `DevTimeControlsEnabled` (a preset writes a world). Buttons
render only what the state probe says can run — a preset whose
precondition fails renders WHY (e.g. "needs a seated legislature — run
the election preset first"), which is the dependency ladder teaching
itself.

**Cost note:** the flyout piece is S; the queue/poll plumbing is the L in
the plan's estimate. If the desk wants it cheaper, V1 can run only the
sub-minute seeders synchronously and defer the queue path — say the word.

## D6 — scope correction: 2 of the 3 "built-but-disabled" affordances are not built

Read directly from the components, not the plan:

| affordance | reality | action |
|---|---|---|
| CaseLifecycle Back/Advance | BUILT — `interactive` prop, default off; `CaseDetail.vue` mounts without it | **one attribute on lane 3's page**: `:interactive="isDemoMode"` via the new `useDemoMode()` composable (shipped, this commit). Flagged to the desk rather than edited — judiciary pages are lane 3's. |
| Challenge-tracker simulate buttons | NOT BUILT — mockup-only (`Art4Section5Tracker.vue` docblock: the data-sim buttons "DO NOT ship") | not a toggle — NEW client UI over local state. Desk decides: build as new work or drop from D6. |
| Judiciary-home consent-meter sliders | NOT BUILT — mockup-only (`Home.vue`: "every meter is an engine snapshot", footer: "never a demo toggle") | same. Note the page's own copy argues AGAINST building it — a slider that fakes consent contradicts the engine-snapshot rail. Recommend DROP. |

**The mode plumbing itself is shipped**: `resources/js/composables/useDemoMode.js`
— world-keyed (`instance.sandbox`), never `import.meta.env.DEV` (the leak
`Districts.vue:2049` still carries; flagged for its owner in passing).
