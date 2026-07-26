# What you'll actually see if you walk the tour today

**For the operator, before he walks it. Lane 6, 2026-07-25 23:0x. Box: dev (`fcd`, :8082) — the
San Marino world lane 13 founded at 21:35.**

**Why this exists.** If you walk the app cold, some screens will be empty. Empty screens look
identical whether the feature was never built, was built but has no data here, or is built and
seeded but its clock hasn't opened yet. Those are three completely different situations with
three different owners, and guessing wrong means misreading finished work as missing work. This
tells you which is which before you start.

**How it was made — and its one limit.** Built from the live database, the live route table, and
the page files on disk. **Not from screenshots**, because I'm parked and every one of these pages
is behind a login I won't create an account for. So this predicts what renders; it does not prove
it. The screenshot pass is the first thing I do when released. Where I'm inferring rather than
measuring, I say so.

---

## The headline

| | |
|---|---|
| Stops in the app's built-in tour | **21**, in 8 acts |
| Stops in the design contract | **117**, in 15 acts |
| **The gap** | **96 stops the app doesn't tour yet** |
| Of the 21 that exist: fully live | **12** |
| Partly live | **2** |
| Empty | **7** |

**Correction, and it's mine.** I told lane 7 the contract was "120 stops in 16 acts" and they've
been quoting that to you. It's **117 in 15** — the v3 simplification pass folded seven pages into
redirects, and `MANIFEST.md:114` lists "120 stops" among things it superseded. My opening post
also called the parity tour "120-stop". The honest gap is **96**, not 99.

---

## The map — the app's own 21-stop tour

Legend: ● live · ◐ partly live · ○ empty

### Act 1 · Arrive (4 stops)
| | Stop | Status | What's behind it |
|---|---|---|---|
| 1 | `/civic` Home | ● | 202 people, 600 confirmed residencies |
| 2 | `/civic/record` My record | ● | Your own record; 31 public records exist |
| 3 | `/journeys` Journeys | ◐ | **The lesson list renders; nobody has progress.** Catalog is code (13 arcs, 10 live); `journey_progress` and `achievements` are both 0 |
| 4 | `/civic/residency` Say where you live | ● | 600 confirmations |

### Act 2 · Your place (2 stops)
| | Stop | Status | What's behind it |
|---|---|---|---|
| 5 | `/jurisdictions` Places | ● | 11 places (Earth → San Marino → 9 castelli) |
| 6 | `/legislatures` Legislatures | ● | San Marino **active**, 59 seats, 31 seated. Earth still **forming**, 37 seats, **0 seated** |

### Act 3 · Speak & gather (3 stops) — **the whole act is empty**
| | Stop | Status | Why |
|---|---|---|---|
| 7 | `/civic/square` The public square | ○ | **Not seeded.** 0 posts, 0 threads, 0 profiles. `social:demo` exists and was never run |
| 8 | `/civic/rooms` Messages | ○ | **Not seeded.** 3 Matrix rooms exist, no conversations. `matrix:demo` never run |
| 9 | `/civic/petitions` Petitions | ○ | **No seeder exists** for petitions at all |

### Act 4 · An election (5 stops) — the best act on the box
| | Stop | Status | Why |
|---|---|---|---|
| 10 | `/elections` | ● | 2 elections |
| 11 | `/elections/open-ballot` | ○ | **Timing, not missing.** The one unfinished election opens for approval at 01:22 UTC on 26 Jul — a few hours from now. The other is already certified |
| 12 | `/elections/ranked-ballot` | ○ | **Timing.** That election's ranking window is 26 Aug → 9 Sep. A month out |
| 13 | `/elections/results` | ● | **Strongest stop on the box** — 4 races certified, 200 ballots, 120 candidates, round-by-round counts |
| 14 | `/elections/candidacy` | ● | 120 candidacies |

### Act 5 · Organizations (1 stop)
| | Stop | Status | Why |
|---|---|---|---|
| 15 | `/organizations` | ○ | **The Type B seating question — the one you're weighing.** 0 organizations. The command that creates them (`institutions:demo-d`) stops at step 1 |

### Act 6 · The courts (1 stop)
| | Stop | Status | Why |
|---|---|---|---|
| 16 | `/judiciary/docket` | ○ | **Not seeded.** 0 cases, 0 panels, 0 advocates, 0 jurors. `institutions:demo-e` never run |

### Act 7 · The record (4 stops) — solid
| | Stop | Status | Why |
|---|---|---|---|
| 17 | `/system/public-records` | ● | 31 records |
| 18 | `/system/audit-chain` | ● | **7,207 entries, chain verified.** The single most convincing screen you have |
| 19 | `/system/amendments` | ◐ | The rules list renders; **the change ledger is empty** (0 changes). ⚠ See the copy defect below — on this box that list *is* the page |
| 20 | `/system/clocks` | ● | 21 clocks, 35 armed timers |

### Act 8 · Help (1 stop)
| | Stop | Status |
|---|---|---|
| 21 | `/support/report` | ● | Static form |

---

## Why each empty screen is empty — grouped by who fixes it

**One ruling from you fixes the first group. The rest are commands or code.**

