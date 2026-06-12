# CGA Authoritative Architecture + Institutions Requirements Map

Sources read in full:
- `E:/fair-constitution-app/App Docs/CGA_Architecture_Plan.docx` (complete text extracted)
- `E:/fair-constitution-app/.claude/worktrees/practical-payne-17d537/docs/extracted/fair_constitution.md` (369 lines, complete)
- `E:/fair-constitution-app/App Docs/CGA_Constitutional_Roles_Forms_Chart.xlsx` — sheets "2. Institutions" (17 institutions), "4. Dependency Chain" (37 steps across 7 phases), "6. Bootstrap Sequence" (30 steps, 7 stages)
- `E:/fair-constitution-app/App Docs/CGA_Mockup_Build_Instructions.md` (392 lines, production-guidance sections)

---

## A) The 17 Institutions and their Dependency Chain

### Institution catalog (Roles Chart, Sheet 2)

| ID | Institution | Created by | Creation method | Prerequisites |
|---|---|---|---|---|
| **Foundational** ||||
| I-JUR | Jurisdiction | System / Individuals | Preloaded from GIS data; new ones via union formation or self-organization (Art. VI §3) | None — foundational |
| I-IND | Individual Registry | System | Automatic — core system module | None — foundational |
| **Electoral** ||||
| I-ELB | Election Board | Legislature (R-09+) | Election Board Creation Act → supermajority vote → member appointment | I-LEG exists (bootstrap: system acts as first board) |
| I-ELE | Election | Election Board (R-08) / system auto-trigger | Election Scheduling Order (or term-expiration auto-trigger) | I-ELB + I-JUR |
| **Legislative** ||||
| I-LEG | Legislature | Election results (auto) | Constituted when winners certified and seated | I-ELE concluded + I-JUR |
| I-SPK | Office of the Speaker | Legislature | Speaker Nomination → supermajority RCV at first session | I-LEG seated |
| I-COM | Committee | Legislature | Committee Creation Act → supermajority → scope/seat count → assignment process | I-LEG; I-SPK should exist to administer |
| I-ADM | Administrative Office | Legislature | Administrative Office Creation Act → legislative vote | I-LEG |
| I-SUB | Legislature Subdivision | Election Board | Subdivision Boundary Drawing → board draws → legislative approval | I-LEG exceeds max seats (9) + I-ELB |
| **Executive** ||||
| I-EXC | Executive Committee | Legislature | Executive Committee Delegation Act → supermajority → define scope/departments | I-LEG |
| I-EEO | Elected Executive Office | Legislature | Executive Office Creation Act → supermajority (+ constituent-jurisdiction supermajority if applicable) → define type committee/individual | I-LEG; I-EXC may exist first (conversion path) |
| I-DEP | Executive Department | Legislature | Department Creation Act → vote → charter + oversight assignment | I-LEG; I-EXC or I-EEO should exist for oversight |
| I-BOG | Board of Governors (Govt) | Executive (R-14/15/16) | Governor Nomination → legislative consent vote | I-DEP + filled executive role |
| **Judicial** ||||
| I-JUD | Judiciary (Appointed) | Legislature | Judiciary Creation Act → supermajority → define jurisdiction/scope → nomination process | I-LEG |
| I-JDE | Judiciary (Elected) | Legislature | Judiciary Conversion Act → supermajority (+ constituent-jurisdiction supermajority) → judicial election | I-LEG; I-JUD may exist first (conversion) |
| **Organizational** ||||
| I-ORG | Private Organization | Individual (R-23) | Organization Registration — type, structure, jurisdiction of registration | I-JUR + registrant jurisdictionally associated (R-03) |
| I-CGC | Common Good Corporation | Legislature | CGC Creation Act → vote → charter + executive oversight | I-LEG; I-EXC or I-EEO should exist for oversight |

### Dependency chain (Roles Chart, Sheet 4 — what must exist before what)

**Phase 0–1 Foundation & Identity:**
- 0.1 System + Jurisdictions (I-JUR) ← nothing (GIS boundary import)
- 0.2 Constitutional Engine (hardened rules + amendable settings defaults) ← nothing
- 1.1 Individual Registry (I-IND) ← system
- 1.2 Residency Tracking ← R-01 Individual (Residency Declaration → GPS pings)
- 1.3 Verified Resident (R-02) → Jurisdictionally Associated (R-03) ← ping threshold met inside boundary
- 1.4 Voter (R-04) ← automatic upon R-03, **no separate form**

**Phase 2 Elections:**
- 2.1 Election Board — **BOOTSTRAP NOTE: for the very first election the system acts as the election board; the first legislature then creates a proper board**
- 2.2 Election scheduled ← I-ELB (or bootstrap)
- 2.3 Candidates (R-06) ← election + open registration + R-03
- 2.4 Organizations can endorse ← I-ORG (creatable independently at any time by any R-03)
- 2.5 Ballots cast ← voting period + R-04 voters
- 2.6 Results certified → winners become R-09 Legislative Representatives

