# Dev time and role controls — the spec

*Lane 7, 2026-07-26. Written because three lanes (3, 4, 6) independently reported the same gap.*
*Not assigned yet. Nothing here is built.*

---

## 1. Why

The operator's playtesting plan is: assume any role, advance the clock turn by turn, walk a
journey end to end. **Six of eighteen candidate journeys are blocked on time alone**, and two of
lane 6's four minimum journeys are blocked on a chamber vote that would take 58 manual logins.

What exists today:

| Capability | State |
|---|---|
| Become any user | `POST /dev/login-as`, `/dev/impersonate/{user}` — works, no prior session needed |
| Grant residency instantly | `POST /dev/residency/grant` — declare → pings → verify, through the real engine |
| Seat yourself on a board | `POST /dev/board/seat` |
| **Fire a specific timer** | **Does not exist.** `ClockService::fire()` is callable from code and nothing exposes it |
| **Move the world forward** | **Does not exist.** No offset, no command, no route |
| **Cast a chamber's votes** | **Does not exist.** One persona at a time |

One partial exception, found by lane 3: `elections:demo --instant` drives one election's phases
synchronously. It works, and it is welded inside that single command — it cannot be pointed at a
court case, a bill, or whatever jurisdiction is on screen.

---

## 2. The design decision: shift the DATA, not the clock

Two ways to make a deadline arrive. This spec picks the second, deliberately.

**Rejected — a global time offset.** Store an offset and read every `now()` through a wrapper.
Verified against the code: **nothing centralizes "now" today.** Election windows live in
`elections.approval_opens_at`, `ranked_opens_at`, `ranked_closes_at` and are compared against
Laravel's `now()` directly. Adopting an offset means auditing every `now()` call site in the
application — including inside PROTECTED files — and **any single missed call site produces a
world that disagrees with itself about what time it is.** That is a worse failure than the gap
it fixes, because it is silent.

**Chosen — pull the deadlines backward.** To advance the world N days, subtract N days from every
armed deadline. The wall clock stays real; everything scheduled simply becomes due.

Why this is the right trade:
- It touches a **bounded, enumerable** set of columns instead of an unbounded set of call sites.
- **Audit entries keep real timestamps.** We never forge history — the record says what actually
  happened and when a human actually did it. A journey walked on 26 July reads as 26 July.
- It fails **loudly**: a deadline column nobody registered simply does not move, and the journey
  visibly stalls. An offset that misses a call site produces a plausible wrong answer instead.

### The discipline that keeps it honest

A hand-maintained list of deadline columns will rot. So:

- A **registry** of `(table, column)` deadline pairs lives in one file.
- A **test asserts every timestamp column whose name ends in `_opens_at`, `_closes_at`,
  `_expires_at`, `_ends_at` or `_at` on a deadline-bearing table is either registered or
  explicitly listed as exempt with a reason.** Adding a new deadline without registering it fails
  the suite.
- `clock_timers.fires_at` is the first entry; the three election phase columns are the next three.

---

## 3. The primitives

### P1 — Fire one named timer

```
POST /dev/clock/fire/{timer}          php artisan dev:clock-fire {timer}
```

Calls the real `ClockService::fire()`. No shortcut path, no bypass of the constitutional engine.
Returns what fired and what it caused.

### P2 — Advance the world

```
POST /dev/clock/advance  {days: N}    php artisan dev:clock-advance --days=N
```

1. **Dry run first, always.** Report every deadline that would become due, grouped by clock id and
   jurisdiction, before anything moves. The operator must be able to see what he is about to
   trigger — a 10-year advance on a founded world fires a great deal at once.
2. Subtract N days from every registered deadline column on rows in an armed/pending state.
3. Run the due sweep so the engine fires what is now due, **in bounded committed chunks with
   per-chunk progress** — the ETL rule applies; this is an operational bulk write.
4. Report what fired.

Rows already `fired`, `cancelled` or `expired` are never touched. History does not move.

### P3 — See the clock before you touch it

`/system/clocks` already renders as a read view. It gains: what is armed, what is due now, and
**what would fire if I advanced N days** — the dry run from P2, on screen.

### P4 — Cast a chamber

```
POST /dev/chamber/cast  {vote_id, yes: N, no: M, abstain: K}
```

Casts through the **real** vote engine as the seated members, exactly as `demo-d` and `demo-e`
already do in code. This is the difference between "pass a law" being a journey and being 58
logins. It must respect quorum, supermajority and bicameral dual agreement as the engine
computes them — it supplies ballots, it does not supply outcomes.

---

## 4. Gating — this must never run anywhere real

The existing dev tooling is gated on `app()->environment('local') && config('cga.impersonation')`,
registered at boot so a disabled route is indistinguishable from a missing one. These controls
take the **same gate plus their own key**, `config('cga.dev_time')`, because they are strictly
more dangerous: impersonation reads the world, these mutate constitutional deadlines.

Additional hard requirements:

- **Refused outright when the instance is federated or has any peer**, and when
  `authoritative_server_id` is set. A node that other nodes trust must never be able to
  time-travel — Full Faith & Credit means a peer's records are taken on trust.
- **`launch:assert-clean` must fail if `cga.dev_time` is on.** Lane 2's public deploy path already
  refuses `--seed` alongside `--public-url`; this belongs in the same guard.
- **Every dev clock action writes an audit entry marked as such**, so a playtested timeline is
  distinguishable from a lived one forever after. The audit log is hash-chained and is the app's
  record of truth; a fabricated timeline that reads as genuine would be the worst outcome here.

---

## 5. What it unblocks

| Journey | Blocked on | After P1–P4 |
|---|---|---|
| Call an election | nomination + voting windows | walkable |
| Cast, count, certify, seat | windows + many voters | walkable |
| Case to verdict and sentence | hearing dates | walkable |
| Petition to referendum | signature window | walkable |
| Org crosses 100 workers | CLK-13 must fire | walkable |
| Emergency powers expire | CLK-03 must fire | walkable |
| Pass a law | 58 persona switches | walkable via P4 |
| Elect a speaker · form a committee · convert an executive | 58 persona switches | walkable via P4 |

Lane 6 confirmed both of its blocked ballot stops are **clock timing, not missing code** — so
P2 lights them with nothing else built.

---

## 6. Proposed ownership

| Part | Lane | Why |
|---|---|---|
| P1, P2, P3, the registry and its test | **3** | Owns institution scaling and the constitutional clock surface |
| P4 chamber cast | **13** | Already casts chamber votes in software for `demo-d`/`demo-e`; the code exists, it needs a door |
| Wiring "advance one turn" into the pump | **4** | Owns the pump chassis. Their own read: `sim:start`/`sim:pump` are the right frame and the wrong engine, because they advance *building* the world, not time passing in it |
| Consuming all four in the worksheet | **6** | Owns the playtest worksheet |

Lane 4 also found **two dormant settings already in the database** for accelerated time and
real-seconds-per-simulated-year. They are evidence this was always intended, and they are not
what this spec builds — compressed time is a different feature from stepping a world forward.

---

## 7. Explicitly out of scope

- Any global time abstraction. See §2.
- Compressed or real-time simulated clocks. The operator ruled that waits until one slice of the
  world is gold.
- Anything that lets a journey skip a constitutional step. These controls move **time**; they
  never move an outcome. If a vote fails, it fails.
