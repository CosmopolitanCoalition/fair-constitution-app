# Operator Queue — open decisions

*Rebuilt 2026-07-26 from all twelve lanes' DIRECT reports, after the board was scrapped.*
*Supersedes the 2026-07-25 tier structure entirely — those tiers were built from board posts
that lagged the work.*

> **⚑ STANDING CORRECTION: "BUILT" IS NOT "TESTED".**
> A phase row reading BUILT means code exists and pins pass. It does **not** mean a human walked
> a real path and it behaved. Every status must say which one it means.
>
> **And the tour cannot be validated until we define what we expect users to DO.** A parity tour
> checks pages against mockups; it does not check that a person can accomplish anything. User
> journeys come first. See `docs/plans/playtest/DEV_TIME_AND_ROLE_CONTROLS.md`.

---

## 🔴 NEW, TOP OF THE LIST — the box needs your hand (2026-07-26 evening)

**`docker exec` is broken at the container-runtime layer.** Not saturation, not the earlier
artisan fatal — a distinct failure:

```
OCI runtime exec failed: error starting setns process:
fork/exec /proc/self/fd/6: no such file or directory
```

**The containers are healthy and the app serves HTTP 200s throughout.** What is dead is the
ability to attach a process to a container — so **no lane can run tests, migrations, tinker or any
artisan command**, while every page keeps loading normally. That combination has been
misdiagnosed twice today as somebody's code.

**It needs your machine** — a Docker Desktop restart from the tray. Nobody can fix it from
inside, and I would not restart the engine blind while lane 1's healing work is in flight.

**What it is holding:** lane 15's `AchievementService` — the last of the four education changes,
and the one that makes achievements actually fire. Until it ships, **every step of the walk works
and no medal appears**, which is the exact false-failure the worksheet exists to prevent.

Everything else continues — the fleet is committing docs, UI and design work, none of which needs
exec.

---

## What is actually open — six items, nothing else

Every lane filed a four-way classification directly. **Three lanes withdrew their own claim on
the operator** once asked to state it plainly (3, 13, 15), and **one lane's open question had
already been answered before it asked** (4). What remains is real.

### Acts only he can perform

| # | Lane | The act | The fact that decides it |
|---|---|---|---|
| A1 | **2 Cloud** | One Ubuntu VM, three DNS records, one command | **Does he control the `worldofstatecraft.org` DNS zone?** If not it stops at step 2 and lane 2 writes the free-hostname workaround first. Port **7882 must be UDP** — voice fails silently on TCP. Lane 2 offers a self-signed rehearsal on the dev box that costs him nothing. |
| A2 | **10 Video** | Record `projects/cga-intro/01-script.md`, then say "recorded" | 201 words, 7 beats, ~1m40s. One command then produces master, captions, three thumbnails, per-beat clips and the summary file lanes 11 and 12 read. Their four open questions all have working defaults — **he should ignore them and just record.** |

### Decisions

| # | Lane | The question | Recommendation |
|---|---|---|---|
| D1 | **5 Translation** | Who settles constitutional vocabulary in a new language? | **Freeze the 38 terms when a locale opens** (~1 min per language), human-vet before anything is public. French and Portuguese shipped with **0 of 38 terms constrained** — a model guessed "Jurisdiction" and "Common Good Corporation". Correcting later invalidates every string containing the term, and the cost compounds per language. |
| D2 | **12 Social** | How do posts fire when his computer is off — self-host, pay a service, or fourteen hand-written clients? | **Self-host Postiz** on lane 2's Azure box. The axis is credential custody: self-host keeps every password on his hardware; a paid service ($29–599/mo) puts them on someone else's. Whichever he picks sits behind one interface, so a wrong choice costs a module, not a rewrite. None of the three fixes platform *permission* — TikTok, Pinterest and YouTube still need their own review. |
| D3 | **14 Coalition** | The two organisations' exact names, and which is the parent | **Confirm the documents** unless he knows otherwise. The public-domain flag is **one-way and permanent**, and it lands on the child — so getting the direction wrong is the one thing here that cannot be undone on a live instance. His sites read `cosmopolitanparty.org`; the docs say "Cosmopolitan Party Foundation". **The plan does not need his answer — only the seeding does.** |
| D4 | **all four workflow lanes** | Private remote, or second disk? | **Private remote for the source; never public.** All four filed credential audits: no keys, tokens or `.env` files anywhere. Constraints: lane 9 holds ~60 files of third-party skill code plus internal roadmap detail (fine privately, not publicly); lane 12 needs `dryrun/` and `queue/` gitignored *before* any push, and carries one real email in `tests/test_counting.py:254`; **lane 11 holds 20 seconds of his real recorded voice plus 20 cloned clips — that never leaves the machine.** Lane 11's asymmetry makes this cheap: **its irreplaceable part is 639 KB of 71 files**; the other ~17 GB is re-downloadable weights and Python environments. |

### Gates only a default, not a build

| # | Lane | What |
|---|---|---|
| G1 | **11 Dubbing** | The blind listening test — `out\_blind\`, files A/B/C/D against the muted `Affiliate Report-Silent.mp4`. One of the four is the HeyGen track he already pays for; the key stays withheld. **If he cannot pick it out, that is the strongest possible result.** It decides which engine is *default*; it blocks nothing. |
| G2 | **11 Dubbing** | How his own name **"JD" is pronounced** per language — 94 occurrences. Lane 11 will not ship a language where this is unresolved. |
| G3 | **9 Decks** | The house-style verdict — **and lane 9's own advice is that he should not spend it yet.** Let them mine his 136-slide deck first; then his verdict becomes "change this, keep that" instead of "it doesn't look like my deck." |

---

## Needs nothing from him — say go and they run

| Lane | Next action |
|---|---|
| **13 Economy** | Cast the open committee vote — under a minute, fictional legislators, cast in software |
| **4 Sim World** | The counting stage. Its question closed itself: lane 3 shipped the fix fifteen minutes after it asked |
| **6 Tour** | The playtest worksheet |
| **3 Institutions** | The provisioning progress screen |
| **15 Education** | Four code changes (not five — he already rejected the timestamp one). The "migrate slot" is fleet-internal turn-taking and was never his to grant |
| **9 Decks** | Mine the 136-slide deck, then rebuild the style against it |
| **11 Dubbing** | Dub a second subject in Arabic — exercises the right-to-left caption path, built but never run |
| **1 GeoData** | Running under his direct orders. Shipped `0e9eda0` — drift is always wrong |

---

## Closed this round

- **The counting engine's 5–9 band** — `5fbdb9d`, pinned `2c4a8ee`, corrected `059b168`. Proven end
  to end: San Marino seats 31 Type A + 27 Type B = 58, and the bicameral acts that used to fail
  now carry.
- **Seat drift** — `0e9eda0` under his ruling. Recorded as step 7 of the apportionment law in
  `CLAUDE.md` (`de9a204`).
- **Lane 5's uncommitted locales** — `585b1e5`, 129 files.
- **The fleet board** — scrapped. `docs/plans/docs-recon/FLEET_CONTEXT.md` is the standing brief.

## Known and unowned

- **Nobody has seen most of this app.** Lanes 3, 4 and 13 each reported independently that they
  cannot render or screenshot the pages they built. "Built is not tested" here is structural, not
  lazy — a fleet has been building screens blind.
- **`Public record` and `Testimony` have no rendering in any of the six locales** — so the
  vocabulary gap is not confined to the two new languages.
- **"Completed a journey" is a self-reported tick**, not a verified act. Lane 6: in a playtest it
  is the one green light that means nothing.
