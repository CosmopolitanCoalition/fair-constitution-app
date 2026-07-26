# Two instances, and the road to alpha

*Operator, 2026-07-26, at the pause before the first walk. Recorded because it is direction rather
than a task, and direction is what evaporates over a break.*

---

## The product is TWO meshes, and they mirror each other

### 1. Simulated Earth — single player, the learning campaign
- **Read-only except the player's own actions**, and those actions are **invisible to everyone
  else**. Nothing a learner does touches anyone.
- **The learning campaign IS the walkthrough.** The same walks in
  `docs/plans/playtest/PLAYTEST_WORKSHEET.md` that the operator is about to do by hand — a player
  does them afterwards, as the campaign.
- So the worksheet is not scaffolding to be thrown away. **It is the first draft of the product's
  onboarding**, and every card that turns out to teach badly is a product defect rather than a
  test defect.

### 2. Empty Earth at scale — real time, multiplayer
- **The real thing.** People scale up and play the version that most simulates life itself.
- They register organisations, stand for office, hold office, make economic decisions —
  everything people do when self-organising.
- **Self-organised quick play is the other half, and it is the part with no constitutional clock
  on it:** making a court case from scratch, hosting a debate, running a committee session inside
  your own organisation. **Same mechanisms, no waiting.** The constitutional clocks govern the
  institutions; the self-organisation infrastructure is available whenever people want it.

**The single-player campaign is the mirror of the real-time world.** Same surfaces, same acts,
different stakes and different visibility.

> **"These are what we are effectively walking. As we walk through we will be building these out."**

---

## The sequence from here

| When | What |
|---|---|
| **Paused now** | Development stops. Everything pushed. Lanes 9–12 leave the repo (below). |
| **Tuesday evening** | **The walk** — the game box, 54 cards across 9 sections. Cleanup as we go. |
| Then | **Certify** what the walk proves. |
| Then | **A fresh cloud box** — online with all the trimmings, **from-scratch data ingestion.** Needs its own fleet lane, to be created at that time. |
| Then | **Test fresh-to-scale**, clean up bugs as they surface, **a second verification walk.** |
| Then | **Declare alpha, public.** |

### Poland is the deadline that gives this its shape
> **QR codes handing out invite links — people jump in and get to learning, or playing, or
> exploring.**

That is the acceptance test for the whole thing: a stranger with a phone and a QR code reaches
something worth their time, with no explanation from the operator.

---

## What the game box actually needs (measured 2026-07-26)

The setup flow the operator described — geodata ingestion, district mapping and institution
scaling as **one multi-day, chunked, stall-recoverable run with a visible progress UI** — is the
thing that produces a full game world. **The box is sitting two thirds of the way through it.**

```
geodata ingest      ✅ 956,336 jurisdictions
district mapping    ✅ 1,987,430 districts across 955,130 legislatures
institutions        ❌ executives 0 · judiciaries 0 · committees 0 · boards 0 · orgs 0
people              ❌ members 0 · elections 0 · users 1
```

**Two distinct gaps, and they are separate work:**
1. **Tables that do not exist yet** — `judges`, `committee_members`, `boards_of_governors`, and
   the whole economy plane. These arrive with the **16 pending migrations**, which are
   **additive-only and verified non-destructive** (every drop is in a `down()`; the one hit inside
   an `up()` is a trigger that makes the ledger *refuse* truncation). **The 956k jurisdictions are
   not referenced by any of them.**
2. **Rows that were never created** — the Phase D/E tables are already present and empty. Nothing
   ever provisioned them. That is app code (`institutions:provision`, the populate engine), not
   schema.

**`/building` is the progress UI for exactly this.** It reads the world rather than a job, so it
answers *"is this world finished?"* at rest and cannot be misreported by a crashed run. On the dev
box it reads 11/11. **On the game box it would read 0 of 955,130 and climb.**

**Unmeasured and the only real unknown:** what provisioning and populating cost at 956k rather than
at 15. Lane 4's engine took Niue from empty to governed today with a real STV count; nobody has
run it at planetary scale.

---

## Lanes 9–12 leave the repository

**They are being generalised into reusable tools and moved to OneDrive**, so they can serve other
clients, the business site, and personal work — not just this project.

| Lane | Tree | Notes |
|---|---|---|
| 9 Presentations | `E:\workflows\presentations` | no git; audited clean of secrets; holds vendored third-party code + internal roadmap detail — **private only** |
| 10 Video | `E:\workflows\video-factory` | no git; one 14.5 MB voice sample, regenerable in one command |
| 11 Dubbing | `E:\workflows\video-translate` | no git; **irreplaceable part is 639 KB / 71 files** — the other ~17 GB is re-downloadable weights and venvs. **Never sync those.** |
| 12 Social | `E:\workflows\social-posting` | **has git, 16 commits — move the folder whole so `.git` survives.** `dryrun/` and `queue/` already ignored |

**Three OneDrive hazards, from lane 12:** syncing a live `.git` across machines can corrupt it;
`desktop.ini` recurrence is *more* likely on OneDrive, and that exact file broke the fleet's
`git fetch` on 2026-07-23 by landing in `.git/refs`; and OneDrive holding a handle open mid-sync
makes atomic writes fail intermittently.

**Each lane has already inventoried its own hard-coded paths.** Roughly a third of lane 12's tree
is already generic; lane 9's is one JSON file plus a dozen constants; lane 11's `paths.py`
write-refusal rail must stay a rail — **configurable to ADD protected roots, never to remove
them, and failing closed on an unreadable config.**