**Phase 3 Legislature Operations (ordering):**
3.1 Legislature constituted (oaths) → 3.2 Speaker elected (supermajority RCV) → 3.3 Rules of Order + Ethics Code → 3.4 Administrative Office → 3.5 proper Election Board (replaces bootstrap) → 3.6 Committees (supermajority) → 3.7 Committee members assigned (rank-preference) → 3.8 Chairs + alternates (RCV) → 3.9 bills can pass → 3.10 referendums (supermajority) → 3.11 emergency powers (supermajority + disaster/invasion)

**Phase 4 Executive & Organizations:**
4.1 Executive Committee (supermajority) → 4.2 members selected proportionally from legislature → 4.3 optional Elected Executive Office conversion (supermajority + constituent-jurisdiction supermajority if applicable) → 4.4 elected officers + advisors → 4.5 Departments ← I-LEG + exec branch → 4.6 Boards of Governors ← department + exec nominates + legislature consents → 4.7 private orgs register freely (available from Phase 1) → 4.8 CGCs ← I-LEG + exec oversight → 4.9 co-determination auto-triggers at 100+ workers → 4.10 public↔private conversion capability

**Phase 5 Judiciary & Law:**
5.1 Appointed Judiciary (supermajority) → 5.2 Judges nominated by constituent jurisdictions + legislature confirms → 5.3 Advocates register → 5.4 cases filed/heard → 5.5 juries empaneled → 5.6 constitutional challenges → 5.7 optional Elected Judiciary conversion (dual supermajority) → 5.8 petition constitutional review

**Phase 6 Federation:**
6.1 peer discovery → 6.2 authority claims negotiated → 6.3 cross-instance identity (Full Faith & Credit) → 6.4 union formation → 6.5 border settlement → 6.6 Art. VI restoration mode (system detects compromised constitutional order → automatic election triggers)

---

## B) The 30-Step Bootstrap Sequence (Roles Chart, Sheet 6 — verbatim-ish)

**STAGE 1: SYSTEM GENESIS**
1. Deploy CGA instance — Docker Compose stack; Constitutional Engine initialized with hardened rules + default amendable settings.
2. Load jurisdictional boundaries — import all known jurisdictions from GIS into PostGIS; assign nesting hierarchy (local → regional → national → Earth).
3. System acts as bootstrap election board — absent a legislature, the system triggers the first election cycle using constitutional defaults (STV/Droop, 5-year terms, 5–9 seats).

**STAGE 2: POPULATION ONBOARDING**
4. Individuals register — accounts + GPS residency pinging begins (F-IND-001/003/005).
5. Residencies verified — after threshold days of consistent pinging within a boundary, residency verified and **all enclosing jurisdictions associated** (F-IND-006, auto).
6. Critical population reached — once enough verified residents exist in a jurisdiction, the first election triggers (F-ELB-001, auto).

**STAGE 3: FIRST ELECTION**
7. Candidacy registration opens — any jurisdictionally associated individual may register; organizations (if any) endorse (F-IND-011, F-CAN-001).
8. Voting period — all R-03 individuals cast ranked-choice ballots (F-IND-007).
9. Results certified — STV/Droop tabulation runs; winners granted R-09 (F-ELB-004, auto).

**STAGE 4: LEGISLATURE CONSTITUTES**
10. Members seated (oath, F-LEG-001).
11. Speaker elected — supermajority RCV at first session (F-LEG-008).
12. Rules & Ethics adopted (F-LEG-032/033).
13. Admin Office created (F-LEG-013).
14. Election Board properly created — replaces the bootstrap board (F-LEG-012 → appointments). *(Depends on step 12.)*
15. Committees created — supermajority (F-LEG-009).
16. Committee preferences submitted — ranked preferences, modified multi-faction method (F-LEG-010).
17. Members assigned to committees — Speaker runs the assignment algorithm (F-SPK-005).
18. Committee chairs elected — whole legislature, RCV per chair (F-LEG-011).
19. **Legislature fully operational** — bills introduce → committee → floor → law.

**STAGE 5: EXECUTIVE BRANCH**
20. Executive Committee created (default path) — supermajority delegation (F-LEG-014).
21. Executive departments created — Chief Exec, Treasury, Defense, State, Justice, others (F-LEG-016 ×N).
22. Boards of Governors appointed — executive nominates, legislature consents (F-EXE-001 → F-LEG-020 ×N).
23. (Optional) convert to elected executive — anytime after step 20 (F-LEG-015).

**STAGE 6: JUDICIARY**
24. Appointed judiciary created — supermajority (F-LEG-017). *(Depends on step 19, not 23.)*
25. Judges nominated and confirmed — constituent jurisdictions nominate; legislature confirms (F-LEG-021 ×N).
26. Advocates register (F-IND-015).
27. Courts operational — cases filed, heard, decided.
28. (Optional) convert to elected judiciary — anytime after step 24 (F-LEG-018).

