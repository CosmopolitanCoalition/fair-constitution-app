All data extracted. Here is the complete report.

# CGA Mockup Frontend Data Contracts — Judiciary, Organizations, Jurisdictions, System, Shared

Source: `E:/fair-constitution-app/mockups/` — all screens read in full; flowData JSON extracted from all 33 WF-JUD/ORG/JUR/SYS flow pages; registry data extracted from `assets/js/fixtures.js`; MANIFEST.md and OPEN_QUESTIONS.md read in full.

---

## 1. JUDICIARY MODULE

### 1.1 Per-screen contracts

| Screen | Page id / nav | Roles | Workflows | Forms | Key data consumed (fixtures contract) |
|---|---|---|---|---|---|
| `judiciary-home.html` | `judiciary/judiciary-home` | R-19, R-20 | WF-JUD-01, WF-JUD-02 | F-LEG-017, F-LEG-018, F-LEG-021 | Panel-size-by-severity table; creation act record (vote counts vs `ceil(serving×2/3)`); per-nominee consent-vote table (nominee, nominated-by, vote n-of-serving, outcome, term dates); dual-supermajority conversion meters (legislature 180/272 need 182; constituent counties 40/62 need 42); `world.emergency` banner |
| `case-docket.html` | `judiciary/case-docket` | R-19, R-20, R-21, R-03 | WF-JUD-03 | F-IND-016, F-IND-017, F-ADV-001, F-JDG-001 | `world.cases[]` — fields used: `id, title, kind (Constitutional challenge\|Civil\|Criminal\|Administrative), court, panel, panelGloss, severity, state, filedVia, doubleJeopardy, note`; filing form posts `{kind, claimed_scale (jurisdiction slug from viewer chain), claimed_severity (Minor\|Moderate\|Serious), title, statement}`; Case entity state strip from `entities['Case']` |
| `case-detail.html` | `judiciary/case-detail` | R-19, R-20, R-21, R-22 | WF-JUD-03, WF-JUD-04 | F-IND-017, F-JDG-001, F-ADV-002, F-ADV-003, F-JDG-002, F-JDG-003, F-JDG-009, F-JDG-010 | 10-stage playable lifecycle (see §1.2); stage→entity-state map; panel assignment table (judge, screening result, Seated/Recused); pre-trial motions table (motion, filed-by, ruling+written reasons); evidence docket (exhibit id, description, submitted-by, admissibility); jury draw stats (12+2 from 14,733,408 eligible pool; selection seed published to audit chain) |
| `juror-view.html` | `judiciary/juror-view` | R-22 | WF-JUD-04 | F-JDG-002 | Service status stepper: Summoned → Conflict screening → Empaneled → Trial → Deliberation → Discharged; summons record (drawn date, pool size, report date/place, draw-seed audit-chain link); 5-question conflict questionnaire (relationship, financial interest, prior involvement, formed opinion, related service) → flagged-for-voir-dire vs remain-in-pool; locked deliberation room (access-controlled, unrecorded; verdict recorded) |
| `advocate-console.html` | `judiciary/advocate-console` | R-21 | WF-CIV-07, WF-JUD-03 | F-IND-015, F-ADV-001…004 | Registration status (granted date, granting judiciary, practice-rights scope); "my cases" = `cases.filter(filedVia==='F-ADV-001')`; per-state next-action map (Evidence docket→F-ADV-003 accepted; Deliberation→no filings; Jury selection→challenge motions F-ADV-002 only); filing composer `{type: F-ADV-001..004, case_id, client, summary}` → filing log rows `{seq, form, caseId, text, when, status}` |
| `constitutional-challenge.html` | `judiciary/constitutional-challenge` | R-03, R-09, R-19, R-20 | WF-JUD-05 | F-IND-016, F-JDG-004, F-JDG-005, F-JDG-006, F-LEG-035 | `world.challenge` = `{name, law, judge, finding, remedy, timeframeDays (60, CLK-12), vetoWindowDays (30, CLK-11), vetoCloses}`; three resolution path cards (A amend / B override / C judiciary edits) with override meter (4→6 of 9, ceil(9×2/3)); law-diff render (`del`/`ins` on law text — PATH C edits text directly, version history preserved); empty state when `scenario.challenge` false |

### 1.2 Judiciary case lifecycle (docket → advocates → jurors → ruling)

**Case entity state machine (fixtures `entities['Case']`):**
`Filed → Validated → Panel-Assigned (≥3, odd, severity-scaled) → [Jury-Empaneled] → Scheduled → Hearing → Deliberation → Decided → Opinion-Published → [Appealed] → Closed`

