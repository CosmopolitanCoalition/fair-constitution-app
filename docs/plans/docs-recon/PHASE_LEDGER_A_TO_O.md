# Phase Ledger A → O — the complete recounting (2026-07-24)

Lane 7 companion to [DELTA_INVENTORY.md](DELTA_INVENTORY.md). One row per phase:
what it is, what actually shipped, status today. This is the §5 skeleton for
`CGA_Architecture_Plan_2026-07.docx`.

**Letter reconciliation** (three systems, one ledger): the institutions build used
letters A–E over "Phases 0–5" numbering (A ≈ 0–1 Foundation+Identity, B ≈ 2, C ≈ 3,
D ≈ 4, E ≈ 5); Phase F–G continued the letters; H–O are the charter's canonical
letters (`docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md` — the exploration docs'
self-assigned letters are retired). K split into K-1/K-2/K-3 during the G
continuation.

## The ledger

| Ph | Name | Status 2026-07-24 | What shipped / what it is |
|----|------|-------------------|---------------------------|
| **A** | Foundation, Constitutional Engine, Identity (builds 0–1) | ✅ BUILT | Docker stack · Laravel 12 + Vue 3 + Inertia · ConstitutionalEngine + hash-chained `audit_log` · clocks + scheduler · activation engine · design system/AppShell · UUID users, GPS `location_pings`, residency confirmations, recursive ancestor-sweep associations, derived roles R-01→R-04 |
| **B** | Elections Engine (build 2) | ✅ BUILT | PROTECTED `VoteCountingService`: PR-STV/Droop/Gregory + single-winner RCV + universal countback · two-phase open ballot + commitment scheme · bootstrap election board · certification auto-seating |
| **C** | Legislature Operations (build 3) | ✅ BUILT | Peg-quorum chamber votes · bicameral dual agreement · Speaker (RCV supermajority) · committees (faction-independent assignment) · bills → versioned laws · referendums + petitions · emergency powers (CLK-03, 90-day ceiling) |
| **D** | Executive & Organizations (build 4) | ✅ BUILT | Exec delegation/conversion (dual supermajority) · departments + Boards of Governors (CLK-09, 10-yr) · executive orders w/ pre-issuance scope validation · full org module + hardened co-determination (CLK-13/14, 100→2000 thresholds) · board elections · CGC public-domain IP register |
| **E** | Judiciary & Law (build 5) | ✅ BUILT | Appointed/elected courts · equal-per-constituent nomination · cases/panels/juries/advocates · double jeopardy · Art. IV §5 three-path challenge ending in DIRECT judicial law-editing (`judicial_remedy` version, history preserved) |
| **F** | Federation substrate & versioned maps | ✅ BUILT | Peer mesh · Full Faith & Credit sync · authority flip (export bundle = seed) · CLK-20 · `jurisdiction_maps` boundary versioning · i18n machinery (loader, glossary, pseudo-locale, 5-locale chrome) |
| **G** | Federated adoption, earned autonomy, social mesh, G-ID | ✅ CODE-COMPLETE (`823e752`, 322 pins, 0 skips) · ⏳ 2 rig gates parked | Prong 1 volunteer mirror mesh (cold-sync→join-key→vouch→wizard) · Prong 2 earned autonomy (G-ID attestation, co-member clusters, write-routing, Patroni HA, fail-closed ballot re-wrap, autonomy vote) · parked: **G-V1** native Capacitor mobile w/ on-device GPS test, **G-V2** real cross-machine peer onboarding (both need the physical rig) |
| **H** | Districting completion & planetary map generation | ✅ CONVERGED 2026-07-23 · residue in hand | Run 6: 520,553/521,050 sweeps + all 434,080 singles; **951,626 legislatures districted**; Earth **1,999 seats EXACT** (282 districts), USA 702 EXACT; leaf giants line-split (no clamp stubs — 472k root-leaf splits); F-ELB-007/008 live; `district_subdivisions` + exactness law + seat-drift audit. Residue (lane 1): 495 review items (concave 426 hand-draw · monsters 8 solo retries · gap 28 · frag 20 · misc 13) + analysis round + **geodata pull engine build** (`docs/plans/etl/GEODATA_PULL_ENGINE_PLAN.md`) |
| **I** | Activation tiers & reach/legitimacy metric | ◻ UNBUILT (design locked) | Tier gates when a government may *boot* (never the franchise): `clamp(ceil(k·pop^⅓), floor, cap)` params as ONE amendable settings row at planet root · `legitimacy_snapshots` (k-anon reach ratio = verified residents ÷ population, explicitly NOT Art. VI §3 legitimacy) · zero new forms/clocks. **Provides Phase O's denominator.** Note: live sizing law today is `max(5, round(pop^⅓))`, ceiling 9 leaves-only — the k/cap curve is design intent |
| **J** | Cosmopolitan Coalition as organization | ◻ UNBUILT (tiny; **last** in work order — ships when the live game opens) | Two nonprofits on the built Phase-D org module: **Cosmopolitan Party Foundation** (parent) + **Cosmopolitan Coalition of United Earth** (authoring child) — the same pair the 8a/8b websites represent · voluntary `public_domain_charter` (one-way) · Δ4 authorship bridge feeding K + N · strict civil-society firewall (Article-I levers only) |
| **K-1** | Civic record plane (public square + halls) | ✅ BUILT | Per-jurisdiction square + halls bound to governance objects · Art. I: the square **cannot be censored** — exactly four carve-outs (judicial order, rights-protection, per-user block, content-neutral anti-spam) · F-CHR/F-SOC forms live |
| **K-3** | Matrix mesh commons | ✅ BUILT | Synapse + MAS + LiveKit voice, appservice-bridged to K-1 · illegal-content layer kept OFF the constitutional plane |
| **K-2** | Civic education + achievements | ◻ UNBUILT | Education tracks/modules (content authored via J; **The_Chart.drawio is the curriculum map**) · achievements: no governance advantage, no per-person composite score, no individual leaderboards · consumes I's reach gauge · includes the named **factions→polymorphic teaching correction** |
| **L** | Public finance | ◻ UNBUILT (L+M = one unit) | Double-entry hash-chained public ledger (`LedgerService` sole writer) · revenue/levies (filings private) · budgets → existing appropriations · borrowings · **currency reserved to root** (Art. V §5) · no-paywall-on-civic-rights pin (Art. II §8) · F-LEG-037..040, F-TRE-001..003 |
| **M** | Market economy | ◻ UNBUILT (gated on L) | Labor board (hires feed co-determination) · marketplace · mutual aid · **UBI: eligibility = active residency ONLY**, public aggregate + private receipts, never federated · sybil defense rides G-ID · F-TRE-004, F-IND-018..023, F-ORG-008 |
| **N** | Full i18n + accessibility + media (second-to-last) | ◻ UNBUILT (machinery live from F; lane 5 is its front-runner) | Extract ~90% hardcoded body copy across 64 pages/48 components → CI-gated catalogs · 115 registered locales / 77+ languages via local-NLLB + Haiku router · WCAG 2.2 AA + EN 301 549 · video→translated-video + `MultiTrackPlayer.vue` · glossary terms + ID tokens byte-identical in every locale · localizes EVERYTHING H–M before the demo |
| **O** | The full-scale demo (capstone — last) | ◻ UNBUILT (lane 4 is its design front-runner) | Two physically separate instances: `earth.*` **the Standard** (real multiplayer, dormant scaffolding, zero synthetic data) vs `earth-demo.*` **the Attained** (~8B synthetic world, `instance_class='scale_demo'` FORCES federation off, ephemeral copy-on-write sandbox, `DemoPopulateService` drives engine statics so demo math == engine math) · gated on H (map) + I (denominator) + N (localization) |