**STAGE 7: FULL GOVERNANCE**
29. Complete government stack active — Legislature + Executive + Judiciary operational; constitutional-challenge workflow functional; orgs register; co-determination scales (depends on steps 19 + 22 + 27).
30. Federation enabled — instance can discover and sync with peers.

---

## C) Architecture Plan — Modules, Phasing, Instantiation Model, Federation, Identity/Auth, Queue/Clock

### C.1 Module map (Architecture Plan §3.2 — 10 modules, each an independent Laravel package communicating via events/service contracts)

| Module | Constitutional basis | Core responsibilities |
|---|---|---|
| Identity | Art. I; Art. V §1 | Registration, profile, residency via GPS pinging, jurisdictional association, identity-verification bridge |
| Jurisdictions | Art. V (all) | Geospatial boundaries, nesting hierarchy, joint/reserved powers registry, Full Faith & Credit sync, union formation, disintermediation, border settlement |
| Elections | Art. II §2, §4–5; Art. III §3; Art. IV §3 | PR-STV/Droop engine, candidate/org registration, automatic scheduling, countback, special elections, election-board management, subdivision boundary drawing |
| Legislature | Art. II (all) | Sessions, speaker election, committee system (modified multi-faction), bill lifecycle, referendums, petitions, emergency powers, quorum tracking, public records |
| Executive | Art. III §2–5 | Committee + elected executive types, department creation, Board of Governors appointments, oversight, CGC management |
| Judiciary | Art. IV (all) | Court creation (appointed/elected), case management, jury paneling, advocate registration, constitutional-challenge workflow (Art. IV §5), law amendment by judiciary |
| Organizations | Art. III §5–6 | Entity registration (private/public/worker/member-owned), ownership management, public-private conversion, co-determination scaling, board elections |
| Legislation | Art. II §2; Art. IV §5; Art. VII | Law text + version control, scale/scope definitions, constitutional-settings changes, amendment tracking, case-law linkage |
| Federation | Art. V §2, §7; Art. VI | Instance discovery, authority resolution, sync, conflict resolution, bootstrapping mechanics, cross-instance communication |
| Constitutional Engine | Art. VII | Hardened-rule enforcement, amendable-variable management, validation of ALL state transitions, audit logging. "The single most important module" — middleware validating every state-changing action before persist |

### C.2 Phasing (76 weeks, 7 phases — each a deployable increment; sequenced by institutional dependency)

| Phase | Weeks | Milestone | Institution deliverables |
|---|---|---|---|
| 0 Foundation | 1–6 | Constitutional Engine + skeleton | Engine (hardened rules, amendable settings, validation middleware, **audit logging with cryptographic chaining**), all migrations, GIS boundary seed, Sanctum auth |
| 1 Identity & Jurisdictions | 7–14 | Register + establish residency | GPS pinging (geofencing, not constant polling), residency verification, **automatic association to ALL enclosing jurisdictions via point-in-polygon**, jurisdiction browser, settings panel **locked to constitutional defaults until a legislature exists** |
| 2 Elections Engine | 15–24 | First automated STV election | STV/Droop with **Gregory fractional surplus transfers**, any candidates × 5–9 seats; candidate registration (any associated individual); org endorsements; auto-scheduling (5-yr default); secret-ballot **commitment scheme** (receipt hash verifiable against anonymized published hashes); queued tabulation job with round-by-round transparency; **countback engine** (re-run prior results minus vacating member); special-election trigger (90–180 d); **single-winner RCV only for individual executive**; election-board management |
| 3 Legislature Ops | 25–36 | Full session operational | Auto-seating of winners; term expiration tracking; speaker (supermajority RCV); committees: allocation = Total Reps ÷ (Committees × seats per committee), ranked preferences, 1st-choice-performance tiebreak, **multi-faction AND factionless members fully supported — proportionality at the individual level**; bill lifecycle; quorum vs all serving members; 90-day meeting enforcement; public records auto-publication; referendums + petitions (threshold + constitutional-court review); emergency powers (declare/renew/90-day max/judicial review/auto-expire); vacancy detection → countback/special election; **subdivision when seats > 9** with boundary tools + election-board oversight |
| 4 Executive & Orgs | 37–48 | Exec + org management live | Delegated exec committee (proportional from legislature); conversion to elected (supermajority + constituent-jurisdiction supermajority); models: (a) committee 5+ PR-STV equal power, (b) individual RCV + top-4 advisors; departments (Chief Exec, Treasury, Defense, State, Justice, custom) with charter/oversight/BoG; BoG: exec nominates, legislature consents, politically neutral, 10-yr terms; org registration (all ownership types); CGC creation + executive oversight; public↔private conversion (mutual agreement OR monopoly action: supermajority, fair-market compensation, board-transition option); co-determination: first worker seat at 100, **linear scaling** to parity at 2000, joint chair by full board |
| 5 Judiciary & Law | 49–60 | Challenge process end-to-end | Appointed courts (supermajority; constituent jurisdictions nominate **equal numbers**; judicial-committee fallback for leaf jurisdictions); elected conversion (dual supermajority, judges elected in groups via STV); **term synchronization: judicial terms match legislative/executive**; panels min 3, odd, severity-scaled, full court for major constitutional questions; juries (random draw from associated individuals); advocates; Art. IV §5 workflow (challenge → finding → recommended remedy → legislature window to amend or supermajority-override → else judiciary edits the law text directly); laws with full version control + **scale** (which judiciary level) + **scope** (which jurisdictions bound); constitutional settings formally linked to enacting laws; case-law opinions as non-binding commentary |
| 6 Federation | 61–76 | Multi-instance sync | ActivityPub + custom extensions; authority claims + resolution (population consensus, encompassing-jurisdiction mediation); **authoritative instance = source of truth, eventually consistent**; cross-instance identity (Full Faith & Credit); union formation (constitution compatibility diff, variable codification, new encompassing jurisdiction); disintermediation; border settlement; **Art. VI bootstrapping**: detect compromised order → constituents call elections → encompassing steps in → individuals self-organize; UTC everywhere + display conversion; full i18n |