**10-stage UI lifecycle (case-detail), with stage→state mapping and forms:**
1. **Filing** — claimed scale + claimed severity (F-IND-017 self/prosecution; F-IND-016 constitutional; F-ADV-001 via advocate) → `Filed`
2. **Justiciability & severity classification** — court confirms justiciable, fixes scale, reclassifies severity (claimed values are inputs not outcomes); jury entitlement attaches to criminal accusations → `Validated`
3. **Panel assignment** — F-JDG-001; ≥3 judges, always odd, severity-scaled; full court (all 5) for major constitutional questions (CLK-16, hardened); conflict screening per candidate judge (personal/financial/prior-involvement); conflicted judges excluded, draw re-runs; screening results attach to case record → `Panel-Assigned`
4. **Initial hearing** — scheduling, advocate appearances (right to representation, Art. I), pre-trial motions (F-ADV-002) ruled with written reasons on public record → `Scheduled`
5. **Evidence docket** — exhibits (F-ADV-003) with admissibility rulings (Admitted/Excluded + reason), witness lists; versioned evidence record → `Scheduled`
6. **Jury selection** — F-JDG-002 order; random draw from all jurisdictionally-associated residents; **selection seed published to audit chain**; voir dire removes conflicts only (never opinions/demographics/politics); 12 jurors + 2 alternates; civic-obligation protections (no employer interference, no fees — Art. II §8) → `[Jury-Empaneled]`
7. **Arguments** — openings/examination/cross/closings, all transcribed to public record live; nothing argued in open court is sealed retroactively → `Hearing`
8. **Chambers & deliberation** — two access-controlled rooms (judges' chambers; separate jury room with zero contact); jury deliberation is the only unrecorded space → `Deliberation`
9. **Judgement** — F-JDG-009 sentencing order, F-JDG-010 warrant (stated reason + duration); **double-jeopardy flag machine-enforced at future filing time** for criminal outcomes (Art. II §8) → `Decided`
10. **Opinion** — F-JDG-003 published, linked as *commentary* to every law interpreted (only Art. IV §5 changes text); appeal re-enters at wider panel → `Opinion-Published`

**Constitutional challenge sub-lifecycle (WF-JUD-05, entity `Constitutional Challenge`):**
`Filed → Heard (full court if major) → Finding+Remedy-Issued → Legislative-Window-Open → Amended-by-Legislature | Overridden (supermajority in veto window) | Judiciary-Applies-Remedy → Law-Edited → Closed`
- Any inhabitant files (F-IND-016) — no standing gatekeeper beyond jurisdictional association
- Finding (F-JDG-004) + remedy recommendation (F-JDG-005) + two per-case clocks: remedy timeframe (CLK-12) and veto window (CLK-11), both set by the judiciary per finding
- Finding lands on legislature as **mandatory session priority** (precedes general agenda, WF-LEG-05)
- PATH A: legislature amends via ordinary bill flow (WF-LEG-06) within CLK-12
- PATH B: F-LEG-035 supermajority override within CLK-11 — law stands; finding, vote, every member's position on public record
- PATH C: window closes with neither → F-JDG-006, judiciary edits the law's text directly; new law version published, prior version retained; adjustable settings updated if needed
- All paths: executives enforce final state (WF-EXE-07)

### 1.3 WF-JUD flow catalog (all 9)

| Flow | Trigger | Steps / branches | Terminal |
|---|---|---|---|
| **WF-JUD-01** Appointed Judiciary Creation & Confirmation (Art. IV §2,§4) | Supermajority Judiciary Creation Act | 1. R-09 F-LEG-017 (jurisdiction+scope) → I-JUD created · 2. constituent jurisdictions each nominate EQUAL numbers (judicial committee fallback if none) · 3. F-LEG-021 consent votes — BRANCH confirmed→R-19 seated 10yr / rejected→constituent renominates · 4. court operational; advocate registration + case filing open | Court with defined scope; 10-yr default terms |
| **WF-JUD-02** Conversion to Elected (Art. IV §3) | Legislature supermajority + constituent jurisdictions supermajority | 1. F-LEG-018 act · 2. constituent supermajority — BRANCH fails→remains appointed / passes→I-JDE · 3. judicial elections scheduled synced to terms, ≥5 judges/race (WF-ELE-09) | STV groups ≥5; terms synced |
| **WF-JUD-03** Case Lifecycle (Art. IV §4) | F-IND-016/017 filing | 10 steps mirroring §1.2; step 2 BRANCH dismissed-with-reasons / accepted; engines: Case engine, Panel engine (hardened CLK-16), Hearing session tooling, Evidence docket, Deliberation rooms, Decision engine | Published opinion; double-jeopardy flag on criminal outcomes |
| **WF-JUD-04** Jury Paneling (Art. IV §4; II §8) | Case entitles accused to jury | 1. random draw from jurisdictionally associated (audit-logged) · 2. voir dire filters (conflicts, capacity) · 3. Constitutional Engine blocks employer/other interference, no fees | Jury of peers seated; service protected |
| **WF-JUD-05** Constitutional Challenge & Law Remedy (Art. IV §5) | Any inhabitant files | 7 steps: file (F-IND-016) → hear (full court if major) BRANCH no-contradiction→law stands / contradiction→finding → F-JDG-004 finding+remedy+CLK-12+CLK-11 → PATH A (WF-LEG-06) / PATH B (F-LEG-035) / PATH C (Law-edit engine) → R-14 executives enforce (WF-EXE-07) | Law amended, overridden, or judicially edited |
| **WF-JUD-06** Emergency Powers Judicial Review (Art. II §7; IV §5) | Challenge or automatic review | 1. expedited docket (F-LEG-024 record) · 2. hardened test: real disaster/invasion? duration/area/methods in limits? civic processes undisturbed? — BRANCH upheld / narrowed (scope rewritten) / struck (terminate immediately) | Powers upheld/narrowed/struck |
| **WF-JUD-07** Judicial Vacancy (Art. II §5; IV §2–3) | Judge seat vacated | BRANCH by court type: appointed → re-run WF-JUD-01 steps 2–3; elected → countback → special election (WF-ELE-03/04) | Seat refilled |
| **WF-JUD-08** Judge Removal (Art. IV §4) | Misconduct/competence proceeding | Same duties/removal parity as legislators (WF-LEG-17 machinery); F-LEG-022 vote — BRANCH removed→WF-JUD-07 / retained→closed | Supermajority removal; vacancy flow triggered |
| **WF-JUD-09** Petition Constitutionality Review (Art. II §6) | Petition reaches signature threshold | I-ELB refers to constitutional court (from WF-CIV-06) → finding — BRANCH constitutional→ballot / unconstitutional→invalidated with published opinion | Petition validated or invalidated |

### 1.4 Judiciary forms contract (from fixtures registry)

| Form | Name | Available to | Creates/Modifies | Prereq |
|---|---|---|---|---|
| F-JDG-001 | Case Acceptance / Panel Assignment | R-19/R-20 | Modifies Case (panel, schedule) | F-IND-016/017 filed |
| F-JDG-002 | Jury Selection Order | R-19/R-20 | Jury panel record → R-22 | Case requires jury |
| F-JDG-003 | Opinion / Ruling Filing | R-19/R-20 | Opinion record linked to case + laws | Deliberation complete |
| F-JDG-004 | Constitutional Finding | R-19/R-20 | Finding record → opens legislative window, notifies legislature | F-IND-016 case + contradiction found |
| F-JDG-005 | Remedy Recommendation | R-19/R-20 | Appends remedy to finding | F-JDG-004 |
| F-JDG-006 | Judicial Remedy Application | R-19/R-20 | **Modifies Law record directly (new version)** | window expired w/o action or override |
| F-JDG-007 | Emergency Powers Review | R-19/R-20 | Review opinion | F-LEG-024 declared |
| F-JDG-008 | Petition Constitutional Review | R-19/R-20 | Petition record constitutional/unconstitutional | threshold + referral |
| F-JDG-009 | Sentencing Order | R-19/R-20 | Sentencing record | verdict |
| F-JDG-010 | Warrant Issuance | R-19/R-20 | Warrant record (stated reason + duration) | probable cause |
| F-ADV-001…004 | Case filing / Motion / Evidence / Brief | R-21 | Case / Motion / Evidence / Brief records | active case (001: client retainer) |
| F-IND-015 | Advocate Registration | R-03 | Advocate record (R-21) | qualifications per jurisdiction law + judiciary exists |
| F-IND-016 | Constitutional Challenge Filing (catalog alias F-IND-013) | R-03 | Challenge case record | judiciary + law exist |
| F-IND-017 | Civil/Criminal Case Filing | R-03 (or via R-21) | Case record | judiciary exists |

---

## 2. ORGANIZATIONS MODULE

### 2.1 Per-screen contracts

| Screen | Page id | Roles | Workflows | Forms | Key data |
|---|---|---|---|---|---|
| `org-registry.html` | `organizations/org-registry` | R-03, R-23 | WF-ORG-01 | F-IND-012, F-ORG-001 | `world.organizations[]` fields: `id, name, type (political_party\|business\|nonprofit\|common_good_corp\|informal), ownership/structure, workers, endorsementCount, flags (monopoly_target)`; registered-in jurisdiction map; stats (total, endorsing, in co-det scaling ≥100, CGC count); registration form `{name, structure (stock\|partnership\|equal_partnership\|member_owned\|worker_owned\|nonprofit), jurisdiction, purpose}` — CGCs explicitly NOT self-registered (legislature creates via F-LEG-019); co-det cell logic: `workers≥2000 parity / ≥100 scaling / else below threshold` |
| `org-detail.html` | `organizations/org-detail` | R-23, R-24, R-06, R-07 | WF-ORG-02, WF-ORG-03 | F-ORG-001, F-CAN-002, F-ORG-002, F-IND-013, F-IND-014 | Profile (members/shareholders, workers R-25, endorsements granted, type, structure, registered jurisdiction, charter, agent R-23); endorsement two-form handshake: F-CAN-002 request (R-06) → F-ORG-002 grant (R-23) → candidate gains R-07; endorsement graph feeds proportionality (no faction layer); join forms (F-IND-013 member/shareholder/partner → R-24; F-IND-014 worker → R-25, feeds headcount); **document packages** (charter/HR/comp policy/custom forms — self-managed versions above the constitutional floor, can never override constitutional forms); **contracts** (terms → co-sign both parties → recorded + audit-chained; recurring labor contracts count toward worker headcount) |
| `cgc-detail.html` | `organizations/cgc-detail` | R-09, R-18, R-25, R-27 | WF-ORG-08, WF-ORG-09, WF-ORG-04 | F-LEG-019 | Charter (purpose, chartering act + effective date); executive oversight assignment (department Board of Governors receives periodic reports; **regulated identically to private peers — hardened**); co-det state with formula `worker_seats = max(1, round((W−100) ÷ 1900 × owner_seats))` (1450 workers, 7 governors → 5 worker seats); **public-domain IP register** (asset, kind, published date, status always public-domain — hardened, irreversible); reorganization/sale/dissolution only by legislature (F-LEG-027) |
| `board-elections.html` | `organizations/board-elections` | R-23…R-28 | WF-ORG-05, WF-ORG-04 | F-ORG-003, F-ORG-004 | Three counts seat one board: **owner track** STV (1,204 shareholder ballots, 12 candidates, 9 seats, Droop quota floor(1204/10)+1=121, Gregory fractional transfers); **worker track** STV (692 of 740 ballots, 5 candidates, 3 seats, quota 174); **joint chair** RCV by entire board (12 ballots, majority 7 of full board; round-by-round elimination + transfer) — hardened; any composition change re-triggers joint chair election |
| `co-determination.html` | `organizations/co-determination` | R-25, R-27, R-23 | WF-ORG-04, WF-ORG-05 | F-ORG-004 | Interactive scaling meter; canonical formula `workerSeats(w, ownerSeats) = w<100 ? 0 : max(1, min(ownerSeats, round((w−100)/(2000−100)×ownerSeats)))`; next-step projection `ceil((seats+0.5)/ownerSeats×1900+100)`; CLK-13 (100, amendable, must stay below parity) and CLK-14 (2000, amendable, must stay above minimum); **applies-equally table**: private enterprises (shareholder-elected owner side), CGCs (appointed governors), executive departments (appointed governors) — `world.departments[]` carries `workers, governors` |
| `transfers-conversions.html` | `organizations/transfers-conversions` | R-23, R-24, R-09 | WF-ORG-06, 07, 09, 10 | F-ORG-005, F-LEG-026, F-ORG-006, F-LEG-027, F-ORG-007 | Four ownership paths: **mutual transfer** (F-ORG-005, both consents required on record — engine rejects anything less; syncs full-faith-and-credit); **monopoly acquisition** (F-LEG-026; 5-stage lifecycle: legislative finding of monopolistic control → acquisition vote at **ordinary majority of all serving** → compensation ≥ fair market (hardened floor; engine blocks underpayment) → conversion to CGC → founding governor seats offered to prior board); **public↔private conversion** (F-ORG-006 request; F-LEG-027 CGC reorganize/dissolve/sell — public-domain IP irreversibly stays public); **internal restructuring** (private-side structure changes by owner consent per current structure's own rules, e.g., partnership unanimity; no legislature involved; structure history preserved); **dissolution** (F-ORG-007 voluntary or judicial WF-ORG-10; obligations settled, records archived) |

### 2.2 Organization lifecycle

**Entity state machine:** `Registered → Active → [Endorsing] → [Co-determination tiers] → [Transfer-Pending → Transferred] → [Converted Public↔Private] → Dissolved`

- **Registration (WF-ORG-01):** R-03 files F-IND-012 `{type: stock|partnership|equal_partnership|member_owned|worker_owned|nonprofit, structure, jurisdiction}` → Org engine validates registrant association + type rules → Active; appears in public registry; eligible to endorse, hire, hold ownership; grants registrant R-23.
- **Endorsement (WF-ORG-02):** during open registration window; per org's internal decision rules; grant creates polymorphic endorsement record, confers R-07; endorsement linkage replaces factions in proportionality + committee math (ledger #q1).
- **Membership / worker joining (WF-ORG-03):** F-IND-013 (member/shareholder/partner → R-24) or F-IND-014 (worker → R-25); org accepts per bylaws; headcount update → BRANCH threshold crossed → WF-ORG-04.
- **Co-determination at 100/2000 (WF-ORG-04, CLK-13/CLK-14):** headcount crossing 100, an interpolation step, or 2000 recomputes required worker seats (hardened uniform scale; formula above); applies equally to private orgs, CGCs, executive departments; opens worker-track election; **composition change → joint chair election by entire board; board valid only when composition matches the scale**. Per ledger resolution #12: "scales uniformly" is plain text (not an `as implemented` decision); worker count = everyone signed via F-IND-014 regardless of contract type; owner side = share system; CGC governors stand where shareholders would.
- **Board elections (WF-ORG-05):** owner STV + worker STV + joint chair RCV (full-board majority). Same PR-STV engine as public elections; single-winner RCV only where one seat is filled.
- **Transfers (WF-ORG-06):** mutual consent mandatory; record syncs cross-jurisdiction (WF-JUR-06).
- **Monopoly acquisition (WF-ORG-07):** only path overriding owner consent; legislative finding (ordinary majority of all serving — owner ruling #13); shareholders paid ≥ fair market (hardened); convert to CGC; prior board offered founding Board of Governors seats (decline → WF-EXE-05 analog fill); CGC rules apply (identical regulation; IP → public domain); workforce co-det recheck.
- **CGC creation (WF-ORG-08):** F-LEG-019 act defines charter, goods/services, oversight (executive committee or elected office); board stands up via WF-EXE-05 analog + co-det check; all IP perpetually public domain.
- **CGC reorganization/sale/dissolution (WF-ORG-09):** legislative act — BRANCH reorganize→new charter / dissolve→wind-down / sell→public-to-private conversion; existing public-domain IP status irreversible; new works follow private rules.
- **Private dissolution (WF-ORG-10):** voluntary or judicial order → settle obligations, terminate memberships/contracts, archive records, registry updated, audit chain preserved.

---

## 3. JURISDICTIONS MODULE

### 3.1 Per-screen contracts

| Screen | Page id | Roles | Workflows | Forms | Key data |
|---|---|---|---|---|---|
| `bootstrap.html` | `jurisdictions/bootstrap` | R-01, R-03, R-08 | WF-JUR-01, WF-ELE-02 | — | Jurisdiction state strip with `Bootstrapping` current; critical-population meter (512 of 500 verified residents at trigger; **counts verified residencies R-02, not registrations**); amendable CLK-06 threshold (500 local tier, range 100–10,000, per-tier); **30-step bootstrap sequence from `registry.bootstrap`** grouped into 7 stages: 1 System genesis · 2 Population onboarding · 3 First election · 4 Legislature constitutes · 5 Executive established · 6 Judiciary established · 7 Full governance; each step has `{step, stage, action, details, forms}`; bootstrap election board is temporary (system acts as election board with constitutional defaults until step 14 creates the independent board); activation pegs player population against real population (owner ruling #15) |
| `federation.html` | `jurisdictions/federation` | R-09, R-04 | WF-JUR-05, WF-JUR-06, WF-JUR-08 | — | **Peer instances table** `{host, authoritative-for jurisdiction, peer state (Authoritative/Syncing/Handshake), last heartbeat}`; Federation Peer state machine `Discovered → Handshake → Trust-Established → Syncing → [Conflict-Resolution] → [Border-Settled] → [Merged/Union] | Departed`; **authority claims table** (jurisdiction, claimed-by host, resolution: uncontested / recognized peer claim / handshake in progress / mirrored-until-peer-appears); **sync log** rows `{seq, hash, text, tone}` incl. conflict-resolved (authoritative wins) and write-rejected (non-authoritative write attempt); **3-step authoritative-instance migration**: partition export (signed, source keeps serving reads) → authority flip (co-signed; `authoritative_server` flips per record) → re-peer; **border settlement table**: boundary change passes by supermajority of *affected population* (`ceil(N×2/3)`), ratified settlements re-run point-in-polygon association for affected residents; CLK-20 heartbeat = ops setting, not constitutional |
| `union-formation.html` | `jurisdictions/union-formation` | R-09, R-04 | WF-JUR-02, WF-JUR-03 | F-LEG-029 | Edge-case walkthrough (Earth starts united; hypothetical Aurelia/Meridia); **compatibility analyzer**: diff of amendable variables (`election_interval_months, supermajority, max_days_between_meetings, initiative_petition_threshold_pct, judiciary_is_elected, executive model`) + hardened rows always identical (stv_droop, 5–9, ceil×2/3); **codification workspace**: per divergent variable choose adopt-A / adopt-B / propose-new in founding act; **ratification meters**: supermajority of *each applicant population* (denominator = whole population, never just voters) + supermajority of union constituent jurisdictions (from first join); **bicameral apportionment preview**: `type_a = max(5, round(∛pop))` Webster-split by population, `type_b` = 1 equal seat per constituent; both kinds agree independently (ledger #q3, #q7); join and **exit mirror each other** — same dual supermajorities, no one-way doors |
| `disintermediation.html` | `jurisdictions/disintermediation` | R-09 | WF-JUR-04 | F-LEG-030 | Worked example (dissolve New York state; counties parent to US); **consent meters**: constituent **unanimity** (44/62 passed — one holdout stops it; explicitly NOT supermajority) + encompassing jurisdiction act; constituent consent table `{county, act number, passed/pending}`; **law merge**: 219 intermediary acts — 214 incorporate verbatim, 5 conflicts each need resolution choice `{incorporate (intermediary supersedes) | defer (constituent prevails) | lapse}` recorded in dissolution plan before effect; **open Art. IV §5 challenges travel with merged law**; topology update: parent re-points, every resident's nesting chain re-resolves; documents the F-LEG-030/F-LEG-036 ID-drift note (canonical F-LEG-030 = Disintermediation Vote; catalog reused the ID for vacancy declaration which is canonically F-LEG-036) |
| `restoration.html` | `jurisdictions/restoration` | R-03, R-09 | WF-JUR-07 | — | Three activation conditions: **countermanded / captured-or-disabled / destroyed** (evidence-based detection, judicially reviewable, never unilateral); **3-tier cascade**: Tier 1 constituents elect new legislature; Tier 2 encompassing jurisdiction calls elections; Tier 3 individuals self-organize per the Template (re-enters bootstrap); restoration elections reuse bootstrap election machinery (system-run STV/Droop, defaults); **legitimacy scoring** (minimize consent violations · balance interests uniformly · govern effectively); defensive forces protect the *most legitimate* claimant; functioning elections/sessions/courts cannot be disrupted; drill state from `world.restorationDrill {badge, condition, tier, tierLabel}` behind `scenario.restoration` |
| `jurisdiction-browser.html` | `jurisdictions/jurisdiction-browser` | R-01, R-03 | WF-JUR-09 | — | **Placement contract for the developed `Pages/Jurisdictions/Show.vue`** (dev-slot): left panel = breadcrumb ancestors, natural-level badge (numeric ADM never displays), population (WorldPop year) + live member count, region/dataset provenance, maps-accepted + apportionment-completed timestamps, `authoritative_server: NULL` line, "View Legislature & Districts" CTA, drill-down children; right map = Leaflet stand-in with Names/Population/Members/Raster layer toggles + geoBoundaries/Protomaps/WorldPop attribution; **powers table**: joint (uniform dept/CGC structures, taxes, lawmaking — all jurisdictions co-equally) vs reserved (currency + standards of measure, declaration of war — most-encompassing only, Art. V §4–5) |
| `district-mapper.html` | `jurisdictions/district-mapper` | R-03, R-08 | WF-ELE-06, WF-JUR-09 | F-ELB-003 | **Placement contract for developed `Pages/Legislature/Show.vue`**: root seats + quota (pop/seat) at every scope; Districts/Assigned/Unassigned stats; versioned plans (draft → Activate → active; prior archived; election board observes activation — F-ELB-003, R-08); **MAP QUALITY ledger**: community integrity (intact/segmented), constitutional contiguity, population equality (≤5%/5–10%/>10% + over/under-rep extremes + range), shape compactness (CHR ≥0.70/0.50–0.70/<0.50), uniform political diversity (optimal vs current seat-grouping formulas); per-district quality strips (Dev · CHR · Contig · Intact); member rows `{name, done, seats, pop, rep (fractional), dev, chr, district?, drill?, note}`; members above the 9-seat ceiling subdivide over their own scope with own budgets (Mexico case: 32 seats, 6 sub-districts); prev/up/next wizard row + progress (101/101); Autoseed/Clear; Seats/Pop/Names/Jurs/Stats/Raster map layers |

### 3.2 Jurisdiction lifecycle — backend implied BEYOND the current app

**Jurisdiction entity:** `Boundary-Loaded (dormant) → Critical-Population → Bootstrapping → Self-Governing → [Subdivided] → [In-Union | Intermediary] → [Disintermediated] → [Restoration-Mode (Art. VI)]`

The current app has: jurisdictions table (PostGIS, parent_id, federation fields), constitutional_settings, legislatures + district maps/districts/members, elections scaffold, residency, setup wizard 0–4, jurisdiction viewer, legislature browser, ETL, apportionment, export/restore. **The mockups imply these additional backend capabilities:**

1. **Jurisdiction lifecycle state column + bootstrap engine** — dormancy/critical-population/bootstrapping states; CLK-06 per-tier critical-population thresholds in settings; a 30-step bootstrap sequence runner (stages 1–7) with a temporary system-run election board replaced at step 14; per-step audit records.
2. **Union formation (WF-JUR-02/03, F-LEG-029)** — compatibility analyzer diffing `constitutional_settings` between instances; codification workspace persisting per-variable choices into a founding act; ratification vote machinery with population-denominator supermajorities (applicant individuals) AND constituent-jurisdiction supermajorities; instantiation of a new *encompassing* jurisdiction row with bicameral seat apportionment (`type_a` cube-root/Webster + `type_b` one-per-constituent); join/exit mirrored flows; archived-on-failure states.
3. **Disintermediation (WF-JUR-04, F-LEG-030)** — unanimity tracker across all constituent legislatures + encompassing consent; **law-merge engine**: per-act incorporation into each former constituent's law with conflict surfacing and recorded resolution choices (incorporate/defer/lapse); versioned merges; migration of open Art. IV §5 challenges onto merged law; parent re-pointing + automatic nesting-chain/association re-resolution for residents. (Implies the forward-looking `jurisdiction_maps` versioning noted in CLAUDE.md, plus a laws/acts table with versions — neither exists yet.)
4. **Federation peer mesh (WF-JUR-05/06/08)** — peers table `{host, authority claims, trust state, heartbeat}`; handshake/trust ladder; signed change broadcast + sync validator (valid→applied; conflict→authoritative-instance precedence; tamper→rejected+flagged); `sync_log` appended to audit chain; partition export tooling (signed, checksummed against audit chain) and **authority flip** (co-signed `authoritative_server` transfer per record, zero-downtime re-peer). The export/restore bundle built in setup is explicitly "the federation seed."
5. **Border settlement** — boundary-change proposals with affected-population referendums (`ceil(N×2/3)` of affected residents, not legislatures); on ratification re-run point-in-polygon association for affected residents; rights re-attach automatically.
6. **Restoration mode (WF-JUR-07)** — Art. VI condition monitors (countermanded/captured/destroyed), judicially-reviewable activation, 3-tier election cascade reusing bootstrap machinery, legitimacy-scoring records, full audit publication on exit.
7. **Population records & apportionment (WF-JUR-09)** — continuous census from residency verifications; pre-election apportionment runs; subdivision trigger when seats > max (CLK-07 → WF-ELE-06); records also drive taxes/resources apportionment per acts (published via WF-SYS-03).

### 3.3 WF-JUR flow catalog (all 9) — condensed

(Full step/branch text extracted; key branches:)
- **WF-JUR-01** Bootstrap: critical population (CLK-06) → bootstrap sequence (WF-ELE-02 → WF-LEG-01 → WF-EXE-01 → WF-JUD-01) → federation participation enabled (WF-JUR-06).
- **WF-JUR-02** Union Formation: initiate + exchange configs → align institutions (BRANCH alignment fails → suspended) → codify variables + added articles → ratification per Art. VII (BRANCH ratified → new encompassing jurisdiction, bicameral seats Art. V §3, instances federate / else archived).
- **WF-JUR-03** Join Existing Union: entrance-clause checklist → applicant population supermajority (BRANCH fails → withdrawn) → union constituents supermajority → admitted; associations/apportionment/federation updated; **exit mirrors**.
- **WF-JUR-04** Disintermediation: all constituents agree (unanimity) → encompassing agrees (BRANCH any dissent → no dissolution) → law-merge engine dissolves intermediary, incorporates acts, surfaces conflicts, updates topology.
- **WF-JUR-05** Peer Discovery & Border Settlement: two most-encompassing claimants discover each other → comms/trade channels → boundary negotiation (PostGIS) → BRANCH remain peers / merge (WF-JUR-06/WF-JUR-02).
- **WF-JUR-06** Full Faith & Credit Sync (continuous): authoritative write → signed broadcast → peers validate (BRANCH valid→applied / conflict→authoritative precedence / tamper→rejected+flagged) → sync_log + audit chain.
- **WF-JUR-07** Restoration: detect condition → Tier 1 constituents elect (BRANCH succeeds→restored) → Tier 2 encompassing calls elections (BRANCH succeeds→restored) → Tier 3 individuals self-organize (WF-JUR-01 from scratch) → defensive forces protect most legitimate; mode exits with full audit.
- **WF-JUR-08** Authoritative Instance Migration: stand up local server + request transfer → export logical partition, verify vs audit chain → flip authority; peers re-point; old host becomes mirror.
- **WF-JUR-09** Population Records & Apportionment (cyclical): residency verifications update counts → pre-election apportionment (BRANCH seats>max CLK-07 → WF-ELE-06 subdivision) → records drive taxes/resources apportionment (→ WF-SYS-03).

---

## 4. SYSTEM MODULE

### 4.1 Per-screen contracts

| Screen | Page id | Roles | Workflows | Forms | Key data |
|---|---|---|---|---|---|
| `setup-wizard.html` | `system/setup-wizard` | — | WF-JUR-01 | — | **Placement contract for developed Setup wizard Step0–4.** Step 0 Cosmic address: instance name; fixed chain Multiverse → Observable Universe → Laniakea → Local Group → Milky Way → Orion Arm → Solar System → Earth; time mode real-time (CLK-01, 60mo) vs accelerated; **restore-from-backup**: `.tar.gz`, manifest schema-version validated (older snapshots refused), table picker (20/20), lands on the wizard step matching the bundle's saved progress. Step 1 Constitutional defaults ("defaults of defaults", reference never locks): min/max seats 5/9, sizing law cube-root (ledger #3), max days between meetings 90, interval 60, voting method STV/Droop (more-proportional-only), special election 90–180, supermajority 2/3 (floor majority+1), emergency 90, petition 5%, civil/judicial terms 10/10 lockstep, min judges/race 5, judiciary appointed/elected radio, worker thresholds 100/2000, residency window 30 (CLK-05). Step 2 Map data ETL: source (local archive default / custom folder / URL+upload planned); run options (fresh purge, pause-on-exception, skip Phase 2 population, ISO3 filter); live per-layer progress bars (countries → neighborhoods; population; topological raster fallback rescue); stat tiles (951,636 total rows); review handoff to jurisdiction viewer; Continue triggers apportionment. Step 3 Build districts: apportionment-complete card (1 legislature, 1,999 seats, cube-root/Taagepera); handoff to district mapper (setup-mode banner; "Back to setup" after map activation; `/setup` always returns). Step 4 Confirm & seat: Finish inserts one `executives` row (default committee) + one `judiciaries` row (default appointed, min 5 judges, 10-yr) per legislature-bearing jurisdiction; no members (status *forming* until Phase 2); records `setup_completed_at`; `/setup` redirects home; existing institution rows left alone; **full export** (FK-downstream graph of jurisdictions + settings + rasters, 20–40 min, ~11.3 GB; download link; = federation seed for peer Step 0) |
| `amendments.html` | `system/amendments` | R-09 | WF-SYS-05 | (F-LEG-031 discussed) | **Two doors**: (1) amendable variables via F-LEG-031 ordinary bill flow, engine blocks out-of-range values **pre-vote** (rejection recorded with citation to audit chain); (2) hardened layer only via code release passing full constitutional test suite, diff + test run on public record ("softening exists only through this door"); **supermajority floor**: amendable fraction but hardened floor majority+1 of all serving (`ceil(serving×2/3)` default; vacant seats stay in denominator); **proportionality ratchet**: `voting_method` replaceable only by MORE proportional method — plurality/FPTP rejected; validation playground bounds: `legislature_max_seats 5–9`, `election_interval_months 12–120`, `emergency_powers_max_days 1–90`; ratification thresholds table: setting-within-bounds = valid act; additional articles = supermajority of constituent jurisdictions (or of legislature where no constituents); hardened change = test-suite-passing release publicly recorded |
| `audit-chain.html` | `system/audit-chain` | — | WF-SYS-04 | — | Hash chain `hash(n) = H(hash(n−1) ∥ payload(n))`; ballot/residency identities never written in clear — only commitments; head hash published to peers on federation heartbeat (CLK-20); entry record `{seq, time (UTC stored), module (Elections/Residency/Legislature/Emergency/Judiciary/Settings/Records/Federation/Sessions), event, ref (WF/CLK/form), hash, rejected?, blocked-reason}`; **rejections are appended too** (e.g., `legislature_max_seats 9→12` blocked: exceeds hardened ceiling, Art. II §2); verify action recomputes all link hashes vs published head checkpoint; append-only, nothing removed |
| `public-records.html` | `system/public-records` | R-03, R-09 | WF-SYS-03 | F-LEG-006 | Append-only public record; corrections append superseding entries; every entry sealed into audit chain at commit and replicates on heartbeat; record `{seq, kind (statement\|vote\|act\|opinion\|certification\|other), title, actor, via (form/WF/CLK), date, tr {done, total}}`; translation pipeline: 5 demo locales (en/es/ar/zh-Hans/hi), machine translation publishes immediately, human review upgrades badge per language; statement composer attaches to bill/session/vote/general; record-keeping cannot be suspended under emergency powers; mirrors Bill/Case/Election entity transitions — each published transition = one immutable record entry |
| `term-sync.html` | `system/term-sync` | — | WF-SYS-01 | — | Single clock: legislative term defines `election_interval_months` (CLK-01); elected executive + elected judicial terms equal it (lockstep, CLK-10 structural); scheduler derives every trigger — next election exists from the moment the prior certifies (2030-11-01 → 2035-11-01); appointed officers on separate 10-yr clocks (CLK-09), deliberately decoupled; **engine refusals** (hardened): no skip/delay/reschedule API; no term extension past common expiry even under emergency powers; no executive/judicial drift from legislative term; violations rejected pre-commit + recorded; vacancies never reset the clock (countback/special winners serve remainder); jurisdictions activate staggered (player thresholds), lockstep harmonization toward a shared election day is an encompassing-level end-state normalization |

### 4.2 WF-SYS flow catalog (all 5)

- **WF-SYS-01 Term Synchronization Engine** (continuous; Art. III §3; IV §3): every term-bearing role registers in the term registry on creation (single source of truth) → all election triggers derived from the legislative term clock → triggers emitted to WF-ELE-01/08/09 at constitutional lead times; "no election can be skipped or delayed by officials — hardened."
- **WF-SYS-02 90-Day Meeting Enforcement** (Art. II §2): CLK-02 monitor tracks days since last session, warns at configurable lead → Speaker must call session before day 90 (F-SPK-001) — BRANCH called→clock resets / not called→auto-notice + attendance compulsion + I-ADM violation record.
- **WF-SYS-03 Public Records Publication** (continuous; Art. II §2): event hooks in all modules capture every statement/bill/vote/explanation at write time ("nothing is publishable-optional — hardened duty") → publication pipeline + i18n; immutable, searchable, citable.
- **WF-SYS-04 Constitutional Validation & Audit Chain** (continuous; Art. VII; CGA §6.2/§6.4): Constitutional Engine intercepts every state transition pre-commit → checks hardened rules + amendable settings → rejects invalid with constitutional citation ("nothing unconstitutional persists — by construction") → accepted transitions appended to cryptographically chained `audit_log`; backs recounts, investigations, sync validation.
- **WF-SYS-05 Constitutional Amendment** (rare; Art. VII): proposal classified amendable-variable vs hardened-mechanic → Art. VII ratification thresholds incl. constituent approvals (BRANCH fails→archived) → variable path updates `jurisdiction_settings` (WF-LEG-14), live at effective date → hardened path = code change shipped, must pass FULL constitutional test suite in CI; deviation without the amendment-backed release process is rejected.

---

## 5. SHARED

### 5.1 `clocks.html` — the scheduler spec (all 21 clocks)

Page states: "The production scheduler implements exactly these clock records." Amendable defaults read from `constitutional_settings`; hardened/structural clocks fixed in code. Every fire event appends to the audit chain.

| ID | Name | Type | Default | Amendable | Fires | Basis |
|---|---|---|---|---|---|---|
| CLK-01 | General Election Interval | Recurring interval | 5 years | Yes | WF-ELE-01 / WF-LEG-18 | Art. II §2 |
| CLK-02 | Legislature Meeting Deadline | Rolling deadline | 90 days since last session | Yes | WF-SYS-02 → WF-LEG-05 | Art. II §2 |
| CLK-03 | Emergency Powers Max Duration | Countdown | 90 days | Yes | auto-expiry in WF-LEG-11 | Art. II §7 |
| CLK-04 | Special Election Window | Bounded window | 90–180 days after vacancy | Yes | WF-ELE-04 | Art. II §5 |
| CLK-05 | Residency Verification Threshold | Accumulating threshold | N days qualifying pings (setting) | Yes | WF-CIV-02/03 | Art. I; V §1 |
| CLK-06 | Critical Population Threshold | Population threshold | setting per jurisdiction tier | Yes | WF-ELE-02 / WF-JUR-01 | Art. II §1 |
| CLK-07 | Legislature Maximum Size | Structural threshold | 9 members | Yes | WF-ELE-06 subdivision | Art. II §2, §8 |
| CLK-08 | Legislature Minimum Size | Structural floor | 5 members | Yes | seat-count validation (Constitutional Engine) | Art. V §3 |
| CLK-09 | Judicial/Civil Officer Term | Term length | 10 years (appointments) | Yes | WF-JUD-07, WF-EXE-05 renewals | Art. IV §4; III §4 |
| CLK-10 | Term Lockstep | Derived schedule | — | **No (structural)** | WF-SYS-01 → WF-ELE-01/08/09 | Art. III §3; IV §3 |
| CLK-11 | Judicial Veto Window | Bounded window | set by judiciary per finding | Per-case | WF-JUD-05 override deadline | Art. IV §5 |
| CLK-12 | Legislative Remedy Timeframe | Bounded window | "reasonable timeframe" set by judiciary | Per-case | WF-JUD-05 auto-remedy trigger | Art. IV §5 |
| CLK-13 | Co-determination Minimum | Headcount threshold | 100 workers | Yes | WF-ORG-04 first worker seat | Art. III §6 |
| CLK-14 | Co-determination Parity | Headcount threshold | 2,000 workers | Yes | WF-ORG-04 board parity | Art. III §6 |
| CLK-15 | Minimum Judges per Elected Race | Structural floor | 5 | Yes | WF-ELE-09 ballot construction | Art. IV §4 |
| CLK-16 | Case Panel Minimum | Structural floor | 3 judges, odd, severity-scaled | **No (hardened)** | WF-JUD-03 panel assignment | Art. IV §4 |
| CLK-17 | Petition Signature Threshold | Population % threshold | setting per jurisdiction | Yes | WF-CIV-06 audit trigger | Art. II §6 |
| CLK-18 | Approval Phase / Registration Window | Continuous window | opens at prior certification → closes at finalist cutoff | Structural | WF-CIV-08 open/freeze; WF-CIV-05 | Art. II §2; CGA open-ballot spec |
| CLK-19 | Referendum Act Protection | Term-scoped flag | same-term supermajority shield; ordinary law after general election | **No (hardened)** | WF-LEG-19 gate | Art. II §6 |
| CLK-20 | Federation Sync Heartbeat | Recurring interval | implementation setting | Ops setting | WF-JUR-06 | CGA federation model |
| CLK-21 | Finalist Count per Race | Derived formula | top X = f(seats in race), e.g. multiplier × seats (setting) | Yes | finalist cutoff WF-ELE-01 / WF-CIV-08 | CGA open-ballot spec; Art. II §2 |

Scheduler-type semantics (from the page): recurring intervals re-arm on fire; countdowns expire once; bounded windows open and close; thresholds watch a quantity and fire on crossing.

### 5.2 `coverage.html`

QA instrument regenerated on load: axes = workbook-derived registry (30 roles · 80 workflows · 103 forms), fill = `manifest.json` (mirror `manifest.js`, byte-identical). Each build stage must turn its scope green before commit (build order §14); definition of done = QA §15. Final status: 30/30 · 80/80 · 103/103, 142→144 manifest files resolving.

### 5.3 `constitutional-questions.html` — the q-ledger (every entry + resolution)

Maintained append-only ledger; every `· as implemented` citation marker across the mockups anchors here. All 7 entries badge **"Candidate for next draft"** (of *A Fair Constitution* and the workbooks); adopted entries get re-badged, never removed.

| # | Title | Touches | Implemented answer |
|---|---|---|---|
| q1 | Factions → polymorphic endorsements | Art. II §2 (Proportional Voting; Election Security), Art. II §4 Committees (3 clauses), Art. III §2 Exec Committee composition | Faction layer removed entirely. Universal organizations model (`political_party\|business\|nonprofit\|common_good_corp\|informal`) + polymorphic endorsements — any org **or individual** endorses any candidate; unendorsed members first-class; observer/audit standing transfers to endorsing orgs + candidates |
| q2 | Committee tie-break — normalized-quota vote share | Art. II §4 Committee Seat Assignment | Raw 1st-choice counts are incommensurable post-STV-transfer; ties go to seat holder with largest vote share **after normalizing quotas** (preserves proportionality + one-person-one-vote, no faction layer) |
| q3 | Legislature sizing — cube-root law | Art. II §2 (min 5 / max 9 + subdivision) | Constitution silent on total parent-seat count → `legislature_sizing_law` (v1: `cube_root` only): total = max(5, round(∛Σ direct-children population)); ~2,000 seats at Earth scale; future laws reserved |
| q4 | Districting — composites of child jurisdictions | Art. II §2 ("drawn equally, contiguously, fairly"); Art. II §8 Subdivision of Legislatures | Districts are Webster-apportioned composites of direct child jurisdictions within the 5–9 range, built from real jurisdictional lines; children above ceiling subdivide carrying their own budgets; manual line-drawing only where a jurisdiction exceeds the ceiling with no child subdivisions ("the open frontier") |
| q5 | Open-ballot two-phase elections | Art. II §2; Art. I right to vote/stand | Continuous filterable approval phase (opens at prior certification) → finalist line top X = f(seats) (CLK-21) → time-boxed ranked window → one-count PR-STV (Droop, Gregory fractional); write-in of any validated candidate preserves right-to-stand absolutely |
| q6 | Countback without factions | Art. II §5 Countback Procedure | Faction-scoped procedure made meaningless by q1 → countback runs **universally**: re-run prior ballots with vacated member removed, no faction filtering; failure → special election 90–180d (CLK-04) |
| q7 | Bicameral dual agreement — per-kind threshold | Art. V §3 Independent Agreement; Art. II §2 Peg Quorum | Art. V §3 names no per-kind threshold/quorum → each kind meets its own peg quorum and passes independently — majority of ALL serving members of that kind (supermajority where act type requires). "Needs resolving in the constitutional text" |

Entry criteria: a question qualifies when the constitutional text under-determines a mechanism the software must decide and the build picks an answer.

---

## 6. MANIFEST.md — full resolution inventory

**Status:** ALL STAGES COMPLETE (0–7). 62 screens + 80 flow walkthroughs + QA instruments; coverage 30/30 roles · 80/80 workflows · 103/103 forms; 142 (later 144) manifest files resolving; `manifest.js` byte-identical mirror.

**§1 — Catalog ID-drift resolutions (canonical ← catalog alias):** R-21 Advocate / R-22 Juror (swapped in catalog) · F-CHR-001…004 ← F-COM-001…004 · F-BOG-001/002 ← F-GOV-001/002 · F-IND-004 Identity Verification ← F-IND-005 · F-IND-005 GPS Ping ← F-IND-004 · **F-IND-016 Constitutional Challenge ← F-IND-013** (new find; F-IND-013 is canonically Org Membership) · F-LEG-022 Removal/Impeachment ← F-LEG-034 · F-LEG-023 Referendum Delegation ← F-LEG-022 · F-LEG-024 Emergency Declaration ← F-LEG-023 · F-LEG-025 Emergency Renewal ← F-LEG-024 · **F-LEG-036 Vacancy Declaration ← F-LEG-030**. UI rule everywhere: **form name first, ID second**.

**Other §2 resolutions:** 80 workflows is authoritative (catalog Read Me's "63" wrong); no faction layer anywhere (word "faction" only in verbatim constitutional citations); committee tie-break = normalized-quota wording; geography real and pre-united (adm 0–5+, slugs `{iso3}-{adm}-{name}`, no supranational tier). **Citation-dictionary correction:** Art. II §8 is *Legislatures: Forbidden Actions*; juror protections cite its *Non-Interference with Civic Obligations* and *Prohibition of Compulsory Payments* subsections; >9 split cites *Subdivision of Legislatures*; "drawn equally, contiguously, fairly" lives in Art. II §2 *Establish Independent Election Boards*. **Definitive registry counts:** 30 roles · 17 institutions · 103 forms · 80 workflows · 21 clocks · 20 entity state machines · 33 special vote types · 30 bootstrap steps.

**§2 design tokens:** `--adm-0…5` aliases mapped altitude-true onto the six-color ramp; recommendation to rename ramp tokens altitude-neutral.

**§3 architecture (frozen contracts):** no ES modules/no fetch (file:// parity); load order `demo-state → fixtures → manifest → icons → i18n → shell`; page contract = `<main id="main">` + `window.CGA_PAGE {id, title, module, nav, roles, workflows, forms, citation, flow, register}`; demo state `{role, persona, jurisdiction, locale, dir, scenario}` URL-wins; **frozen scenario enum**: `election: approval|ranked|certifying`, `emergency`, `challenge`, `quorumFails`, `bicameral`, `countbackFailed`, `restoration`, `unionDrill`; **frozen flowData contract**: header card · steps `{n, actor, action, form|engine, outcome, screen{href, params}, branches[{label, goto: stepN | {wf, step} | "terminal:STATE"}], entityState?}` · state strip · deep links.

**§4 component inventory** (Vue candidates): AppLayout (exists — reconcile), JurisdictionSwitcher, AdmChip, StatusBadge, Citation, HardenedRule/AmendableSetting, FormChip, OrgChip/PersonaChip, Card, Button, Field, Stat, RegistryTable, SetupStepper (exists), FlowStepper, StateStrip, LifecycleTracker, Banner, ThresholdMeter; plus stage-1–7 additions (`.filter-bar/.chip-toggle`, `.candidate-row/.finalist-line`, `.switch`, `.rank-list`, `.stv-*`, `.receipt`, `.log-row/.log-hash/--rejected`, `.law-diff del/ins`, `.seat-map/.seat-dot`). Demo bar = mockup-only.

**§6:** four flagship pages hand-authored; results page embeds a REAL Gregory STV count (412,383 ballots, quota 41,239, 27 rounds, write-in tabulated identically); 80 flows generated by `tools/gen_flows.py` from catalog Sheet 2 (16 drifted form IDs normalized); 3 flows (WF-CIV-02, WF-ELE-03, WF-JUD-05) use hand-authored `fixtures.flowSamples`; shell fixes (renderChrome no-op pre-DOM, link() passthrough of page-local params, wrap fixes).

**§7 developed-component slots (round peg, round hole):** the three already-built product tools adopt the mockup design system — `jurisdiction-browser.html` ↔ `Pages/Jurisdictions/Show.vue`; `district-mapper.html` ↔ `Pages/Legislature/Show.vue`; `setup-wizard.html` ↔ `Pages/Setup/Step0…Step4`. Data shown mirrors a real dev run (Earth 1,999 seats, quota 3,985,245, Autoseed Attempt 2, ~951k jurisdictions).

**§8 owner review (2026-06-11) applied:** demo world re-centered on New York County/Manhattan (instance `manhattan.cga.example`; US chain honestly ends at county); numeric adm levels never display (natural labels only); "giant"/"leaf" removed from user copy; ledger grew to 7 (#q6, #q7); **unstated vote thresholds = ordinary majority of all serving (peg quorum); supermajority only where stated; governor removal = ordinary majority**; no real recount (audit review reframing); R-17 advisors not a pickable path (sequential-exclusion derivation); activation model = player population pegged against real population, lockstep harmonization as end-state.

**§9 a11y/responsive hardening:** 144/144 pages zero findings at 7 widths + pseudo-locale + RTL; self-hosted fonts (offline/LAN-only, zero external requests); forced-colors + prefers-contrast support; contrast fixes at source; `shared/accessibility.html` statement (WCAG 2.2 AA + selected AAA, EN 301 549). Caveat: contrast walker doesn't read SVG fill; human AT walkthroughs scheduled against production.

---

## 7. OPEN_QUESTIONS.md — every entry and status

All 17 numbered entries **resolved** (JD, 2026-06-11); the file header's "only #12 awaits" is stale within the same file — entry 12's body records its resolution:

1. Honest-gap country choice — **Resolved**: ADM 0–6 ~1M jurisdictions; default chain Earth → US → New York → New York County; San Marino second country; dual-footprint jurisdictions carry both chains, residents belong to both; all jurisdictions viewable by all (physical presence binds conduct, not visibility).
2. F-ELB-007 countback — **Resolved**: countback universal (no faction filtering) → ledger #q6; countback is an engine, not a form.
3. Forms count — **Resolved**: build from chart contents (103).
4. Institutions count — **Resolved**: 17.
5. adm-5 label — **Resolved**: numeric ADM never displays; ETL natural labels (Planet/Country/State/Province/County/Municipality/Township/Neighborhood).
6. `is_civic_active` vs `is_active` — **Resolved**: production-phase DB concern.
7. en-XA placement — **Resolved**: demo-bar QA tool only; production carries stored per-language strings to correct MT error.
8. Bicameral per-kind threshold — **Resolved as implemented; text resolution open by design** → ledger #q7.
9. Learn question counts — **Resolved**: not material.
10. R-17 advisors — **Resolved**: top-4 runners-up by sequential exclusion; informational card; assumable via demo bar for coverage.
11. Leaf/giant demo data — **Resolved**: user copy "exceeds the seat ceiling — subdivides further" / "no child subdivisions — manual line-drawing"; real-lines rule folded into #q4.
12. Co-determination interpolation — **Resolved**: not constitutional; "scales uniformly" read plainly (proportional between 100 and 2,000); `as implemented` hedge removed, cites Art. III §6 directly; worker count = F-IND-014 signups (recurring labor, any org type); owner side = share system; CGC governors stand in for shareholders.
13. Monopoly-acquisition threshold — **Resolved**: ordinary majority of all serving (peg quorum).
14. Governor-removal threshold — **Resolved**: hiring-and-firing, ordinary majority; super/non-super are mechanism-layer switches (later phase).
15. CLK-06 default — **Resolved**: activation pegs active players against real population per jurisdiction; any level can activate first; setup charters final structure, play grows into it.
16. Hardened bounds for amendable settings — **Resolved**: founding values on wizard Step 1; thereafter hardening flows through roles & permissions; amendability "played for"; rule-text bounds stay as rendered.
17. Election sub-states vs frozen scenario enum — **Resolved**: no real recount mechanism — "recount" = audit review (re-run tabulation, re-verify chain); page-local sub-states stay; activation/lockstep context on term-sync page.

**Post-it review backlog (owner, 2026-06-11):**
- **A. Confirmed covered:** individual/org containers; petitions; jurisdiction assignment; voting; standing; office/seat lists; candidates; ownership types; endorsements; residency; jurisdiction list; seat filling; constitutional power settings; bill registration (F-LEG-003); committees; public↔private interchange; exec committees & departments; worker registration; localization; achievements; org officer elections.
- **B. Built this round (mockup surfaces):** profile links; family tree/relationship declarations (proposed); personal settings incl. location manager + default endorsement visibility; grants & appropriations; org document packages; contracts (terms → co-sign → audit-chained; recurring labor feeds worker count); internal ownership restructuring; endorsement webs (public-by-choice); multi-level elections scoped to viewed jurisdiction; circular chamber seniority-alternating seating; per-round STV transfer breakdowns.
- **C. Tabled:** banking system.
- **D. Noted for data-structure phase:** legal-code modification register (git-style law versioning — partially visible via Art. IV §5 diffs); full contracts engine; grant disbursement mechanics.

---

## 8. Cross-cutting backend implications (beyond current app schema)

From these five modules the mockups imply tables/services the app does not yet have:
- **Judiciary:** `judiciaries` (created at setup Step 4 as *forming*), judges + nomination/consent records, cases (kind, claimed/classified scale + severity, justiciability), panels + conflict screenings, motions, evidence (versioned, admissibility), juries (random draw with audit-chained seed, voir dire), verdicts with machine-enforced double-jeopardy flags, sentencing orders, warrants (reason + duration), opinions linked as commentary to laws, constitutional challenges with per-case CLK-11/CLK-12 clocks and the three-path resolution incl. direct judicial law-editing with version history.
- **Organizations:** endorsement request/grant handshake (R-06→R-23→R-07), membership (R-24) and worker (R-25) records feeding headcount, co-determination engine + board composition validity rule, board elections (owner STV, worker STV, joint chair RCV), document packages, co-signed contracts, transfers (mutual-consent gate), monopoly acquisition (fair-market floor), public↔private conversion with irreversible public-domain IP register, dissolution.
- **Jurisdictions:** lifecycle states + bootstrap engine (30 steps, temporary election board), union formation (compatibility diff, codification, dual-supermajority ratification, bicameral apportionment of a new encompassing row), disintermediation (unanimity + encompassing consent, law-merge engine with conflict resolutions, challenge migration), federation peer mesh (authority claims, trust ladder, sync validator, partition export + authority flip), border settlement (affected-population referendum + re-association), restoration (condition monitors, 3-tier cascade, legitimacy scoring).
- **System:** term registry + lockstep scheduler (CLK-01/CLK-10, no-skip API), 90-day session enforcement, public records pipeline (append-only + 5-language translation states), constitutional engine middleware (pre-commit validation, rejections recorded), cryptographically chained `audit_log` with head-hash federation checkpoints, two-door amendment machinery (F-LEG-031 bounds validation pre-vote; hardened path = CI-gated code release), and the 21-clock scheduler whose registry rows are the spec.