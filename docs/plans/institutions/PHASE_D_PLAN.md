# CGA — Phase D: Executive & Organizations (detailed plan)

Status: approved direction (operator, 2026-06-15). Builds on Phases A–C. Live test beds:
San Marino (bicameral chamber + speaker + committees + Act 2026-01, exec stub `forming`),
Montegiardino (unicameral), 211 demo residents, working election + chamber-vote machinery.

## Design documents (authoritative — read before building any WI)

| Doc | Covers |
|---|---|
| `PHASE_D_DESIGN_executive.md` | executives/executive_members evolutions (ESM-16: one row per jurisdiction, conversion evolves it), **the unified boards + board_seats set** (departments AND orgs AND CGCs — one co-determination engine; worker-headcount polymorphic contract), departments + charters, BoG pipeline (F-EXE-001 nominate → F-LEG-020 consent via the existing ChamberActService lane → 10-yr CLK-09 terms), executive_orders w/ pre-issuance scope validation (rejected orders persist + publish BEFORE rethrow), policy_proposals/investigations, department_rules/reports, appropriations/grants, F-LEG-014 delegation (committee-proportional selection), F-LEG-015 conversion via MultiJurisdictionVoteService (first live consumer), election integration (exec_committee seat_kind ≥5; individual = single + deriveAdvisors) |
| `PHASE_D_DESIGN_organizations.md` | organizations evolution (ownership structures), org_memberships (R-24) / org_workers (R-25, F-IND-014 = THE headcount feed, polymorphic employer incl. departments), ownership stakes, contracts (co-sign gate, labor_recurring feeds headcount), document packages, transfers (dual consent), conversions (monopoly: fair-market floor + founding-governor offers; public↔private), **cgc_ip_register (append-only, irreversible — no update/delete path)**, co-determination engine (linear interpolation 100→parity@2000, CLK-13/14, composition_valid blocks board acts), board elections reusing the election machinery (kind org_board_owner/worker, electorate_type, chair via body_type='board' chamber votes), WorkerRepresentation + CgcIpPublicDomain test specs |
| `PHASE_D_DESIGN_frontend.md` | 11 pages w/ exact props, components (ConstituentConsentPanel, CoDetScale, BoardStrip, OwnershipPanel, DepartmentCard, OrderScopeCard, Ui/Stepper), /dev/executive-kit harness, the three exit-criterion surface chains, FE-D0..D9 breakdown |

## Key decisions (binding)
- ONE boards + board_seats set for departments/CGCs/private orgs; only CoDeterminationService
  writes worker_seats; composition_valid=false blocks board acts except the curing elections.
- Conversion evolves the SAME executives row (no second row ever); delegated members are
  ex-officio legislators (term via legislature_member_id, never duplicated).
- Rejected executive orders persist (`rejected_pre_issuance` + citation) and publish to
  public_records BEFORE the 422 rethrows — the exit-criterion mechanism.
- exec_committee races: seats ≥ 5, NO ceiling (Art. III §2 floors at 5; the 1–9 cap is recut).
- CGC IP register: append-only at schema + route level; the form has no status field.

## Work items
Backend: D-EXEC, D-DEPTS (boards substrate), D-ORDERS, D-RULES, D-GRANTS, D-ORGS, D-CODET,
D-BELECT, D-TRANSFERS (detail in the two backend designs).
Frontend: FE-D0 (surfaces/nav/CSS/resolver) → FE-D1 (kit @ /dev/executive-kit) →
FE-D2 Executive/Home → FE-D3 Departments+Detail (exit 3) → FE-D4 Actions (exit 1) →
FE-D5 Reporting; parallel org fork: FE-D6 Registry+OrgDetail → FE-D7 CoDetermination (exit 2)
→ FE-D8 BoardElections → FE-D9 CgcDetail+Transfers. phasesLive 'D' flips with the last batch.

Constitutional placeholders converted: WorkerRepresentationTest + CgcIpPublicDomainTest
(leaves only the 2 Phase E placeholders).

## Exit criteria
1. Delegated exec committee governs departments with consented governors (F-EXE-001 →
   chamber consent vote → seated w/ 10-yr CLK-09 terms beside lockstep worker seats).
2. An org crossing 100 active F-IND-014 workers auto-triggers its first worker seat
   (CLK-13): applies-equally row flips, composition_valid=false, worker-track election opens.
3. An out-of-scope executive order is rejected pre-issuance with the verbatim citation AND
   the rejection is on /system/public-records + the audit chain.

## Deferrals (justified in the designs)
Judicial review of orders (E), judicial dissolution (E), org-side grant self-service (polish),
FF&C transfer sync (F), elected-committee conversion UI fixture (model in About panel until an
instance converts), specialized defense/state surfaces (F-adjacent), CoDetScale stepped slider
(all-phases pass).
