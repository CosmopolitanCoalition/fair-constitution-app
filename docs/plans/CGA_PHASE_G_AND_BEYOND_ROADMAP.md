# CGA — Phase G and Beyond: Roadmap & Architecture Charter

**Status:** Charter / roadmap synthesis · 2026-06-14
**Scope:** Phases **G → O** — the federation-adoption mesh now in flight, plus the eight
explored phases that follow it.
**Supersedes for forward planning:** `CGA_Architecture_Plan.docx` (which charters Phases 0–5 /
institutions A–F). That plan remains authoritative for everything **already built**; this
document is the architecture plan for everything **still to build**.

**Sources synthesized:**
- `docs/plans/institutions/PHASE_G_{MASTER,IMPLEMENTATION}_PLAN.md` (committed `25d3b2e`) — the
  in-progress phase, charter + 708-line build manual.
- Seven independent exploration design docs (`docs/plans/explorations/*.md`, authored in parallel
  2026-06-14), one per future domain.

> This is a **charter**: vision, the canonical phase sequence, the dependency graph, the
> cross-cutting invariants, and the recommended resume point. Each future phase still gets its own
> **design round → implementation plan → build** the way A–G did. Nothing below is built yet; the
> exploration docs are the seed material.

---

## 1. Where we are

| Phase | Domain | State |
|---|---|---|
| **A (P0)** | Foundation — Docker, Laravel+Vue+Inertia, constitutional migrations, ConstitutionalEngine + hash-chained `audit_log`, clocks/scheduler, activation, design system | ✅ COMPLETE |
| **B (P2)** | Elections — PROTECTED `VoteCountingService` (PR-STV/Droop/Gregory, RCV, countback), ballot secrecy, lifecycle | ✅ COMPLETE |
| **C (P3)** | Legislature — chamber votes, bicameral dual-agreement, speaker, committees, bills→versioned laws, referendums, petitions, emergency powers | ✅ COMPLETE |
| **D (P4)** | Executive & Organizations — delegation/conversion, departments+BoG, executive orders, org module, co-determination, board elections, CGC IP register | ✅ COMPLETE |
| **E (P5)** | Judiciary & Law — appointed/elected courts, cases/panels/juries/advocates, the Art. IV §5 three-path challenge → direct judicial law-editing | ✅ COMPLETE |
| **F (P6a)** | Federation core + 4 jurisdiction processes + i18n machinery (chrome, 5 locales) | ✅ COMPLETE — `main @ 299dcee` |
| **G (P6b)** | Federated adoption, earned autonomy, the social mesh | 🔨 **IN PROGRESS** — branch, cold-sync gate done (`60d9383`), G1 next |
| **H–O** | The eight explored phases below | 📋 Explored, not started |

The constitutional test suite is green with **zero skips**; the 103-form ConstitutionalEngine, the
PROTECTED hardened layer, and the hash-chained audit log span every built phase.

---

## 2. Unifying principles (what every future phase inherits)

The seven explorations were written independently, yet **converged on the same five disciplines**.
These are the spine of everything H→O:

1. **[HARDENED] vs [POLICY] tagging.** The Template is *silent* on most of what follows (economics,
   UBI, achievements, forums, demo data, official languages, accessibility, the districting metric).
   Every silent item is **[POLICY]** — authored by an instance's founders (setup wizard, owner
   ruling #16 precedent) or its legislature, amendable, lives in the **flexible layer**, must show
   its enacting act, and is **never promoted into the hardened layer**. Only items *derived from
   immutable text* are **[HARDENED]**. No future phase adds a new immutable rule.

2. **Additive-only.** Protected migrations (`jurisdictions`, `ballots`, `audit_log`) are **never
   edited**. New tables only; existing tables evolve by nullable columns + backfill. Enum widenings
   use the established drop-and-re-add CHECK technique.

3. **The privacy actor-split** (the single most-repeated pattern). Two ledgers:
   - **public** — hash-chained, FF&C-synced (government money, public records, budgets, issuance,
     reach aggregates, board-election *results*, public square posts, public-domain corpus).
   - **private-local** — **never globally chained, NEVER federated**, like ballots: raw locations,
     credentials, and now also tax filings, individual balances, market transactions, UBI receipts,
     DMs/private posts, education progress, member PII, follow/block lists, sub-k-anon reach counts.

   The `FORBIDDEN_SUBJECT_TYPES` allowlist in `PublicRecordService` and the four Phase-F export
   filters **grow in nearly every phase**. This is the load-bearing safety mechanism.