### C.3 Per-jurisdiction instantiation model — IMPORTANT divergence to flag

- **Architecture Plan (authoritative doc)**: `legislatures` = "Legislature instances **per jurisdiction** with term dates and status". Multi-tenant-by-jurisdiction: each jurisdiction's data logically partitioned in one PostgreSQL instance; when a jurisdiction spins up its own authoritative instance, migration tools export its partition. Executive offices, courts, settings are all per-jurisdiction. Constitutionally (Art. V §3), any jurisdiction containing constituent jurisdictions gets a **bicameral** legislature.
- **Current repo reality (supersedes for implementation)**: there is **one Earth legislature** (~1999 type_a seats, ~274 districts via cube-root sizing); sub-national jurisdictions do **not** have their own legislatures — "scope" is a **view/filter term, not an institution** (operator feedback, recorded in project memory). The mockup instructions (§2 item 9) confirm: real pre-united Earth geography, synthetic Earth root at adm_level 0, **nations parent directly to Earth, no supranational tier**; union formation remains a workflow with no live instance.
- Mockup §11 item 16 gives the repo-canonical sizing/districting law: `legislature_sizing_law` v1 = `cube_root`: total population-apportioned seats = max(5, round(∛Σ direct-children population)); districts are **composites of direct child jurisdictions** with Webster-rounded seats, each district within 5–9; oversized "giant" children recurse into their own sub-district budgets; bicameral seats = `type_a` (population) + `type_b` (one per constituent child, equal), both kinds must agree independently.

### C.4 Federation architecture
- Each instance = full Docker stack able to run the whole stack local→Earth; local jurisdictions later deploy authoritative instances for themselves.
- `authoritative_server_id NULL` = this server authoritative (repo convention); conflict resolution = authoritative-instance-wins; eventual consistency.
- ActivityPub-based protocol + governance-specific extensions; instance discovery, authority-claim negotiation; cross-instance identity recognition (Full Faith & Credit, Art. V §2); union formation / disintermediation / border settlement workflows; Art. VI restoration protocols.
- UUID PKs everywhere (cross-instance safety); no assumption of single-server authority.

### C.5 Identity/auth model
- Laravel Sanctum API tokens (SPA/mobile) + optional OIDC bridge for jurisdictions with existing identity providers.
- Role-based authorization policies derived from **constitutional roles** (individual, representative, speaker, executive, judge, governor, advocate, election-board member — 30 roles R-01…R-30 in 7 tiers per roles chart).
- Residency = GPS ping pattern (encrypted at rest, retention policies) → threshold days (amendable, default ~30) → verification → **automatic association at every nesting level simultaneously** → voting + candidacy unlock automatically with **no other requirements** (Art. I absolute rights).
- Ballot secrecy: commitment scheme — voter receipt hash verifiable against published anonymized ballot hashes; system can verify *that* a voter voted (double-vote prevention) without linking ballot↔voter.

