# CGA — Phase E: Judiciary & Law (detailed plan)

Status: design round complete (2026-06-13), awaiting operator sign-off before build.
Builds on Phases A–D. Live test beds: San Marino (bicameral, delegated executive,
a department + governor, the institutions:demo-d standing state), Montegiardino
(unicameral), Earth. All three carry a `forming` judiciary stub from activation.

Phase E is the **constitutional core**: a court that, under Art. IV §5, can rewrite
the text of a law directly when the legislature neither fixes nor overrides within
the judge-set windows. The good news from the design round: ~80% of Phase E is
*mirroring proven Phase A–D machinery* — the executive design was explicitly built
so judiciary creation/conversion reuses it, and many §5 hooks were pre-reserved in
Phase C (the `judicial_remedy` law-version source, the CLK-11/12 override slot, the
emergency/petition review holds).

## Design documents (authoritative — read before building any WI)

| Doc | Covers |
|---|---|
| `PHASE_E_DESIGN_judiciary.md` | judiciaries/judicial_seats EVOLVE; F-LEG-017 creation (appointed default, supermajority); nomination mode DERIVED from constituents (equal-per-constituent vs Judicial Committee); F-LEG-021 consent (mirrors the BoG lane → 10-yr CLK-09 civil terms); F-LEG-018 conversion to elected (dual supermajority via MultiJurisdictionVoteService + PROTECTED election machinery, min-5 group race); judge removal (F-LEG-022 → officeholder_remove supermajority). Owns the seat POOL; cedes per-case panels to cases. |
| `PHASE_E_DESIGN_cases_juries.md` | the Art. IV §4 adjudication core: cases/case_parties/case_filings (append-only docket), panels/panel_judges (odd ≥3, severity-scaled, en-banc), juries/jury_members (peer pool from residency), verdicts/sentencing_orders/warrants (Art. II §8 facts hardened), opinions/opinion_law_links, advocates (R-21); F-IND-015/017, F-ADV-001..004, F-JDG-001/002/003/009/010; double-jeopardy hardened validator pin; PanelSizing pure function. |
| `PHASE_E_DESIGN_challenge_law.md` | **THE exit criterion** — Art. IV §5 end to end: F-IND-016 challenge → F-JDG-004 finding → F-JDG-005 remedy (arms CLK-11 veto window + CLK-12 remedy timeframe, per-case judge-set via clock_timers.override_value) → three paths [Path 1 legislative remedial bill F-LEG-003; Path 2 supermajority override F-LEG-035; Path 3 windows expire → JudicialAutoRemedyJob → F-JDG-006 → EnactmentService::amendLaw(source='judicial_remedy'), history preserved]; F-JDG-007 emergency review; F-JDG-008 petition review; the amendments TWO-DOOR (legislative- vs constituent-supermajority, reconciled with F-LEG-031). |
| `PHASE_E_DESIGN_frontend.md` | 6 screens (judiciary-home, case-docket, case-detail, constitutional-challenge, advocate-console, juror-view); ZERO new CSS; only 4 new components (PanelTable, CaseLifecycle, Art4§5Tracker, JurorScreening); reuses ConstituentConsentPanel (dual supermajority) + LawDiff (its docblock already names "Phase E PATH C"); JudiciaryResolverController; FE-E0..E6 breakdown; phasesLive → E. |

## Key decisions (binding)
- **Mirror, don't reinvent.** Judiciary creation/conversion is the Phase D exec
  delegation/conversion pattern applied to judiciaries: `MultiJurisdictionVoteService`
  + `constituent_consent` arm (built in D explicitly for E), `CivilAppointmentService`
  10-yr CLK-09 terms, `CertificationService`/`ElectionLifecycleService` judicial
  branches over the **untouched PROTECTED `countStv`**, `OversightService` removal arm.
- **Nomination mode is DERIVED, never input**: constituents present → equal number of
  `constituent_nominated` seats per constituent (Art. IV §2a, PROTECTED equal-count
  invariant); none → a Judicial Committee (a `committees` row kind=judicial) nominates
  by supermajority (§2b).
- **§5 clocks are per-case, judge-set**: F-JDG-005 writes `clock_timers.override_value`
  (CLK-11 veto window, CLK-12 remedy timeframe — both pre-seeded in Phase A). CLK-11 is
  armed to `max(veto_closes_at, remedy_due_at)` so the §5.5 "neither modify NOR override"
  auto-remedy is a single deterministic fire. Path 1/2 cancel both timers.
