# Operator Queue — open decisions

> **⚑ STANDING CORRECTION (operator, 2026-07-26): "BUILT" IS NOT "TESTED".**
> A phase row reading BUILT means code exists and pins pass. It does **not** mean a human walked a
> real path and it behaved. The fleet has produced a great deal of the first and almost none of the
> second. Every status report must say which one it means.
>
> **And the tour cannot be validated until we define what we expect users to DO.** His words: *"in
> order for the tour to even be validated, we have to actually ask ourselves, what are the things we
> expect our users to do."* A parity tour checks pages against mockups; it does not check that a
> person can accomplish anything. **User journeys must be defined first** — court case end to end,
> the demo journeys — and the tour validated against those. He wants that conversation with this
> desk, and it is the next substantive piece of work here. Raw material: lane 15's 13 journey arcs
> and its 127-achievement catalog derived from real acts.

*(rebuilt 2026-07-25 22:10 from every lane's TRANSCRIPT; header added 2026-07-26)*

Rebuilt after reading all eleven idle lanes' actual chat transcripts, not just their board posts.
That changed the picture materially: **most of the fleet is idle for want of a NUDGE, not a
decision.** Organised by what it costs you, cheapest first.

Lane 1 is the only lane still running (healing wave). Everything below is stopped.

---

## TIER 1 — say "continue" and they run (no decision content at all)

| Lane | What it's waiting for |
|---|---|
| **13 Economy** | **Nothing.** It ended its turn "Starting L-3" and its board says Blocked: nothing. All four D-14 questions were answered. It needs a nudge, that is all. L-1 and L-2 are shipped. |
| **5 Translation** | A plain go for **step 4** (the glossary). It runs a per-step "you are good to proceed" cadence with you — that is how step 3 was authorised. No numbered decision open. |
| **6 UI + A11y** | The plain **GO** it has been holding for since 09:36. It posted its tour method first so you could veto the shape before it burns a tour. |
| **14 Coalition** | GO to **write the plan** (its seeding question gates seeding only, not planning — see Tier 3). |

## TIER 2 — one short answer each

| # | Lane | The question | Recommendation |
|---|---|---|---|
| Q1 | **3 Institutions** | Are the **tier-curve parameters legislative settings (amendable) or founder-only config**? They work and their bounds are enforced but are registered as neither. Making them amendable means editing two PROTECTED files, "which I won't do without your word." | **Amendable.** A population curve that decides when a government may boot is exactly the kind of thing a world should be able to change by act; founder-only makes it a permanent dictate — the same defect lane 13 just closed for the monetary levers. |
| Q2 | **4 Sim World** | Its **DECISION 3** — whether to build batching for governance acts. Step 3 is parked on it; everything else in its plan proceeds. | Ask it to restate the decision in one line, then rule. It has been parked longest on this. |
| Q3 | **12 Social** | **API-immediate or native schedulers?** The deciding fact: **13 of 14 platforms have no usable API scheduling**; exactly one (Facebook Page) is free and ungated. It defaults to API-immediate if unanswered. | **Native schedulers** for the 13, API only where it genuinely works. Also: its `publishAt` line in CLAUDE.md is "true and materially misleading" — unverified projects force videos private, so **YouTube must route through Studio**. |
| Q4 | **10 Video** | Four defaulted questions: master geometry (1080p default vs your uniformly 1280×720 library), audio bitrate (256k to match your ~244kbps), whether to **add a `Governance App` row to `Topic_Knowledge.xlsx`** (your file, untouched), and whether to stage the master into the library (default no). | Take its defaults except geometry — **match your library at 720p**. The xlsx row is worth adding: lanes 11 and 12 both key off it. |
| Q5 | **15 Education** | A **build slot** for 5 additive changes, all free *right now* because both tables hold zero rows: `journey_id`→`award_key`, `earned_at`→`awarded_on DATE` + audit_seq channel, i18n key instead of denormalized English title, the `AchievementCatalog` registry, and awarding through the existing seal path. | **Grant the slot now.** This is the `earned_at` item from the old queue, grown into a clean five-part change while it is still free. After the first real medal it becomes a data migration. |

## TIER 3 — these need real thought

| # | Lane | The decision |
|---|---|---|
| T1 | **11 Video Translation** | **The licence question outranks the cloning question.** XTTS-v2 ships under CPML, non-commercial, and the restriction **follows the output files** — and Coqui shut down in early 2024, so no exception can be granted. Complicated by the `Shop` subject and by assets produced by an L.L.C. while the app ships under the nonprofit. **Settle this first: if CPML rules XTTS out, cloning is moot.** Then the cloning question, correctly reframed by the lane: *your HeyGen library is already your cloned voice in 77 languages (4,697 tracks)*, so a decision against cloning is not avoiding it — it is **stopping**, and accepting that new tracks sound like a different person than everything shipped. Third, listening-only: a blind loudness-normalised **A/B/C/D scoresheet** awaits your ear with **HeyGen as one of the four**, key withheld — "the only quality verdict in this benchmark". |
| T2 | **2 Cloud Launch** | **Which box is sovereign.** Its finding: a peer join yields a **mirror** — authoritative for nothing, carrying no `users` — so **players cannot register on it**. Therefore the fresh Azure box must be the front door and your home box joins *it*. That inverts the intuitive arrangement and must be chosen before the first run. Also needs: **who controls the `worldofstatecraft.org` DNS zone** (it says by 08-01 or the schedule re-cuts). |
| T3 | **9 Presentations** | **Review the house style and say what is wrong** — the fix lands in `house.json` once and every future deck inherits it. Its own caveat: the 136-slide prior deck is **still unmined** (that agent died on a spend limit), so the style is "the design system's answer, not yet yours." |
| T4 | **14 Coalition** | Four seeding facts: exact legal names (is "Cosmopolitan Party Foundation" verbatim?), the parent/child direction (Foundation → 8b, Coalition of United Earth → 8a), founding details to carry, and any divergence between what the sites say today and what the app should store. |

## TIER 4 — actions only you can take (not decisions)

1. **Stand up the Azure VM and run the one-liner.** Lane 2 has verified statically only and says plainly the first real run will find something — port, DNS timing, or the ETL container. It needs 3 A records, opened ports, and **UDP 7882 for voice (TCP will not substitute)**.
2. **Record `projects/cga-intro/01-script.md`**, then tell lane 10 "recorded". Everything downstream of it is waiting. **Apply the corrected seat line first** (below) — a reshoot costs a session.
3. **Fill the Connect panel** at `file:///E:/workflows/social-posting/board.html` at your convenience — lane 12 built it so you never paste credentials into chat.
4. **Confirm two login-walled numbers** for lane 12: X's 18-month scheduling horizon and Instagram's ~75-day / 25-per-day limits.

## SETTLED BY LANE 7 THIS ROUND (no action needed)

- **The public seat rule.** Verified in code: `max(5, round(pop^(1/3)))` — floor five, **no ceiling**. The 5–9 band governs **districts**. Approved line: *"Power stays local: every district elects five to nine representatives. Bigger places simply have more districts — Earth's world legislature seats 1,999 members across 282 of them."* Lanes 10 and 12 are unblocked. **One doc item is yours:** CLAUDE.md's row *"Legislature max seats 9"* reads like a cap and has now stalled two lanes — suggest *"District max seats 9 (a legislature above 9 must subdivide into districts)"*; say the word and I will make exactly that edit.
- Migration ordinal blocks assigned; the 8 suite failures attributed as pre-existing and probably environmental; the git pathspec rule; risk items 13–20.

## DEFECTS FOUND, NOT YET OWNED

- **Your shipped library has language-untagged tracks.** `ReCombineVideos.py:223` uses `parts[1]` instead of `parts[-1]`, so `Scaling Co-Determination-Spanish.m4a` parses its language as "Determination" → `und`. **All 77 audio tracks in the Co-Determination multilingual video are untagged.** Lane 10 reported it and touched nothing of yours.
- **The app's own player emits invalid `srclang` values** (`ben_bn`, `arm`/`cze`/`wel`). Lane 11 will not fix it — changing `wp_code` would mutate srclang on 4,697 live tracks; it is a Coalition-site call.
- **212 un-indexed foreign keys** exist; lane 3 fixed only the four that reach planet scale and documented the audit query.