| Reason | Stops | Who fixes it |
|---|---|---|
| **Type B seating** — the executive, the org module and co-determination all sit behind one blocked command | 1 on this tour, **14 in the full contract** | **Your ruling.** Lane 13 found it; it isn't their lane to fix |
| **Not seeded** — the code works, a command exists, nobody ran it | 3 | Anyone; minutes each |
| **No seeder exists** — the code works and there is no command to fill it | 1 here, **16 in the contract** | Needs writing. See below |
| **Timing** — built, seeded, and the clock simply isn't in that window | 2 | Nothing to fix. Move a date if you want to see them |
| **No page yet** — the back end is live and the screen doesn't exist | The whole economy | **Mine** |

### ⚑ The biggest hole isn't on this tour at all
**Lawmaking is the largest act in the design contract — 16 of 117 stops — and there is no command
that seeds a single bill, committee, referendum or petition.** `elections:demo` seats a chamber
but files nothing for it to do. So roughly one stop in seven walks into an empty room, and unlike
the others it can't be fixed by running something that already exists. Flagging it as the single
highest-leverage fixture gap; it isn't my lane to build.

### ⚑ The economy is live underneath and has no screens — and that's my half
Lane 13 shipped the routes and the controller. **Six routes are live right now** — `/economy`,
`/economy/market`, `/economy/market/{listing}`, `/economy/treasury`, `/economy/units`,
`/economy/wallet` — and every one of them asks for a Vue page that doesn't exist
(`resources/js/Pages/Economy/` is not there).

This does **not** degrade politely. `app.js:10` resolves pages with `resolvePageComponent` over a
glob of `Pages/**/*.vue`; a miss **throws**, so a signed-in visit gets a blank screen, not an
empty state. The server answers 200 — the break is in the browser. **Don't walk to `/economy`
until I've landed the pages, or you'll see a white page and reasonably conclude the economy is
broken.** It isn't: underneath sit 369 ledger entries, 60 wallets, a 250,000 mint, a completed
60-person stipend run, 3 listings, a settled sale and 5 assets.

I told lane 13 to ship routes without waiting for my Vue so their half wouldn't stall. That was
right for throughput and it produced exactly this window. Two ways to close it, both cheap: I
land six pages when released, or they add a temporary "coming soon" component. Either is minutes,
but until one happens those routes are a trap for anyone walking the app.

---

## The 96-stop gap, by act

Acts in the design contract with **no live tour coverage at all**:

| Contract act | Stops | Note |
|---|---|---|
| Lawmaking | 16 | No seeder — see above |
| The economy | 13 | Engine live, pages missing — mine |
| Run a node | 10 | Solo box: no peers to show |
| Learn & get help | 10 | Mostly static content, not world data |
| The judiciary | 8 | 1 live stop covers 8 contract stops |
| Organizations | 8 | Type B |
| Places & their processes | 7 | Union/split/restoration need >1 country |
| The executive | 6 | Type B |
| Records & the clock | 5 | 4 live stops cover these well |
| Recognition & reach | 2 | Waiting on Phase I |
| For the build team | 4 | No live data by design |

---

## One copy defect, and this box makes it worse

`resources/js/Pages/System/Amendments.vue:34` lists this as hardened constitutional law:

> "5–9 seats with mandatory subdivision above 9"

**It has no subject**, so it reads as a cap on legislatures — the misreading lane 7 settled
against at 22:05, and which has already cost two lanes a stall. The rule is
`max(5, round(pop³√))` with **no ceiling**; the 5–9 band governs **districts**.

It matters more here than it would elsewhere: **the change ledger on that page is empty on this
box, so the rules list is essentially the entire visible page.** If you walk to Amendments, that
sentence is what you'll read. Lane 7 has ruled it should be fixed from their approved line; it's
one string and it's queued for my release. The rest of the app already says it correctly
("34 districts of 5–9 seats").

---

## Two things I saw but did not verify — someone else's to confirm

1. **A doc comment disagrees with the code on San Marino's Type B count.**
   `CommitteeService.php:31` describes San Marino as "(32a:9b)" — 9 Type B seats. The live box
   says **27**, and the activation test asserts 27. Likely a stale comment, but the committee
   apportionment worked example sits on it. @lane-03's to confirm.
2. **A possible ceiling contradiction I am not qualified to call.** A castello's seat count looks
   like it gets clamped to 9 via a hard ceiling, while the settled rule says there's no ceiling on
   a legislature total (the 9 governs districts). These may be different code paths and I may
   simply be reading it wrong — flagging it because it touches a rule now being published
   publicly, not because I think it's broken. @lane-01 / @lane-03.

---

## What I'd do first, if the goal is a good walk

1. **Run the three seeders that already exist** — `social:demo`, `matrix:demo`,
   `institutions:demo-e`. Lights up 3 empty stops for a few minutes' work.
2. **Rule on Type B seating.** It's worth 14 contract stops, and it's the only item here that
   needs you.
3. **Let me land the six economy pages** (or have lane 13 stub them) so `/economy` stops being a
   trap.
4. **Decide whether Lawmaking gets a seeder.** Biggest act, no command, not my lane.

Nothing above needs my release except item 3. This map is free.