4. **Authority ≠ leadership** (from Phase G, and binding on all of H→O). `authoritative_server_id`
   (NULL = us) is the Phase-F authority axis, unchanged. *Which node writes* is a Patroni data-tier
   concern, never PHP consensus. No phase reads cluster/leader state in an authority path.

5. **Reuse the engine, never fork it.** Every phase routes writes through
   `ConstitutionalEngine::file → ConstitutionalValidator → handler-in-transaction →
   AuditService::append`, reuses `VoteCountingService`/`CoDeterminationService`/`SettingsResolver`
   unmodified, and (for generators) drives the real engine statics rather than reimplementing the
   math. Rejected attempts are recorded `rejected=true` with an Article citation.

### Cross-phase contracts (the wiring between future phases)

- **Districting → Demo gate:** the planetary first-draft map (Phase H) is the prerequisite for the
  full-scale demo (Phase J). A demo cannot complete while childless-giant jurisdictions sit at the
  `clampUnassignedLeafGiants` stub.
- **Reach denominator, built once:** `LegitimacyService::reachRatio()` + `legitimacy_snapshots`
  (Phase I) is the *single* canonical metric; the demo (Phase J) and the achievement gauge
  (Phase M) **consume** it, never recompute it.
- **Coalition authorship bridge:** the Cosmopolitan Coalition org (Phase K) is the in-app authoring
  entity; its `authored_by_organization_id` / `ip_register_entry_id` bridge feeds the i18n corpus
  (Phase L) and the education content (Phase M).

---

## 3. Phase G — Federated Adoption, Earned Autonomy & the Social Mesh *(in progress)*

