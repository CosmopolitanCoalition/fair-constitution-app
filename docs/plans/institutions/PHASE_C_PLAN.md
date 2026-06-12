# CGA — Phase C: Legislature Operations (detailed plan)

Status: approved direction (operator: "Lets move on to Phase C", 2026-06-14). Builds on
Phases A+B. Live test beds: San Marino (ACTIVE bicameral chamber, 41 = 32 type_a + 9 type_b,
vote_share_norm populated) and Montegiardino (ACTIVE unicameral 8 + 1 vacancy).

## Design documents (authoritative — read before building any WI)

| Doc | Covers |
|---|---|
| `PHASE_C_DESIGN_votes_laws.md` | public_records substrate, sessions/attendance, **the chamber vote engine** (chamber_votes + chamber_vote_tallies lane table — one code path for unicameral/committee/each-bicameral-kind; thresholds ALWAYS via ConstitutionalValidator), the 33-vote-type registry (config/constitution/vote_types.php), bills(+versions) → laws(+versions git-style, scale/scope/act_type, judicial_remedy source for E), setting_changes + clock re-derivation, referendums (CLK-19 shield, Phase B ballot integration incl. the C-8 envelope/ballot referendum fix), petitions (CLK-17, F-ELB-005), emergency powers (cause enum, CLK-03 auto-expire, engine-level civic-process protection), F-LEG handlers, 4 constitutional test specs |
| `PHASE_C_DESIGN_chamber_ops.md` | speaker (supermajority RCV + re-ballot posture, tie-break-only voting, neutral presiding), committees (creation, allocation formula, ranked preferences, **vote_share_norm tie-break q#q2**, bicameral kind-ratio mirroring, chair/alternate RCV), admin office + rules/ethics, misconduct/removal proceedings (speaker judges except own), proper election board (F-LEG-012 + bootstrap retirement WF-ELE-10), F-LEG-036 closed loop, relocation → vacancy linkage, R-10..R-13 derivations |
| `PHASE_C_DESIGN_frontend.md` | 16 pages with exact props contracts, components (SeatMap rings, LawDiff, **VoteTally** — one renderer for all 6 threshold classes × uni/bicameral, AgendaStrip, SignatureMeter, VoteCastList, EmergencyBanner), /dev/legislature-kit fixture harness, public-records vs audit-chain distinction, FE-C0..C11 breakdown with verification steps |

## Key architectural decisions (binding)
- **Lane-table vote engine**: per-kind tallies are rows (`chamber_vote_tallies`), not columns —
  unicameral, committee, and each bicameral kind run identical quorum+threshold math through
  ONE code path; Phase D board votes reuse it with zero migration.
- Thresholds resolve ONLY through `ConstitutionalValidator::supermajority()/quorum()` —
  never reimplemented; snapshotted onto the vote row at open (UI renders server numbers only).
- Bicameral (q-ledger #q7): per-kind peg quorum AND threshold, at committee AND floor.
- Laws are git-style versioned with scale + scope + act_type; settings bills apply on enactment
  and re-derive clocks (the exit-criterion receipt is visible on TermSync).
- Emergency powers: closed cause enum (disaster|invasion), ≤90d incl. renewals, CLK-03
  auto-expiry, civic-process protection as VALIDATOR rules, session agenda slot 1 locked.
- public_records (curated, append-only, translations jsonb) vs audit_log (raw chain) — every
  published transition writes a record row via PublicRecordService inside the transaction.

## Work items
Backend: C-SESSIONS, C-VOTES (engine), C-BILLS, C-LAWS, C-COMMITTEES, C-ADMIN, C-REFERENDUM,
C-EMERGENCY, C-PETITIONS, C-RECORDS, C-RELOCATION (detail in the two backend designs).
Frontend: FE-C0 (surfaces/nav/CSS/resolver) → FE-C1 (component kit, fixture-first,
/dev/legislature-kit) → FE-C2 Chamber → FE-C3 SessionConsole → FE-C4 Bills/BillDetail →
FE-C5 Settings (exit criterion); parallel: FE-C6 Committees, FE-C7 SpeakerTools, FE-C8
Oversight; FE-C9 Referendums+Emergency, FE-C10 Petitions+Relocation+TermSync, FE-C11
PublicRecords.

Constitutional placeholders converted this phase: PegQuorumTest, BicameralDualAgreementTest,
EmergencyCeilingTest, ReferendumShieldTest (4 of the 8 remaining).

## Exit criterion
A seated legislature passes a bill into versioned law under peg quorum in BOTH modes:
Montegiardino (unicameral 8 serving — committee then floor 5-of-8) and San Marino (bicameral —
type_a 17/32 AND type_b 5/9 at committee AND floor; failing one kind fails the act with the
failing kind named). Then a settings bill changes election_interval_months 60→48 and the
CLK-01 timers re-derive (receipt on TermSync). All through the engine, all chained.

## Deferrals (designed-in, justified in the docs)
F-JDG-008 petition constitutional review (honest Phase E hold), challenge agenda slot 2,
court-appeal links (planned-flags), translation pipeline execution (badges only),
unauthenticated public-records read (Phase F), automated speaker re-ballot scheduling.