- **Direct law edit reuses the reserved hook**: `EnactmentService::amendLaw(source=
  'judicial_remedy')` already exists (pierces the CLK-19 referendum shield); it appends
  a `law_versions` row — full history preserved. Phase E only *consumes* it.
- **Amendments two-door** (Art. IV §364-367): Door 1 = legislative supermajority
  (existing F-LEG-031 path); Door 2a = constituent supermajority (`DUAL_DOOR_KEYS`
  starts at `[judiciary_is_elected]`, via MJV); Door 2b = population referendum
  (existing route). All converge on the single settings-mutation entry point, bounded
  by the existing entrenchment `SETTING_BOUNDS`.
- **Double jeopardy + warrants hardened**: a criminal verdict sets
  `cases.double_jeopardy_locked` atomically; re-prosecution is a PROTECTED rejected-row
  pre-commit. Warrants require a NOT-NULL stated reason + max hold duration (Art. II §8).

## Owner-ruling resolutions (unstated in the Template — pinned in the q-ledger)
- F-LEG-021 judicial consent threshold (Art. IV silent) → reuse `bog_consent` (ordinary
  majority of all serving), MANIFEST §8 unstated-threshold ruling.
- Panel-size ladder (constitution mandates only odd/≥3/scaling/en-banc) → minimal lawful
  ladder: minor/moderate 3, serious 5, constitutional-major en banc.
- "Reasonable timeframe"/"set window" (§5) → floor >0 days, **no cap** (none stated).
- "Constituent" for consent = legislature-bearing direct children (the WF-JUR-04 / Phase D
  exec-conversion ruling).
- Multi-law findings → single-law spine for V1; `finding_offending_laws` reserved for the
  N-law fan-out.

## Work items
**Backend** (dependency order): **E-JUD** (judiciaries/seats/nominations evolve +
F-LEG-017/018/021 + removal + conversion) → **E-CASES** (cases/panels/juries/advocates +
F-IND-015/017 + F-ADV-* + F-JDG-001/002/003/009/010 + double-jeopardy/warrant pins) →
**E-CHALLENGE** (constitutional_challenges + findings/remedies + F-IND-016 + F-JDG-004/005/006/007/008
+ F-LEG-035 + CLK-11/12 jobs + the amendments two-door). The `CaseService::open` seam is a
single owner shared by E-CASES (case-row half) and E-CHALLENGE (finding/remedy half).
**Frontend**: **FE-E0** (surfaces/nav/state-machines/JudiciaryResolverController) →
**FE-E1** (dev/judiciary-kit + the 4 new components, fixture-first) → **FE-E2** judiciary-home
→ **FE-E3** case-docket + case-detail (exit-criterion cases surface) → **FE-E4** advocate-console
→ **FE-E5** constitutional-challenge (**Art4§5Tracker — the exit criterion**) → **FE-E6**
juror-view. phasesLive flips to `['A','B','C','D','E']` with the final batch.

Constitutional placeholders converted: the 2 remaining `FuturePhasePlaceholdersTest`
skips (judicial panels, Art. IV §5) become live pins — Phase E leaves ZERO skips.

## Exit criteria
1. **The Art. IV §5 chain** (the headline): any resident files F-IND-016 against a law →
   the full court issues a Constitutional Finding (F-JDG-004) + Remedy Recommendation
   (F-JDG-005, arming the per-case veto + remedy clocks) → the legislature lets BOTH
   windows expire → CLK-11 fires → the judiciary applies its own remedy (F-JDG-006),
   editing the law text directly via a `judicial_remedy` law version with full history
   preserved + the rejected/override paths on the public record.
2. **A judiciary stands up appointed and converts**: F-LEG-017 creates an appointed court
   (equal-per-constituent nomination → F-LEG-021 consent → 10-yr CLK-09 seats); F-LEG-018
   converts it to elected only on the dual supermajority (legislature + constituents).
3. **A case runs end to end**: F-IND-017/F-ADV-001 files → F-JDG-001 panels an odd ≥3 bench
   (en banc for a constitutional-major) → optional jury (F-JDG-002) → verdict → F-JDG-009
   sentencing / F-JDG-010 warrant; a re-prosecution of the same criminal act is rejected
   (double jeopardy) on the record.

## Deferrals (justified in the designs)
Appellate hierarchy (`parent_judiciary_id` kept, no workflow in the Template);
multi-law-finding N-law fan-out (single-law spine V1); appeal grounds form (Art. II §8
"proven contradictions/errors" — deferred); juror voir-dire as a thin controller endpoint
(no catalog form); Full Faith & Credit cross-jurisdiction recognition (Phase F).