### C.6 Queue/clock infrastructure
- Redis + Laravel Horizon for: election tallying, vote processing, sync operations, **scheduled election triggers** (elections fire from the system clock, never from official discretion — "no election can be skipped or delayed by officials — hardened").
- Laravel Reverb (Pusher protocol) for live vote counts, session streaming, notifications.
- A **CLK-01…CLK-21 clock registry** exists in the workflows catalog (Sheet 4): named clocks include CLK-03 emergency-power max (90 d), CLK-04 special-election window (90–180 d), CLK-05 residency ping threshold, CLK-06 critical population, CLK-09 civil-officer term (10 yr), CLK-11 judicial veto window, CLK-12 remedy timeframe, CLK-13 first worker seat (100), CLK-14 parity (2000), CLK-15 min judges per race (5), CLK-16 panel rules, CLK-17 petition threshold, CLK-18 inter-election candidacy registration (open the whole period), CLK-19 referendum-act protection (same-term shield, converts to ordinary law after next general election), CLK-21 finalist cutoff X = f(seats). The mockup `shared/clocks.html` "doubles as the scheduler spec for the dev team."
- All timestamps UTC internally; display-layer conversion.

### C.7 Audit chain & constitutional engine
- **Append-only audit log with cryptographic chaining** — every state change (vote cast, bill passed, appointment, law edit) recorded; tamper-evident; Phase 0 deliverable.
- Constitutional Engine = validation middleware on every state-changing action (e.g., bill cannot pass without peg quorum; emergency power cannot exceed 90 days; voting-method change cannot reduce proportionality).
- **Constitutional test suite = executable constitutional law**; CI rejects any change failing it. Two layers: Hardened (STV algorithm, supermajority calc, rights enforcement, bicameral dual-agreement, proportionality guarantees) vs Flexible (reads `jurisdiction_settings` / `constitutional_settings`; each setting carries key, current value, hardened min/max, enacting act, effective date).