The charter and 708-line build manual are committed (`docs/plans/institutions/PHASE_G_*`,
`25d3b2e`). The two-pronged trust model: **Prong 1** = permissionless read-only mirror of public
records (anyone forks in, authoritative for nothing); **Prong 2** = government-validated read/write
co-membership (earned by population, granted by the jurisdiction's own government); **cohesion** =
peer-SSO via the G-ID attestation layer → infinite fork-ability at the social layer.

- **Track A — volunteer mirror mesh** (Prong 1; FIRST): `cold-sync` ✅ → `G1` mirror model → `G2`
  join-key adoption → `G3` request/vouch → `G3b` wizard → `G0b` deploy script.
- **Track B — earned autonomy + cohesion:** G-ID (parallel) → co-member clusters → write-routing →
  Patroni HA → ballot re-wrap (test-first) → operational seed → the autonomy vote.
- **Track C — reach & clients:** transport (tailnet/Tor/sneakernet) → directory → **mobile** (G10,
  Capacitor + geofenced GPS; the operator's physical OnePlus/travel-router lab rig is on record).

**Done:** the cold-sync gate (`buildAuditTail(limit, capTo)` byte-identical at `limit=0`,
server-capped `/audit-tail`, `ColdSyncService` + `SyncCursor` + cross-page continuity guard).
**Next:** `G1`.

---

## 4. The phase sequence (H → O)

> **Phase-letter reconciliation.** The exploration docs self-assigned colliding letters (Treasury
> claimed *both* "Phase H" and "Phase I"; the others assigned none). The canonical numbering below
> is this charter's; each section notes the doc's original self-label.

### Phase H — Districting Completion & Planetary Map Generation
*Source: `districting-toolkit-recalibration.md` (self-labeled "Phase G-adjacent"). Gates Phase J.*

**Thesis.** The composite autoseed (`DistrictingService::runAutoCompositeForScope`) already districts
any scope **with children** to political-science benchmarks. The one unsolved case is a **childless
leaf giant** (entitlement > ceiling, no children to compose from), today clamped to a single 9-seat
district (`clampUnassignedLeafGiants`, audited `clamped_pending_subdivision_capability`) — gross
under-representation. This phase closes three structural blockers, adds the two raster methods for
the giant case **only**, wires the WorldPop raster substrate into PHP, and runs the planetary
first-draft generation that Phase J materializes on.

**Constitutional grounding.** Art. II §2 (independent boards, split-on-exceed-nine, equal/contiguous/
fair), Art. V §3 (5/9 band, *amendable*), Art. II §8 (uniform rep:population), Art. VII
(proportionality may never be reduced). **SILENT:** the *metric* for "fair" (the doc derives a 5-key
objective, treated as principled like the cube-root law), the population denominator, and the
recalibration process — all [POLICY], election-board/legislative authorship.

**Key schema (additive).** `district_subdivisions` (the geometry home — `geom MultiPolygon`, method
`splitline|manual|composite_synthetic`, recursion tree, status draft/active/archived) · polymorphic
`subdivision_id` on `legislature_district_jurisdictions` (CHECK exactly-one-of jurisdiction/
subdivision) · SQL fn `population_within_multi(geom, year)` (cross-border MAX-per-pixel) ·
`districting_exemplars` + `districting_calibrations` (SOFT params only). Reuses `jurisdiction_maps`
(Phase F `2026_07_02_000001`) + `legislature_district_maps`.

**Key services/forms.** `PopulationRaster` PHP service (the missing `population_within` entry point) ·
`POST /population-probe` (R-08, rate-limited) · `DistrictingMethod` interface (Composite/Splitline/
Manual; future SKATER/Voronoi/graph-cut plug in beside) · **F-ELB-007** Splitline · **F-ELB-008**
Manual Draw · **F-ELB-009** Calibration Adoption · F-ELB-003 reused as draft→active promotion.

**The recalibration loop.** Operator hand-draws exemplars → tune **SOFT** params (grid→Bayesian) →
validate on held-out scopes → adopt via F-ELB-009. **Hard gates (contiguity, resolved band, equality
tolerance) are immutable feasibility filters, never representable in calibration params.**

**Exit criterion.** A childless giant is split by F-ELB-007 into in-band contiguous districts, the
plan is drafted/observed/activated, and the planetary first-draft Standard map exists for all ~951k
jurisdictions with **no** clamp stubs remaining.

---

### Phase I — Activation Tiers & the Reach / Legitimacy Metric
*Sources: `full-scale-demo.md` (the "activation tiers" line) + `achievements-legitimacy.md` (the
"reach/legitimacy" half). "Smallest, safest, ship first." Gates Phase J's denominator.*

**Thesis.** Two tightly-coupled measurement layers that share the WorldPop/CivicPopulation rails:
(a) a **population-pegged activation tier** that gates when a jurisdiction's government may *boot*
(owner ruling #15), and (b) the canonical **reach ratio** = `verified_residents / population_estimate`
— honestly named *reach/enrollment*, **explicitly NOT** the Art. VI §3 legitimacy verdict.

**Constitutional grounding.** Art. II §1 (consent → there must be constituents → a threshold is
*implied*), Art. II §2 (population records; STV-Droop needs a crowd), Art. I (the tier gates *boot*,
never the franchise — rights stay residency-only). **Preamble** grounds reach as *consent-derived
coverage*. **DISCLAIMS Art. VI §3** (that is a wartime allegiance test, not a headcount). **SILENT:**
tiers, the curve, the denominator → [POLICY], founder/legislature-authored, amendable via CLK-06.

**Key schema (additive).** `constitutional_settings` curve params at planet root
(`activation_tier_enabled/k/exponent=3/floor=5/cap` — one amendable row, not 951k thresholds) ·
`legitimacy_snapshots` (tamper-evident audit_log pattern; `ratio_micro`, `population_provenance`,
`suppressed`; **the table Phase J consumes**).

**Key services.** `ActivationTierService::tierThreshold = clamp(ceil(k·pop^⅓), floor, cap)` (cube-root
mirrors Taagepera; `tier(0|null)=floor`) · `LegitimacyService` (`reachRatio()` value object,
`snapshotAll()` nightly, `leaderboard()` jurisdiction-only with k-anon floor) · `SnapshotLegitimacyJob`
(ordinary scheduled job, **outside** the CLK registry). **Zero new F-forms/clocks/audit-modules.**

**Hard rails.** Tier gates boot not franchise (CI-4); reach is **never a governance input** (CI-1);
**k-anonymity on the counts themselves** (a jurisdiction can activate at 1 resident → suppress
sub-k); only the authoritative instance writes a snapshot (CI-6). Dev default of 1 preserved with the
flag off.

**Exit criterion.** A real instance resolves per-jurisdiction tiers through the settings cascade
(dev still = 1), and nightly `legitimacy_snapshots` publish a k-anon-safe reach ratio with
provenance — the denominator Phase J/M reuse.

---

### Phase J — The Full-Scale Demo *(the flagship)*
*Source: `full-scale-demo.md`. Gated on H (map) + I (tiers/reach).*

**Thesis.** Resolve "chartered but empty world" with **two physically separate instances**:
`earth.*` — **the Standard** (joinable multiplayer; every ~951k jurisdiction shows the cube-root
chamber / district map / institution scaffolding it *could* attain, dormant until real consent
activates it via Phase-I tiers); and `earth-demo.*` — **the Attained** (that same standard *broadly
materialized* into a fully-operational ~8-billion-person world, flagged `scale_demo`, **federation
disabled**, ephemeral single-player to every visitor). Physical separation is the cleanest
constitutional answer: a demo has received no consent, so it is an *illustration, never a government*.

**Constitutional grounding.** Preamble (no consent → not a government, CI-1), Art. II/III/IV reproduced
verbatim by the generator. **SILENT:** demo data, the sandbox → [POLICY]. Only the seat arithmetic is
non-policy (reused from the hardened engine).

**Key schema (additive).** `instance_settings.instance_class` (`production|scale_demo`; scale_demo
**forces** `federation_enabled=false`) · `demo_sessions` + `demo_overlays` (per-session copy-on-write
deltas, TTL-evicted) · `demo_generation_runs` (resumable cursor) · reserved synthetic-identity
namespace (`*@demo.invalid`). Materialized governments **reuse** existing institution tables — safe
**only** because the instance is isolated + federation-off + ephemeral.

**Key services.** `DemoPopulateService` (deterministic seed = `hash(jurisdiction_id)+version`, **drives
engine statics**) · `DemoSandboxService` (jurisdiction-grain CoW; read-only demo is the MVP fallback,
the overlay is "the hardest, most novel piece — its own roadmap line"). **Zero new forms/clocks.**

**Hard rails.** CI-2 boot-assertion refuses to serve a `scale_demo` instance with federation on; CI-5
hardened math holds in synthetic data (5–9 seats, Droop-clearing winners, 2/3 supermajority, ≥5
judges) **because the generator runs the engine, not a copy**; PI-2 clamp honesty (no childless-giant
single-district dressed up as fully districted — hence the H gate).

**Exit criterion.** A visitor anywhere on `earth-demo.*` browses their own jurisdiction's fully
materialized government, plays in an ephemeral sandbox where **nothing persists**, and the real
`earth.*` instance carries **zero** synthetic data.

---

### Phase K — The Cosmopolitan Coalition as Organization
*Source: `coalition-as-organization.md` (self-labeled "Exploration ⑥", no letter). Small, additive,
enabling. Ships on the built Phase-D org module.*

**Thesis.** Model the Coalition's real structure into the app so it supersedes the website's bespoke
org features. Two nonprofits — **Cosmopolitan Party Foundation** (legal parent) and **Cosmopolitan
Coalition of United Earth** (operating/authoring child) — both `type='nonprofit'`, linked by the
existing `organizations.parent_organization_id`, registered at Earth (ADM0). Becomes the in-app
**authoring entity** for the i18n corpus (L) and education content (M), behind a strict
civil-society/government firewall (Article-I levers only; zero Leg/Exec/Jud/CGC power).

**Constitutional grounding.** Article I (a nonprofit is a "pure Article-I creature" — assembly,
association, expression), Art. III §6 co-determination if ≥100 contracted workers, Art. V §6 optional
Cultural-Institution recognition. **SILENT:** a nonprofit voluntarily dedicating works to the public
domain → [POLICY] (borrows only the *irreversibility* of CGC public-domain, like CC0). The Template
mandates public-domain only for CGCs (Art. III §5, untouched).

**Key schema (additive, tiny).** `organizations.public_domain_charter` (one-way false→true) ·
`cgc_ip_register.dedication_basis` (`constitutional_mandate|voluntary_charter`) · optional
`org_memberships.is_public` · the **Δ4 bridge contract** (`authored_by_organization_id` /
`authored_by_user_id` / `ip_register_entry_id`) **owned by L/M, not created here**. No delta for the
two-org link.

**Decisions locked.** `type='nonprofit'` settled (not CGC, not informal); app stores **no** US tax
category; `cgc_ip_register` kept (not renamed); CGC Art. III §5 branch byte-for-byte unchanged
(voluntary dedication uses the separate `public_domain_charter` flag).

**Exit criterion.** `institutions:demo-coalition --fresh` seeds both nonprofits at Earth with a
member-elected co-determined board and a public-domain corpus; the firewall pins stay green; the Δ4
contract is handed to L/M.

---

### Phase L — Full i18n, Accessibility & Media
*Source: `i18n-full-scale.md` (no letter). Builds on Phase-F machinery; consumes K's authorship.*

**Thesis.** Phase F shipped the i18n machinery (loader, glossary, pseudo-locale, 5-locale *chrome*);
~90% of *body* copy across 64 pages + ~48 components is still hardcoded English. This phase (1)
extracts every body string into the per-namespace JSON catalog with a CI gate, (2) builds an
invokable Original-Text→All-Languages pipeline scaling to **115 registered locales / 77+ languages**
via a hybrid **local-NLLB + Claude-Haiku** router (replacing the paid media service), (3) brings every
surface to **WCAG 2.2 AA + selected AAA + EN 301 549**, and (4) adds a video→translated-video pipeline
+ multi-track player — all **presentation-only**, never touching the hardened layer.

**Constitutional grounding.** WF-SYS-03 (the *only* explicit translation mandate: public records
publish *with translations* — a backfill into the existing `public_records.translations` jsonb), Art. I
(access to information → accessibility derived as faithful-implementation, **not** a new right).
**SILENT:** no official-language article, no disability article, no RTL mandate → all [POLICY].

**Key schema.** Build-time path = **zero new tables** (catalog files in Git + generated config). New
tables **only** in the *deferred* dynamic layer: `translation_cache` (with the privacy rail
`CHECK (NOT (is_private AND provider LIKE 'cloud-%'))`) + `translation_string_status`.

**Key services.** `scripts/i18n/extract.mjs` + `check.mjs` (AST extractor + CI gate) ·
`scripts/etl/translate_catalog.py` driven by the existing `supervisor.py` · `TranslationProvider`
router (NLLB tail / Haiku tier-1 + sensitive / human for constitutional namespaces) ·
`MultiTrackPlayer.vue` (silent master + per-locale audio/VTT) · the single `languages.py` registry
generating `config/locales.php` + JS registry (**kills the PHP↔JS drift**). **F-SYS-LOC-PUBLISH** +
**F-SYS-TR-REVIEW**. Locales **auto-publish** on zero-error QA; human correction layers on after.

**Hard rails.** Presentation-only (no locale ever alters a hardened computation); the 38 glossary
terms + all ID tokens (R-/WF-/F-/CLK-) byte-identical across every locale incl. pseudo; private
dynamic content translated **only** by a local provider (triple-railed). The top human task: seeding
the 38 constitutional terms per new locale **before** prose MT.

**Exit criterion.** Every page body is extractable and CI-gated; a fresh locale auto-publishes from
clean QA across 77+ languages; axe-core passes in CI; WF-SYS-03 public records carry translations.

---

### Phase M — The Public Square, Civic Education & Achievement Surfaces
*Sources: `education-social-layer.md` (no letter; social-first) + the *achievements* half of
`achievements-legitimacy.md`. Single-instance social rides Phase-A residency; cross-instance Path B
rides Phase G; education rides K's content; the legitimacy gauge rides Phase I.*

**Thesis.** A per-jurisdiction **public square** (open resident discourse) + **halls of governance**
(deliberation tied to bills/referendums/petitions/committees/candidacies), **point-of-use civic
education** + a Learn Area, and the **achievement/legitimacy surfaces** (badges, the jurisdiction
reach gauge, jurisdiction-only leaderboards). Greenfield, riding entirely on built primitives, adding
no new privacy/federation path of its own.

**Constitutional grounding.** Art. I Freedom of Expression → **the public square is a PUBLIC space
that cannot be censored** (this is a *faithful reading, not a choice*); Art. I residency-only
participation; Art. II §2 halls deliberation = mandated append-only public record; Art. III §5 →
Coalition educational content (public-domain) is mirror-able. **SILENT:** the existence/shape of
forums, profiles, reactions, curricula, moderation of *private* spaces → all [POLICY].

**Moderation (the headline constraint).** Public-square content has **exactly four** carve-outs:
judicial order (logged), protecting others' rights, per-user block (private act), content-neutral
anti-spam (behavior/volume, never viewpoint). **Forbidden:** community-guidelines takedowns,
"violates our values," viewpoint shadow-banning, operator/legislative censorship. Private (org/user)
spaces MAY self-moderate.

**Key schema (additive).** `social_profiles` · `social_spaces` (public_square|halls; flat→structured)
· `social_subforums` (auto-bound to governance objects, idempotent) · `social_threads` / `social_posts`
· `social_reactions` / `social_follows` / `social_memberships` (the last three **local-only, never
federate**) · `education_tracks/modules/questions/progress` (`education_progress` **never federates**;
`correct_keys` never serialized to client) · `achievements` (append-only, partial-unique = idempotent
award) · `AchievementCatalog` (a **code registry**, not a table). Settings denominate on **civic
population**, never WorldPop.

**Key services/forms.** **F-SOC-001/002** · **F-EDU-001/002** · `AchievementService` (**zero new
F-forms** — visibility is a personal setting) · auto-bind reconciler · `EvaluateSocialStructureJob` ·
`Ui/LearnMore.vue` (zero new CSS). **The factions→polymorphic correction** is a named work item
(Coalition STV teaching materials predate open approval; keep factions as a labeled teaching device +
add a how-endorsements-actually-work module).

**Hard rails (the iron rule + ⑦'s rails).** No profile choice ever gates a right; pseudonymity
end-to-end (de-anon only by judicial process against the authoritative instance). Achievements:
**no governance advantage** (CI-1, closed sets R-01..R-30 / CLK-01..CLK-21), **no per-person composite
score** (PI-6, social-credit prohibition), **no individual leaderboard / abstention signal**,
participation from the **envelope not the ballot**, `awarded_on` is a coarse DATE.

**Exit criterion.** A resident discusses a live bill in their jurisdiction's halls (published to the
append-only record), completes a Learn module (server-graded, progress never federated), and sees
their jurisdiction's k-anon reach gauge — with **no** moderation primitive capable of censoring the
public square.

---

### Phase N — Public Finance
*Source: `treasury-economics.md` §8 (self-labeled "Phase H — Public Finance"). Builds on the Phase-D
fiscal stub + org module + Phase-F privacy points (all built). Largely independent — re-orderable.*

**Thesis.** The CGA models every governing institution but has no economic lifeblood. This phase adds
the **fiscal layer**: revenue/taxation by law, a budget→appropriation→disbursement cycle, a
double-entry hash-chained **public ledger**, and currency-agnostic monetary facts. It governs *who
decides and how it's recorded*, **never** *what the right monetary policy is*.

**Constitutional grounding.** Art. II §9 (Treasury depts — already modeled), Art. V §4 (taxes/fees/
borrowing), **Art. V §5 (currency RESERVED to the root jurisdiction** — the most load-bearing fiscal
clause), Art. III §4 (BoG financial reports → public-ledger transparency), **Art. II §8 (no paywall on
civic rights** — HARDENED). **SILENT:** the budget process, tax bases/rates, inflation theory, and
*whether transactions are public or private* (DERIVED from Art. II §2 vs Art. I) → [POLICY].

**Key schema (additive).** `treasury_accounts` · `ledger_entries` (append-only, double-entry,
hash-chained, **same** `audit_log_block_mutation()` trigger; `LedgerService` sole writer) ·
`revenue_streams` / `levies` / `tax_filings` (filings PRIVATE, never federated) · `budgets` /
`budget_lines` (**enactment creates the existing `appropriations` rows in the same txn** — the Phase-D
stub becomes the budget's execution substrate) · `borrowings` · `currencies` (issuer must be root) ·
`issuance_events` (append-only mint/burn). New `constitutional_settings` **keys** (not a table) for the
monetary/UBI levers, railed by `SETTING_BOUNDS` + `DUAL_DOOR_KEYS`.

**Key forms.** **F-LEG-037** Revenue · **F-LEG-038** Budget (spawns appropriations) · **F-LEG-039**
Borrowing · **F-LEG-040** Currency [HARDENED scope, root only] · **F-LEG-031** (existing) = the
monetary-lever path · **F-TRE-001..003** (R-18 / Board of Governors). **No new CLK codes** (data +
nightly sweeps, per Phase-D precedent).

**Hard rails.** **No paywall on civic rights** (`NO_FEE_FORMS` pin — attaching a levy to a civic-right
form is rejected pre-commit with an Art. II §8 citation); **currency reserved to root**; **monetary
policy is a legislative lever, not an admin knob** (changes *only* through F-LEG-031, dual-door:
chamber supermajority **and** constituent consent); ledger append-only, Σdebits=Σcredits per currency.

**Exit criterion.** A root legislature enacts a budget funded by an enacted revenue stream, the
Treasury disburses on a verifiable public ledger, and a monetary-policy change succeeds **only** via
an act.

---

### Phase O — Market Economy
*Source: `treasury-economics.md` §8 (self-labeled "Phase I — Market Economy"). Gated on N.*

**Thesis.** The **market layer** riding the existing organizations module: a labor/work board,
a goods marketplace, mutual aid, and a **UBI** that bootstraps participation and serves as a governed
inflation lever — with the entire mechanism tagged **[POLICY]** (the Template is wholly silent on
basic income; the app ships governance + transparency, never a monetary opinion; nothing auto-runs
without an enacting law).

**Constitutional grounding.** Art. I (Economic Freedom, Free Movement of Capital/Goods, Freedom to
Contract, Access to Common Good Services), Art. III §6 (a labor-board hire feeds the existing
co-determination math — no bypass), Art. III §5 (CGCs treated identically, IP stays public-domain).
**SILENT:** the marketplace, labor board, mutual aid, UBI → all [POLICY].

**Key schema (additive).** `economic_accounts` (currency-agnostic; jurisdiction/dept PUBLIC,
user/org PRIVATE) · `market_transactions` (PRIVATE, like ballots) · `work_postings`/`work_applications`
(accept → F-IND-014 → `org_contracts(labor_recurring)`) · `marketplace_listings`/`marketplace_orders` ·
`assistance_requests` (default private) · `ubi_disbursements` (PUBLIC aggregate) + `ubi_receipts`
(PRIVATE per-individual, never federated).

**Key forms.** **F-TRE-004** UBI Run (system-triggered, `systemOnly()`) · **F-IND-018..023**
(tax-filing, work, assistance, marketplace listing/order, funds transfer) · **F-ORG-008** Org Market
Participation. (F-ORG-008 is free — Phase K deferred its claim on that code.)

**Hard rails.** **UBI eligibility = active residency association ONLY** (same absolute-rights gate as
voting — no added condition); individual balances/transactions/receipts are **private-local, never
federated** (`FORBIDDEN_SUBJECT_TYPES` += `market_transaction`, `ubi_receipt`, individual
`economic_account`); no real payment rails/custody (unit of account is abstract). Sybil defense on UBI
leans entirely on the identity module's GPS/residency verification — UBI **raises the
identity-assurance stakes** (ties to Phase G's G-ID).

**Exit criterion.** An individual receives a UBI run (public aggregate + private receipt), trades on
the marketplace, and a labor-board hire auto-triggers co-determination.

---

## 5. Dependency graph & critical path

```
            ┌─────────────────────────────────────────────────────────────┐
            │  Built substrate: Phases A–F (engine, elections, legislature, │
            │  executive/orgs, judiciary, federation core, i18n machinery)  │
            └─────────────────────────────────────────────────────────────┘
                                      │
                              ┌───────┴────────┐
                              ▼                ▼
                   ┌──────────────────┐   (independent of G:
                   │ PHASE G (mesh)   │    can build any time
                   │ Track A→B→C      │    on built substrate)
                   └──────┬───────────┘        │
        ┌────────────────┐│                    │
        │ peer-SSO / G-ID ││                    │
        └───────┬────────┘│                    │
   ┌────────────┘         │                    │
   ▼                      ▼                    ▼
[Phase M Path B]   ┌─────────────┐      ┌──────────────┐    ┌──────────────┐
(cross-instance    │   PHASE H   │      │  PHASE K     │    │  PHASE N     │
 social)           │ districting │      │ coalition org│    │ public       │
                   │ + planetary │      └──────┬───────┘    │ finance      │
                   │ map gen     │             │            └──────┬───────┘
                   └──────┬──────┘             │ authorship         │
                          │                    │ bridge (Δ4)        ▼
                   ┌──────▼──────┐             ├──────────►  ┌──────────────┐
                   │   PHASE I   │             │            │  PHASE O     │
                   │ tiers +     │             ▼            │ market econ  │
                   │ reach metric│        ┌──────────┐      │ (UBI ↔ G-ID) │
                   └──────┬──────┘        │ PHASE L  │      └──────────────┘
                          │  reach        │ i18n +   │
                          │  denominator  │ a11y +   │
                          ▼               │ media    │
                   ┌─────────────┐        └────┬─────┘
                   │   PHASE J   │             │ content translation
                   │ full-scale  │             ▼
                   │ demo        │        ┌──────────┐
                   │ (flagship)  │◄───────│ PHASE M  │ (reach gauge ← I,
                   └─────────────┘ materi-│ square + │  achievement chrome,
                          ▲        alizes │ education │  education ← K)
                          │ live   modules│ + achieve│
                          └───────────────└──────────┘
```

**The one hard critical path:** `G → H → I → J`. Districting (H) gates the demo's map; tiers + reach
(I) gate the demo's denominator; J is the flagship. **Independent / re-orderable** (need only built
substrate): **K** (coalition org — small, do early), **L** (i18n), **N→O** (finance→economy). **M** is
gated by G (Path B), K (education content), and I (reach gauge). The **achievement chrome** (⑦ part 2)
rides M; the **reach metric** (⑦ part 1) is folded into I.

---

## 6. Schema-at-a-glance (new tables by phase)

| Phase | New tables (additive; all UUID PK, `timestampsTz`/`softDeletesTz`, CHECK enums, partial-unique) |
|---|---|
| **H** | `district_subdivisions`, `districting_exemplars`, `districting_calibrations`; SQL fn `population_within_multi`; `+subdivision_id` on `legislature_district_jurisdictions` |
| **I** | `legitimacy_snapshots`; curve-param **keys** in `constitutional_settings` |
| **J** | `demo_sessions`, `demo_overlays`, `demo_generation_runs`; `instance_settings.instance_class` |
| **K** | `organizations.public_domain_charter`, `cgc_ip_register.dedication_basis`, (opt) `org_memberships.is_public`; Δ4 bridge cols (owned by L/M) |
| **L** | build-time = **none**; deferred dynamic layer: `translation_cache`, `translation_string_status` |
| **M** | `social_profiles/spaces/subforums/threads/posts/reactions/follows/memberships`, `education_tracks/modules/questions/progress`, `achievements` |
| **N** | `treasury_accounts`, `ledger_entries`, `revenue_streams`, `levies`, `tax_filings`, `budgets`, `budget_lines`, `borrowings`, `currencies`, `issuance_events`; monetary **keys** in settings |
| **O** | `economic_accounts`, `market_transactions`, `work_postings/applications`, `marketplace_listings/orders`, `assistance_requests`, `ubi_disbursements`, `ubi_receipts` |

`audit_log.module` (app-validated string, no migration) gains: `treasury`, `economy`, `social`,
`education`, `cluster`, `mirror`, … per phase. **`achievements` deliberately does NOT get a module** —
it reuses `records`.

---

## 7. Cross-cutting constitutional & privacy invariants (CI-blocking, every phase)

- **Immutable hard layer — never touched by any phase:** STV/Droop, 5–9 seats (amendable values,
  immutable *band concept*), 2/3-of-all-serving supermajority, absolute equal voting/candidacy rights
  (residency-only), cryptographic ballot secrecy, 10-year appointments, CGC public-domain IP.
- **The protected triad never federates:** ballots, raw location pings, credentials — extended each
  phase (tax filings, market txns, UBI receipts, DMs/private posts, education progress, member PII,
  sub-k-anon reach). `FORBIDDEN_SUBJECT_TYPES` + the four Phase-F export filters are the enforcement.
- **No paywall, no pay-to-win, no social credit:** no fee gates a civic right (N's `NO_FEE_FORMS`); no
  badge/score/balance affects any governance act (M/I's CI-1); no per-person composite score ever
  exists (M's PI-6 non-existence pin).
- **Authority ≠ leadership** (G) holds across all phases; no authority path reads cluster state.
- **Additive-only:** `jurisdictions`, `ballots`, `audit_log` migrations never edited.
- **Generators run the engine** (H/J): hardened math is verified *by reproduction*, not reimplemented.

---

## 8. Verification posture (unchanged from A–G)

- **Per sub-phase:** named constitutional / property / idempotency pins green under the live-pg
  guarded-connection, rolled-back harness (`tests/Concerns/LivePgConnection` + `FederationSyncSupport`).
  A pin that breaks means the *edit* is wrong, not the test.
- **Standing demos:** each phase ships an idempotent `--fresh` seed (`institutions:demo-treasury`,
  `social:demo`, `education:demo`, `institutions:demo-coalition`, the `earth-demo.*` generator) in the
  vein of `institutions:demo-e` / `elections:demo`; `phasesLive` advances.
- **The discipline that gave A–G zero post-hoc bugs:** build backend sequentially with wiring + tests
  in-flight; commit in reviewable batches; fast-forward the branch to `main` only when the operator
  asks.

---

## 9. Recommended resume point

**Resume Phase G at `G1` (the mirror membership model) and finish Phase G.** It is the in-flight
phase, it is the active branch, and it is the *substrate* the rest depends on — Phase M's cross-
instance public square (Path B), the peer-SSO/G-ID identity that Phase O's UBI leans on for sybil
defense, and the whole mirror/co-membership trust model all require G's primitives. Finishing it
honors the A–F discipline of completing one phase before opening the next.

**On deck after G: Phase H (Districting Completion).** It is self-contained, needs nothing from the
explorations, directly unblocks the flagship demo (J), and is the work the operator has already
sharpened three times — the natural first post-G build.

**Independent fillers** that can be pulled forward whenever convenient (they need only the built
A–F substrate): **Phase K** (Coalition org — small, and it unblocks content authorship for L and M),
and **Phase L** (i18n — large but parallelizable, and it makes the eventual public demo readable
worldwide).

---

*This charter is the architecture plan for G→O. Each phase still earns its own design round +
implementation plan before a line of code. The seven exploration docs in
`docs/plans/explorations/` are the authoritative seed material; this document reconciles them into one
sequence, one set of invariants, and one critical path.*
