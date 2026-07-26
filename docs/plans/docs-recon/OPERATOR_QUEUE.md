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

## 🔴 TOP OF THE LIST — THE BOX IS OUT OF MEMORY, AND THAT IS THE WHOLE STORY

**Superseded everything below it. The Docker faults were never Docker's fault.**

```
WSL VM total          7.64 GiB   ← WSL's default: half the host
in use at collapse    6.32 GiB   (83%)
host RAM             15.80 GiB
.wslconfig            DOES NOT EXIST
```

**Two complete stacks share that VM** — the game box `fc_*` and the dev box `fcd_*`, every
service of each. `fc_postgres` 1.59 GiB · `fc_horizon` 1.59 GiB · `fcd_horizon` 1.12 GiB ·
`fc_redis` 0.70 GiB.

**Under memory pressure the WSL kernel reclaims aggressively, and every "Docker bug" chased on
2026-07-26 was a symptom of it:** `setns` failing to fork · overlay layers evicted from page cache
and returning *"no such file or directory"* · the daemon's own API answering 500 · `ENOMEM` in the
Vite log. Lane 5's observation that it **degraded progressively over ~40 minutes and never
recovered on container restarts** is exactly what memory exhaustion looks like and exactly what a
Docker bug does not.

**⚑ A Docker Desktop restart WOULD have appeared to fix it** — killing every container frees
~6 GiB — **and it would have come back within hours.** That is why the instinct was right and the
diagnosis was wrong. This desk recommended that restart twice; both times it would have bought a
few hours and taught us nothing.

### Your options — this is a hardware ceiling, not a bug

| | What it costs |
|---|---|
| **Raise the WSL ceiling** — create `%USERPROFILE%\.wslconfig` with `[wsl2]` / `memory=10GB` | A restart of WSL. Leaves ~6 GB for Windows on a 15.8 GB machine — workable on a dev box, tight if you also run heavy apps. **Keeps both stacks, which the walk needs.** |
| **Stop the `fc_*` game-box stack when not using it** | Frees ~4 GiB instantly, no config change. **But Section H of the walk is the game box** — planetary districting exists nowhere else — so it has to come back up for that part. |

**Recommended: do both.** Raise the ceiling to 10 GB for headroom, and keep the game box stopped
except when walking Section H.

**⚑ And carry this to the cloud sizing.** Two stacks needed more than 7.6 GiB on real work. Lane
2's Azure VM is a **single** stack, so it is not the same arithmetic — but the number to give them
is measured now rather than guessed: a single stack's resident set here is roughly 3–4 GiB before
any load.

### Already fixed, lane 4's own share
`supervisor-sim` was correctly enabled (238 jobs sat on a queue with no consumer), but Horizon's
`balance: simple` keeps every process **resident**, so `cores-2` put **ten idle PHP workers —
about a gigabyte — on a box already at the edge.** Capped at 4 locally, full pool retained in
production. `665dc8d`. Measured after: **6.32 → 5.43 GiB, and Vite came back and stayed up.**

---

## SUPERSEDED — the exec fault (kept for the diagnosis, not the instruction)

**`docker exec` was broken at the container-runtime layer:**

```
OCI runtime exec failed: error starting setns process:
fork/exec /proc/self/fd/6: no such file or directory
```

**This was a SYMPTOM of the memory exhaustion above, not a separate fault.** And it never blocked
anyone: **`docker run` and `docker compose run` create a fresh namespace and never touch `setns`**,
so every lane could run artisan, tests and migrations the entire time. Lane 4 had been doing
exactly that all session; this desk took the blocker at face value, told three lanes to wait, and
put it at the top of this file. **The fleet sat for hours on a workaround that already existed.**

The working form, verified:
```bash
MSYS_NO_PATHCONV=1 docker run --rm --user 33 --entrypoint php \
  --network fcd_fc_network \
  -v "E:/fair-constitution-app:/var/www/html" \
  -v fcd_vendor:/var/www/html/vendor \
  -w /var/www/html fcd-app artisan <command>
```

**The containers are healthy and the app serves HTTP 200s throughout.** What is dead is the
ability to attach a process to a container — so **no lane can run tests, migrations, tinker or any
artisan command**, while every page keeps loading normally. That combination has been
misdiagnosed twice today as somebody's code.

**It needs your machine** — a Docker Desktop restart from the tray. Nobody can fix it from
inside, and I would not restart the engine blind while lane 1's healing work is in flight.

**Scope check, 2026-07-26 late:** it began as one container and **has since spread to all of
them** — `docker exec fcd_postgres` worked earlier and now hangs identically. So it is the
engine's exec layer, not any container's state. **A `docker restart` will not clear it and may
make things worse** (a container nobody can get inside is not guaranteed to come back cleanly).
Lane 4 was stood down from attempting exactly that.

**Deliberately not attempted from here.** The app still **serves HTTP normally** — every page
loads. An engine restart that failed would cost that too, and you are away. Preserving a
browsable app was judged worth more than unblocking lanes that can wait.

**The queue behind it, in the order it should run when the box is back:**
1. **Lane 1 — activate the nine castelli district maps.** They are DRAWN and EXACT (below); one
   `finalize` step away from active.
2. **Lane 4 — the Niue run**, and San Marino's castelli then seat themselves, because lane 4's
   pipeline already sizes rosters from `racePlan()` and needs no change.
3. **Lane 15 — `AchievementService`**, the last of the four education changes and the one that
   makes achievements fire at all. Until it ships, **every step of the walk works and no medal
   appears** — the exact false-failure the worksheet exists to prevent.
4. Everyone else — tests, migrations, suites.

### ✅ Good news that changes what the walk looks like — the nine castelli have maps
Lane 1 took the ask and drew all nine, **every one seating its budget exactly, zero drift**:

```
Serravalle 22/22 · Borgo Maggiore 19/19 · Città di San Marino 16/16
Domagnano 15/15 · Fiorentino 14/14 · Acquaviva 13/13
Chiesanuova 10/10 · Faetano 10/10 · Montegiardino 10/10
```

They are still `draft` — finalize hit **stale compiled code inside the container**
(`childLayerIsInert()` existed in git and not in the running app), and the restart to clear that
is when exec died. So the honest line is **"nine maps exist and are exact, pending one activation
step"**, not "nine empty chambers". Lane 6 has written the walk cards to be correct either way.

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