### C.8 Production-implementation guidance from CGA_Mockup_Build_Instructions.md (canonical resolutions that bind the production build)
1. **Factions → organizations** (repo supersedes constitution's mechanism): universal `organizations` table (`political_party|business|nonprofit|common_good_corp|informal`) + polymorphic `endorsements`; any org **or individual** endorses any candidate; no faction registration anywhere; members without endorsements first-class. The constitution's four faction touchpoints (Art. II §2 ×2, Art. II §4, Art. III §2) are satisfied at the individual-preference level / via endorsing-org observer standing.
2. **Committee tie-break (repo evolution of Art. II §4)**: ties go to the seat holder with the **largest vote share after normalizing quotas** (raw 1st-choice counts aren't comparable across STV transfers) — cite "Art. II §4 · as implemented".
3. **Two-phase open-ballot elections** (repo elaboration of Art. II §2): continuous approval phase opens the moment the prior election certifies; finalist cutoff top X = f(seats); ranked window; **write-in of any validated candidate always allowed**; PR-STV/Droop with Gregory fractional transfers fills ALL seats per district in one count; single-winner RCV only for individual executive.
4. **Geography is real, pre-united**: Earth synthetic root adm_level 0; geoBoundaries ADM0→level 1 (national), ADM1→2, ADM2→3, OSM 4/5+; slugs `{iso3}-{adm_level}-{name}`; cosmic-address prefix (Multiverse→…→Solar System→Earth); provenance per jurisdiction (`source`, population year, `is_civic_active`).
5. **Hardened vs amendable visually/architecturally**: hardened mechanics locked + protected by test suite; amendable settings show current value, allowed range, enacting act.
6. Form-ID drift resolutions (roles chart canonical): F-CHR not F-COM; F-BOG not F-GOV; F-LEG-022…036 match by **name** not number; R-21 Advocate / R-22 Juror; 80 workflows (CIV 8, ELE 10, LEG 20, EXE 9, JUD 9, ORG 10, JUR 9, SYS 5).
7. Handoff: `manifest.json` per screen maps roles/workflows/forms/entities/clocks → `suggestedVuePage` (e.g., `resources/js/Pages/Elections/OpenBallot.vue`); flow pages structured "deliberately the structure the dev project can lift into orchestration/state-machine classes."
8. Mechanics that must be exact (mockup §11): peg quorum denominator = all serving members; bicameral dual agreement at committee AND floor; emergency powers as first order of business; Art. IV §5 three paths; term lockstep; monopoly acquisition fair-market floor; referendum-act shielding; judiciary panel rules; joint vs reserved powers; residency→rights automatic.

---

## D) Constitutional Hard Constraints per Institution

### Per-Article requirements summary

**Art. I — Individuals (24 enumerated rights).** Equal treatment; autonomy; expression; religion; **fair representation**; privacy/security; no compelled speech (except truthful court testimony); no self-incrimination; supremacy of rights over any law/contract; fair public trial by impartial tribunal; competent advocates + timely hearings + presumption of innocence beyond reasonable doubt; no torture; access to information; **right to vote in any election/referendum regardless of any characteristic except jurisdictional association**; **right to stand for any elected office or civil appointment, same sole qualifier**; assembly/association; economic freedom; movement of persons and of capital/goods between jurisdictions; equal access to Common Good services; right to reside in any jurisdiction (subject to its laws); freedom to contract; petition/protest; right to secure rights.

**Art. II — Legislatures.**
- §1: All powers granted by all individuals associated with the jurisdiction; faithfully represent constituent will while preserving rights.
- §2 Basic duties: rules of order + ethics code; **independent politically neutral administrative office** (procedures, ethics, misconduct investigation); guarantee right to vote/stand; election security + secret ballot + **public chains of custody with faction (→ endorsing-org) observation/audit**; voting accessibility (time + resources); population records for apportionment; terms/intervals — **elections every 5 years default**; **independent election boards** drawing subdivision boundaries "equally, contiguously, fairly" — **max 9 members before a legislature/subdivision must split**; **proportional voting — STV with Droop quota default**; **peg quorum: majority (or supermajority where stated) of ALL serving members** to pass anything; **meet at least every 90 days**, may compel attendance; publish public records of statements/bills/votes with explanations; daily session order: quorum count → outstanding emergency powers → constitutional matters → general business.
- §3 Speaker: politically neutral; elected by supermajority at first post-election meeting; serves until next legislature; replaceable by supermajority anytime; **votes only to break ties**; ensures committee assignments fair; judges all impeachment/censure/expulsion **except their own**.
- §4 Committees: created by **supermajority**; factional makeup mirrors whole legislature (→ implemented faction-independently, see C.8); seats distributed evenly within faction; assignment by ranked preference ballot, ties by prior-election 1st-choice performance then subsequent ranks (→ normalized-quota share in repo); chairs + alternates by whole-legislature RCV; vacancy/new-committee fills by whole-legislature RCV preserving proportion.
- §5 Vacancies: **countback first** (re-run prior results as if vacating member never ran); failure → **special election 90–180 days** after vacancy.
- §6 Referendums: supermajority to delegate authority to referendum; **petition initiative at threshold % of jurisdiction population** (repo default 5.00%) → next jurisdiction-wide ballot, invalidated by constitutional-court finding or signature audit; population thresholds mirror legislative ones (majority↔majority, supermajority↔supermajority); legislature may modify/repeal referendum acts same term by supermajority **unless population passed it by supermajority**; after next general election referendum acts become ordinary law.
- §7 Emergency powers: invoke by **supermajority**, only for **natural disaster or actual invasion**; must define expected duration (≤ max), area (≤ jurisdictional authority), enforcement methods (within constitutional order); renewal by supermajority, each ≤ max; **max duration 90 days default**; **cannot disrupt legislative/judicial/electoral or any civic process**; subject to judicial review.
- §8 Forbidden actions: no subdivision into separate voter pools unless seats exceed the max (then population subdivided into **contiguous, equal groupings**, uniform rep:population ratio); no interference with anyone's civic obligations (including by official inaction); **no retroactive acts, no bills of attainder**; arrest only by court warrant stating reason + max hold duration; no servitude/property in persons; no double jeopardy (criminal); judgments overturned only for proven contradictions/errors; **no taxes/fees/costs to exercise civic rights**.
- §9 Governments: legislatures create Chief Executive, Treasury, Defense, State, Justice (+ Judiciaries), and other necessary departments; **civil appointments 10 years default**.

**Art. III — Governments/Executives/Organizations.**
- §1: authority extended from legislature; powers granted by residents.
- §2 Executive Committees: supermajority delegation; composed of legislators in the same factional proportions/manner as legislative committees.
- §3 Elected Executives: supermajority delegation; elected by entire population **same manner and same term length as legislators**; **constituent-jurisdiction supermajority required to create/alter any executive office** where constituents exist; **individual model: top 4 runners-up serve as alternates/advisors**; **committee model: at least 5 elected representatives**; executives have same rights/duties as legislators incl. removal by supermajority.
- §4 Boards of Governors: executives appoint experts **with legislative consent**; politically neutral; term = civil-officer term (10 yr); executives have full equal investigative/administrative power over their departments, may propose policy changes and call for governor removal in good faith.
- §5 Common Good Corporations: created by legislature, overseen by exec committee/office; **regulated identically to private enterprises**; legislature may purchase monopolistic or for-sale private enterprises; **shareholders paid at least fair market price**; prior board offered founding-governor seats; legislature may reorganize/dissolve/sell CGCs; **all CGC IP universally and eternally public domain**.
- §6 Work Councils: at minimum employee threshold, ≥1 worker-elected governor; **scales uniformly to parity**; **thresholds: 100 employees (first seat), 2000 (parity)** defaults; chair elected jointly by entire board; applies to executive departments, CGCs, AND private enterprises.

**Art. IV — Judiciaries.**
- §1: independent, dispassionate; powers from residents; created by legislatures.
- §2 Appointed (default): supermajority delegation; politically neutral; **single fixed term**; constituent jurisdictions each nominate an **equal number** of judges; leaf jurisdictions: judicial-committee nomination (by supermajority delegation).
- §3 Elected (conversion): supermajority delegation **+ supermajority of constituent jurisdictions**; judges elected **in groups**, by entire population, **same manner and term length as legislators**.
- §4 Composition: judges have legislators' rights/duties incl. supermajority removal; **panels ≥3, odd, severity-scaled; full court for major constitutional questions**; jury of peers + zealous competent advocates guaranteed; **minimum 5 judges per single race** default; **judicial appointments 10 years** default.
- §5 Resolving questions of law: any inhabitant may challenge a law; judiciary finds contradiction → informs legislature + recommends remedy + timeframe; legislature modifies/removes in that timeframe OR **supermajority overrules within the judicial veto window**; if neither, **judiciary applies its own remedy directly to the law text**; executives uphold the outcome.

**Art. V — Jurisdictions / Bicameral / Powers / Union.**
- §1–2: co-equal self-governing containers; manage Common Good; uniform application of law across constituents; share indivisible/flowing resources jointly; resolve disputes via legislative/judicial process; **Full Faith and Credit** to all associated jurisdictions' acts/records/proceedings; maintain defensive forces; **no unilateral boundary changes** — mutual agreement + **supermajority of population in the affected area**.
- §3 Bicameral: required **whenever a jurisdiction contains constituent jurisdictions**; seats apportioned by relative population (type A) + equally per constituent (type B); both kinds elected by whole population, same way, same time; committees mirror the chamber-kind ratio; **both kinds must independently agree** for anything to pass committee or become law; otherwise one body; **min 5 / max 9 seats per jurisdiction or subdivision**.
- §4 Joint powers (all jurisdictions): uniform admin structures (constituents keep local appointments); taxes/fees; borrowing; mobilize defense; buy land/capital at fair market value; **≥1 official language, cannot disallow constituents' languages nor deny individuals any language**; enact laws; punish crimes; **time-limited exclusive IP rights** to promote science/arts; resolve constitutional interpretation questions.
- §5 Reserved powers (most encompassing only): **currency + measurement standards** (most encompassing of all); extra-jurisdictional trade oversight; mass-destruction weaponry; military coordination protocols; military resourcing **≤ one legislative term**; **declare war by legislative supermajority**; set uniform **age of consent/majority (default 18)** — but voting/standing for office can never be age-denied; encompassing constitutions take priority on conflict.
- §6 Cultural Institutions of State: supermajority recognition (+ constituent-jurisdiction supermajority); **no legislative/executive/judicial powers**.
- §7 Union: 2+ independent jurisdictions align institutions to compatibility → codify amendable variables → ratify; joining: align with union constitution, admitted per entrance/exit clauses; **supermajority of applicant's individuals + supermajority of union's constituent jurisdictions** to join or leave (default).
- §8 Disintermediation: **all** constituents + the encompassing jurisdiction agree → intermediary dissolves, acts incorporated into former constituents.

**Art. VI — Constitutional Order.** Activation conditions: government countermanding the constitution; fair government captured/disabled for an extended period; destroyed by war/disaster. Restoration ladder: (1) constituent jurisdictions elect a new legislature ASAP; (2) encompassing jurisdiction calls elections; (3) individuals self-organize new jurisdictions per the constitution. Defensive forces protect individuals + the most legitimate government (identified by fidelity to the constitution).

**Art. VII — Ratification.** Fully entrenched; amendable only by what minimally violates consent, uniformly balances interests, effectively governs. **Additional articles: supermajority of constituent jurisdictions (or of the legislature if no constituents)**. **Voting method can never be replaced by anything less proportional. Supermajority can never be defined below majority; majority never below ½ + 1. Default supermajority = 2/3** (repo formula: `ceil(serving_members * 2/3)` of ALL serving members).

### Hard-constraints table per institution

| Institution | Hard constraint | Value | Source |
|---|---|---|---|
| I-LEG Legislature | Voting method | STV/Droop (only more-proportional replacements ever allowed) | Art. II §2; Art. VII |
| I-LEG | Seats per legislature/subdivision | min 5, max 9 (mandatory subdivision above 9, contiguous + equal) | Art. II §2, §8; Art. V §3 |
| I-LEG | Quorum/pass threshold | majority (or supermajority) of **all serving** members | Art. II §2 |
| I-LEG | Supermajority | 2/3 of all serving = ceil(n·2/3); floor = majority+1 | Art. VII |
| I-LEG | Meeting interval | ≤ 90 days | Art. II §2 |
| I-LEG | Election interval | 5 years default (amendable) | Art. II §2 |
| I-LEG | Session order | quorum → emergency powers → constitutional matters → business | Art. II §2 |
| I-LEG | Forbidden | retroactive acts, attainder, civic-rights fees, voter-pool subdivision below 9-seat trigger | Art. II §8 |
| I-LEG (bicameral) | Dual agreement | type A + type B must independently agree, committee AND floor | Art. V §3 |
| I-SPK Speaker | Election/removal; voting | supermajority RCV; replaceable by supermajority; **tie-break vote only**; judges removals except own | Art. II §3 |
| I-COM Committee | Creation; proportionality; chairs | supermajority; mirrors legislature makeup (→ individual-level in repo); chairs/alternates by whole-house RCV | Art. II §4 |
| I-ELB Election Board | Independence; boundaries | independent; subdivisions equal/contiguous/fair; transparent public process; bootstrap board = system, temporary | Art. II §2; Sheet 4 note |
| I-ELE Election | Vacancy fill | countback first; special election 90–180 days on failure | Art. II §5 |
| I-ELE | Ballot secrecy | cryptographic separation voter↔ballot; observable chain of custody | Art. II §2 |
| I-ELE | Eligibility | jurisdictional association is the ONLY requirement to vote or stand | Art. I |
| I-EXC Exec Committee | Creation; composition | supermajority delegation; proportional from legislators, committee method | Art. III §2 |
| I-EEO Elected Executive | Creation/alteration; models; terms | supermajority + constituent-jurisdiction supermajority; committee ≥5 via PR-STV equal power, OR individual via RCV + top-4 advisors; term = legislative term; removable by supermajority | Art. III §3 |
| I-DEP Departments | Mandatory set | Chief Executive, Treasury, Defense, State, Justice (+Judiciary), others as needed | Art. II §9 |
| I-BOG Board of Governors | Appointment; term | exec nominates + legislature consents; politically neutral; **10-year civil term** (lockstep with judicial) | Art. III §4; Art. II §9 |
| I-JUD Judiciary (appointed) | Default type; creation; nomination; term | appointed is default; supermajority; constituent jurisdictions nominate equal numbers (judicial-committee fallback); single fixed **10-year** term | Art. IV §1–2, §4 |
| I-JDE Judiciary (elected) | Conversion; race size; term | legislature supermajority + constituent-jurisdiction supermajority; **min 5 judges per race**, elected in groups via STV; term = legislative | Art. IV §3–4 |
| I-JUD/I-JDE | Panels | ≥3 judges, odd, severity-scaled; full court for major constitutional questions; jury + advocate rights | Art. IV §4 |
| I-JUD/I-JDE | Art. IV §5 power | judiciary edits law text directly if legislature neither amends nor overrides (supermajority) within window | Art. IV §5 |
| I-ORG Organization | Worker representation | first worker-elected governor at **100** employees; uniform scaling; parity at **2000**; joint chair by full board | Art. III §6 |
| I-ORG | Monopoly acquisition | legislative action; shareholders ≥ fair market price; board offered founding-governor seats | Art. III §5 |
| I-CGC Common Good Corp | Regulation; IP | identical regulation to private peers; **IP universally + eternally public domain** | Art. III §5 |
| I-JUR Jurisdiction | Boundaries; powers; bicameral trigger | no unilateral changes (mutual + affected-population supermajority); joint powers all / reserved powers most-encompassing only; constituents ⇒ bicameral; Full Faith & Credit | Art. V §2–5 |
| I-JUR | Union / disintermediation | dual supermajority to join/leave union; unanimity of constituents + encompassing consent to dissolve intermediary | Art. V §7–8 |
| I-SUB Subdivision | Trigger; shape | seats > 9; contiguous, equal population groupings; uniform rep:population ratio; election-board drawn | Art. II §2, §8 |
| I-ADM Admin Office | Independence | politically neutral; procedures + ethics + misconduct investigation | Art. II §2 |
| (cross) Emergency powers | Bounds | supermajority; disaster/invasion only; ≤90 days incl. each renewal; cannot disrupt any civic process; judicial review; auto-expire | Art. II §7 |
| (cross) Term lockstep | Synchronization | legislative + elected executive + elected judicial terms expire together; elections cannot be skipped/delayed by officials | Art. III §3; Art. IV §3; mockup §11.9 |
| (cross) Petition initiative | Threshold | % of jurisdiction population (repo default 5.00%); constitutional-court review; signature audit | Art. II §6 |
| (cross) Age | Consent/majority | 18 default (most-encompassing sets it); never applicable to voting/candidacy | Art. V §5 |

Key file paths for the architecture plan that consumes this: `E:/fair-constitution-app/App Docs/CGA_Architecture_Plan.docx`, `E:/fair-constitution-app/App Docs/CGA_Constitutional_Roles_Forms_Chart.xlsx` (also sheets "3. Forms Catalog" ~110 forms, "7. Special Vote Types" — not dumped here), `E:/fair-constitution-app/App Docs/CGA_Workflows_Catalog.xlsx` (80 workflows + entity state machines + CLK registry — referenced by the mockup doc as the primary workflow spec), `E:/fair-constitution-app/App Docs/CGA_Mockup_Build_Instructions.md`, `E:/fair-constitution-app/.claude/worktrees/practical-payne-17d537/docs/extracted/fair_constitution.md`.