**Score: 10 built** (A–G, H-converged, K-1, K-3) · **7 unbuilt** (I, J, K-2, L, M, N, O).

## The chart

```mermaid
flowchart TD
    subgraph BUILT["BUILT SUBSTRATE"]
        A["A Foundation+Identity"] --> B["B Elections"] --> C["C Legislature"] --> D["D Exec+Orgs"] --> E["E Judiciary"] --> F["F Federation substrate"] --> G["G Adoption+G-ID<br/>(2 rig gates parked)"]
        K1["K-1 Civic square"]:::built
        K3["K-3 Matrix mesh"]:::built
        H["H Districting<br/>CONVERGED 07-23<br/>(495-item residue)"]:::resid
    end
    G --> H
    G --> I["I Tiers + reach"]:::todo
    G --> J["J Coalition org<br/>(ships at live launch)"]:::todo
    G --> L["L Public finance"]:::todo
    L --> M["M Market economy<br/>(UBI ↔ G-ID)"]:::todo
    I --> K2["K-2 Education + achievements"]:::todo
    J --> K2
    K2 --> N["N Full i18n + a11y + media"]:::todo
    L --> N
    M --> N
    H --> O["O FULL-SCALE DEMO<br/>(capstone)"]:::todo
    I --> O
    N --> O
    CL["⚑ 2026-09-01 SCOPED CLOUD LAUNCH<br/>earth.* Standard live early —<br/>real consent, current i18n, NOT Phase O"]:::launch
    H -.-> CL
    G -.-> CL
    classDef built fill:#1a6b3c,stroke:#0d3d22,color:#fff
    classDef resid fill:#8a6d1a,stroke:#5c470e,color:#fff
    classDef todo fill:#4a4a5a,stroke:#2e2e3a,color:#ddd
    classDef launch fill:#7a2048,stroke:#4d1129,color:#fff
    class A,B,C,D,E,F,G built
```

