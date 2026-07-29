# The live-room freshness store — CONTRACT (desk review BEFORE composition)

*Lane 3, Wave 3 (2026-07-29). Slice 6 substrate. The desk RULED the substrate
**poll-first** (V3_SYNTHESIS_PLAN §7.1): extend the proven 5-second Inertia
partial-reload to `session/committee/case/board` props behind a
**transport-agnostic** store, so Reverb/SSE can replace polling later as a
drop-in — **no push layer this wave**. This is that store's contract. Per the
desk's Wave-3 delta #3, `LiveCivicRoom` composition is HELD until the desk rules
the two open knobs at the end.*

---

## 0. What it is (and what the codebase already decided for me)

A single Vue **composable** — `resources/js/composables/useLiveRoom.js` — the
freshness layer every live civic room mounts. Two facts the codebase settles, so
they are **statements, not questions**:

- **Composable, not a store object.** There is **no Pinia** in `package.json`;
  the established idiom is `resources/js/composables/use*.js` (`useAnnounce`,
  `useVoiceRoom`, `useDemoMode`, `useDeviceIdentity`, …). A client-side store
  cache would also fight the core principle below (server snapshots are truth),
  so a composable is both idiomatic and correct. No new dependency.
- **The pattern is proven and already duplicated 10×.** `MatrixCommons.vue`
  (lines 73–110) is the reference implementation; the identical hand-rolled
  `setInterval` + `router.reload({ only })` loop also lives in `PrivateRoom`,
  `RankedBallot`, `Results`, `VacancyCountback`, `Build/Progress`, `Operations`,
  `Districts`, `SimConsole`, `Step2_MapData`. `useLiveRoom` is the consolidation
  of that pattern — **but this wave wires only the live-room props**; retrofitting
  the other nine is a later, separate, peer-file-touching pass (out of scope,
  noted so it isn't lost).

## 1. The principle (non-negotiable — the reason the transport can be swapped)

**Server snapshots are the truth. The store only sets freshness. Correctness
never rides the transport.** Every prop the room renders is an engine/DB snapshot
produced by the controller (vote tallies from `VoteTally`'s constitutional
posture, agenda from the engine re-guard, presence/queue from the server). The
store never computes, never merges client-authoritative state, never writes,
**never decrypts**. It asks the server for a fresh snapshot of named props and
swaps them in. Because the payload is always a whole server snapshot, *how* it
arrived (a 5s poll today, a Reverb push tomorrow) cannot change what is shown —
which is exactly what makes the transport a drop-in.

## 2. The API surface (the contract the composition binds to)

```js
const live = useLiveRoom({
  keys:        ['status','agenda','vote','presence','queue','floorHolder','chat','record','clocks'],
  //           the volatile live props (MANIFEST §1). Static props (title,
  //           jurisdiction, chairRole, chair, constitutionalOrder, forms,
  //           chairControls, reusesV1) are NEVER re-fetched.
  isLive:      () => room.status.state,   // returns 'open'|'recess'|'scheduled'|'adjourned'
  busy:        () => anyFormProcessing,   // skip a tick while the player has a write in flight
  cadenceMs:   5000,                      // default; see open knob Q1
  strategy:    'poll',                    // the transport seam; see §3. Only 'poll' this wave.
})

// what a page reads:
live.lastSyncedAt   // ref<number|null> — epoch ms of the last successful snapshot
live.isStale        // computed<bool>   — now - lastSyncedAt > 2 × cadence (drives a "reconnecting…" chip)
live.isPolling      // ref<bool>        — is a transport currently armed
live.refresh()      // force one immediate snapshot (used on visibility-regain and after a local write)
```

The composable owns its own `onMounted`/`onBeforeUnmount` and the
`visibilitychange` listener — a page calls `useLiveRoom(...)` once and is done, the
way `MatrixCommons` is today but without the boilerplate.

## 3. The transport seam (how "transport-agnostic" is honored — no push built)

`strategy` is the single switch point. This wave ships exactly one strategy,
`'poll'`, which IS the proven MatrixCommons loop:
`router.reload({ only: keys, preserveScroll: true, preserveState: true })` on a
`setInterval(cadenceMs)`. The seam is a **documented contract**, not a built
abstraction (honoring "no push layer this wave"): a future `'reverb'` or `'sse'`
strategy must satisfy exactly one obligation —

> **On any signal that a live prop may have changed, obtain a fresh server
> snapshot of `keys` and hand it to the same Inertia merge the poll uses. Never
> deliver a partial/computed delta; never make the client authoritative.**

So Reverb/SSE, when it lands, is a *nudge to refresh*, not a data channel — the
snapshot still comes from the server, and every §4 guarantee still holds
unchanged. That contract lives here and in the composable's docblock, **never in
a page** — pages only ever call `useLiveRoom({...})`.

## 4. The guarantees (carried verbatim from the proven loop — pinned)

Each becomes a pin in `LiveRoomStoreContractTest` (a JS/Vitest or a Dusk-free
prop-contract pin, matching the fleet's existing front-of-house test style):

1. **Hidden-tab pause.** `document.hidden` ⇒ no ticks; regaining visibility fires
   one immediate `refresh()` then re-arms (MatrixCommons `onVisibility`).
2. **In-flight-write guard.** `busy()` truthy ⇒ the tick is skipped, so a poll
   never cancels the player's open `useForm` post (MatrixCommons line 82).
3. **Snapshot-merge only.** `preserveScroll` + `preserveState` ⇒ scroll position,
   focus, the compose box, and the live AV call are untouched across a refresh.
4. **Read-only, decrypt-free.** The store issues GET-shaped Inertia reloads only.
   It is structurally incapable of a write or a ballot decrypt — the
   `BallotSecrecyTest` boundary is never approached (relevant because a live
   election-forum room renders standings; those come from the controller's
   already-safe projection, never from the store).
5. **Terminal stop.** A concluded entity stops the transport (see Q2) so an
   adjourned room's static transcript costs nothing.

## 5. Scope boundary (what this contract does NOT do)

- It does not touch the other nine inline pollers (peer files; later pass).
- It does not add real-time infra, a websocket server, or any daemon.
- It does not change any controller's prop computation — controllers already
  emit these props; the store just re-requests them.
- It carries no state across rooms and no cross-client shared state.

---

## ⚖ TWO OPEN KNOBS — desk ruling requested before composition

### Q1 — Cadence policy: **uniform** vs **tiered**

The live room renders props of very different velocities: the Matrix timeline,
presence, and the hands-raised queue move at conversational speed; the vote
tally and agenda change only on a chair act; `clocks` is a client-side countdown
that needs no server tick at all once seeded.

| Option | What it does | Cost |
|---|---|---|
| **A · Uniform 5s (RECOMMENDED)** | one timer re-runs all `keys` every 5s, exactly as MatrixCommons proved | Simplest, one timer, proven. Re-runs slow props (vote/agenda) needlessly — at planet scale that is extra controller work per open room per 5s (lane 2's concern). |
| B · Tiered | fast group (chat/presence/queue) 5s; slow group (vote/agenda/record) 12–15s; `clocks` never polled (client countdown) | ~½ the server work on the slow props; the store manages 2 timers + 2 key-sets; marginally more surface to pin. |

**Recommendation: ship A (uniform 5s), but make `cadenceMs` accept a
per-group map** so lane 2 can flip to B under real load **without a page rewrite
or a composition change** — the composition binds the same API either way. That
keeps the proven-simple default while leaving the load valve inside the store.

### Q2 — Liveness gate: when does the room poll at all?

MatrixCommons keys polling on "the room exists." A civic room additionally has a
lifecycle `status` (open/recess/scheduled/adjourned).

| Option | Behavior | Cost |
|---|---|---|
| A · Poll whenever the page is open | matches MatrixCommons | An **adjourned** room (static transcript) keeps hitting the server every 5s while anyone reads it — pure waste, ×potentially many readers. |
| **B · Status-gated (RECOMMENDED)** | poll at full cadence while `open`/`recess`; a slow **heartbeat** (~30s) while `scheduled` so an opening is noticed; **stop entirely on `adjourned`** (transcript is static — `refresh()` still available via a manual "check for updates") | A tiny state machine in the store (3 pins). Correct load profile: work is proportional to *live* rooms, not *open browser tabs*. |

**Recommendation: B.** It is the honest reading of "freshness" — a concluded
proceeding is not live — and it is the difference between server load scaling
with active governance vs. with idle spectators. The heartbeat-while-scheduled
rung means a room that opens on schedule still comes alive without a manual
reload.

---

**On the desk's word on Q1 + Q2 I build `useLiveRoom`, pin §4 + the chosen
knobs, and only then compose `LiveCivicRoom` on top of it. Room provisioning and
the (already-landed) oversight fold proceed meanwhile.**