**Standing work order** (operator, 2026-06-19): **H → L+M → I → K-2 → N → O → J**.
**Overlay** (operator, 2026-07-23): the 40-day scoped cloud launch jumps the queue —
`earth.*` the Standard goes live by 2026-09-01 with real consent, current i18n, and
provisionable institutions; L/M, K-2, full N, and O stay on the long arc.

## The two drawio authorities (now reconciled at census level)

**`The_Chart.drawio`** (549 distinct labels): the **principles + curriculum map** —
A Fair Constitution decomposed into teaching Units → Lessons → Chapters per section
(Preamble, I Individuals, II.2/II.4/II.5/II.6/II.9, III.6, IV.4, V.2/V.4/V.5/V.7,
VI.3 …) with weight percentages per unit. It is **Phase K-2's content source** and
the sites' Learn-layer skeleton. Action: full label-level reconciliation (incl.
faction-language sweep, T-1) belongs to the K-2 authoring pass — not blocking any
current lane.

**`App_Flows.drawio`** (WIP per CLAUDE.md): the pre-build flow sketchbook. Largely
superseded by the shipped 109-form registry and the 80 WF-* walkthroughs (inverted
into the v3 Learn layer). Contains concepts that were **never built** — operator
disposition wanted (keep-as-roadmap vs retire): Family Tree Support · Apply for
Grants · Legislature Fundraising / Fund Distribution Tools · Asset Registration
Support · Endorse Policies (vs built endorse-candidates/petitions) · Equal
Partnership Agreement flow. Several plausibly fold into L/M (fundraising/grants →
public finance; asset registration → treasury).

## Where each ledger row lands next

- Rows A–H, K-1, K-3 → the "as-built" half of `CGA_Architecture_Plan_2026-07.docx` §5.
- Rows I–O → its forward half, quoting the charter's theses + exit criteria.
- The whole table (plain-language edition) → `docs/findings/FINDINGS_DIGEST.md`
  "Where the build stands" section for the site chats.
- The mermaid chart → the docx as a rendered figure + the digest as-is (GitHub
  renders mermaid at the raw/blob view's rendered mode; sites get the table).
