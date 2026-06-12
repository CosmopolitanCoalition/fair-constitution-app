All six sheets extracted and verified against expected counts (30 roles, 103 forms, 33 vote types, 80 workflows, 20 state machines, 21 clocks). Registry follows.

# CGA Data-Model Registry (extracted from canonical spreadsheets)

Sources:
- `E:\fair-constitution-app\App Docs\CGA_Constitutional_Roles_Forms_Chart.xlsx` — sheets "1. Roles", "3. Forms Catalog", "5. Role-Form Summary", "7. Special Vote Types"
- `E:\fair-constitution-app\App Docs\CGA_Workflows_Catalog.xlsx` — sheets "1. Workflow Inventory", "3. Entity State Machines", "4. Clocks & Triggers"

---

## A) Roles Registry (30 roles, R-01…R-30)

The Roles sheet has no explicit term column; terms below are derived from the Clocks sheet (CLK-01 general 5-yr lockstep, CLK-09 10-yr civil/judicial appointments) where canonical.

### Individual Tier
| ID | Role | Institution/Category | Acquisition | Term |
|---|---|---|---|---|
| R-01 | Individual | Individual | System registration (inherent by nature) | — |
| R-02 | Resident | Individual | Automatic upon location verification (R-01 + GPS ping pattern meeting residency threshold) | While residency holds |
| R-03 | Jurisdictionally Associated | Individual | Automatic upon residency verification (R-02 + point-in-polygon match to all enclosing jurisdictions) | While residency holds |
| R-04 | Voter | Individual | Automatic — right exists upon jurisdictional association (R-03 + active election) | — |
| R-05 | Petitioner | Individual | Self-initiated by creating/signing a petition (R-03) | — |

### Candidate & Electoral Tier
| ID | Role | Institution/Category | Acquisition | Term |
|---|---|---|---|---|
| R-06 | Candidate | Electoral | Self-registration during candidacy window (R-03) | Until election resolved/withdrawn |
| R-07 | Endorsed Candidate | Electoral | Organization submits endorsement form (R-06 + registered org grants endorsement) | — |
| R-08 | Election Board Member | Electoral (I-ELB) | Legislative appointment vote (R-03 + I-LEG exists) | Civil appointment (10 yr default, CLK-09) |

### Legislative Tier
| ID | Role | Institution/Category | Acquisition | Term |
|---|---|---|---|---|
| R-09 | Legislative Representative | Legislative (I-LEG) | Automatic upon certified STV election victory (R-06 + won) | Legislative term (5 yr default, CLK-01) |
| R-10 | Speaker of the Legislature | Legislative (I-SPK) | Supermajority election (RCV) by legislature at first session after general election | Until next legislature unless replaced |
| R-11 | Committee Member | Legislative (I-COM) | Proportional assignment algorithm from ranked preferences (R-09 + I-COM + preferences submitted) | Legislative term |
| R-12 | Committee Chair | Legislative (I-COM) | Ranked choice vote by the full legislature (R-11) | Legislative term |
| R-13 | Committee Alternate Chair | Legislative (I-COM) | Automatic — top runner-up in chair RCV election | Legislative term |

### Executive Tier
| ID | Role | Institution/Category | Acquisition | Term |
|---|---|---|---|---|
| R-14 | Executive Committee Member | Executive (I-EXC) | Factional proportional selection from legislature (after supermajority delegation act) | Legislative term (lockstep) |
| R-15 | Elected Executive Officer (Committee Type) | Executive (I-EEO) | PR-STV election by entire population (after supermajority conversion) | = Legislative term (Art. III §3 lockstep) |
| R-16 | Elected Executive Officer (Individual Type) | Executive (I-EEO) | Single-winner RCV election by entire population | = Legislative term (lockstep) |
| R-17 | Executive Advisor/Alternate | Executive (I-EEO) | Automatic — top 4 runners-up in individual exec RCV election | = Principal's term |
| R-18 | Board of Governors Member | Executive (I-BOG/I-DEP) | Executive nomination (R-14/15/16) + legislative consent vote | Civil appointment (10 yr default, CLK-09) |

### Judicial Tier
| ID | Role | Institution/Category | Acquisition | Term |
|---|---|---|---|---|
| R-19 | Judge (Appointed) | Judicial (I-JUD) | Nomination (constituent jurisdictions or judicial committee) + legislative supermajority confirmation | 10 yr default (CLK-09) |
| R-20 | Judge (Elected) | Judicial (I-JDE) | STV election in groups (min 5/race) by entire population, after conversion act | Term-synced (CLK-10) |
| R-21 | Advocate | Judicial | Registration with jurisdictional judiciary (R-03 + qualifications per jurisdiction law) | — |
| R-22 | Juror | Judicial | Random selection by court from eligible R-03 pool | Per case |

### Organization Tier
| ID | Role | Institution/Category | Acquisition | Term |
|---|---|---|---|---|
| R-23 | Organization Registrant/Agent | Organization (I-ORG) | Self-registration of organization (R-03) | — |
| R-24 | Organization Member/Shareholder/Partner | Organization | Application/purchase of membership or shares | — |
| R-25 | Organization Worker | Organization | Employment contract registration | — |
| R-26 | Owner-Elected Board Member | Organization | Board election by ownership class (R-24) | Board cycle (WF-ORG-05) |
| R-27 | Worker-Elected Board Member | Organization | Board election by worker class — triggers at 100 employees (R-25) | Board cycle |
| R-28 | Board Chair (Organization) | Organization | Joint election by entire board (R-26 or R-27) | Board cycle |

### Administrative Tier
| ID | Role | Institution/Category | Acquisition | Term |
|---|---|---|---|---|
| R-29 | Administrative Office Staff | Administrative (I-ADM) | Legislative appointment (after I-ADM created) | Civil appointment (10 yr default) |
| R-30 | Civil Officer | Administrative (I-DEP) | Appointment by relevant authority, legislative consent where required | 10 yr (Art. II §9) |

---

## B) Forms Catalog (103 forms, grouped by prefix)

Format: ID | Name | Who files | Creates/Mutates.

### F-IND — Individual Forms (17)
| ID | Name | Filed by | Creates / Mutates |
|---|---|---|---|
| F-IND-001 | Individual Registration | R-01 Individual | Creates: Individual record |
| F-IND-002 | Profile Management | R-01 | Modifies: Individual record |
| F-IND-003 | Residency Declaration | R-01 | Creates: Residency tracking record; starts ping collection |
| F-IND-004 | Identity Verification Submission | R-01 | Modifies: Individual record (verified status) |
| F-IND-005 | GPS Residency Ping | R-01 | Appends: Residency ping log |
| F-IND-006 | Residency Verification Confirmation | R-02 Resident (pending); system auto-generates | Creates: Verified residency + all jurisdictional associations (grants R-03) |
| F-IND-007 | Ballot Submission (Ranked Choice) | R-04 Voter | Creates: Encrypted ballot record |
| F-IND-008 | Referendum Vote | R-04 Voter | Creates: Referendum ballot record |
| F-IND-009 | Petition Creation | R-05 Petitioner | Creates: Petition record |
| F-IND-010 | Petition Signature | R-03 | Appends: Signature to petition |
| F-IND-011 | Candidacy Registration | R-03 | Creates: Candidate record (grants R-06) |
| F-IND-012 | Organization Registration | R-03 | Creates: Organization record (I-ORG); grants R-23 |
| F-IND-013 | Organization Membership Application | R-01 | Creates: Membership record (grants R-24) |
| F-IND-014 | Worker Registration | R-01 | Creates: Worker record (grants R-25) |
| F-IND-015 | Advocate Registration | R-03 | Creates: Advocate record (grants R-21) |
| F-IND-016 | Constitutional Challenge Filing | R-03 | Creates: Constitutional challenge case record |
| F-IND-017 | Civil/Criminal Case Filing | R-03 (via advocate R-21) | Creates: Case record |

### F-CAN — Candidate Forms (3)
| ID | Name | Filed by | Creates / Mutates |
|---|---|---|---|
| F-CAN-001 | Campaign Profile Setup | R-06 Candidate | Modifies: Candidate record |
| F-CAN-002 | Endorsement Request | R-06 | Creates: Endorsement request record |
| F-CAN-003 | Candidacy Withdrawal | R-06 (before voting period) | Modifies: Candidate record (withdrawn) |

### F-ORG — Organization Agent Forms (7)
| ID | Name | Filed by | Creates / Mutates |
|---|---|---|---|
| F-ORG-001 | Organization Profile Management | R-23 Org Agent | Modifies: Organization record |
| F-ORG-002 | Candidate Endorsement Grant | R-23 | Creates: Endorsement record → candidate becomes R-07 |
| F-ORG-003 | Board Election Administration | R-23 | Creates: Board election → R-26 Owner-Elected Board Members |
| F-ORG-004 | Worker Board Election Administration | R-23 / System auto-trigger (100+ workers) | Creates: Worker board election → R-27 members |
| F-ORG-005 | Ownership Transfer Initiation | R-23 | Creates: Transfer agreement record (mutual consent) |
| F-ORG-006 | Public-Private Conversion Request | R-23 / Legislature | Modifies: Organization type (private↔CGC) |
| F-ORG-007 | Organization Dissolution | R-23 | Modifies: Organization status → dissolved |

### F-ELB — Election Board Forms (6)
| ID | Name | Filed by | Creates / Mutates |
|---|---|---|---|
| F-ELB-001 | Election Scheduling Order | R-08 Election Board Member | Creates: Election record (I-ELE) |
| F-ELB-002 | Candidate Validation | R-08 | Modifies: Candidate record (validated/rejected) |
| F-ELB-003 | Subdivision Boundary Drawing | R-08 (when seats > 9) | Creates: Subdivision boundary records (I-SUB) |
| F-ELB-004 | Election Results Certification | R-08 | Modifies: Election record (certified) → winners granted roles |
| F-ELB-005 | Petition Signature Audit | R-08 | Modifies: Petition record (valid/invalid) |
| F-ELB-006 | Recount/Audit Order | R-08 | Creates: Recount proceedings record |

### F-LEG — Legislative Representative Forms (36)
| ID | Name | Filed by | Creates / Mutates |
|---|---|---|---|
| F-LEG-001 | Oath of Office / Seating Acceptance | R-09 | Activates legislative role; seat record updated |
| F-LEG-002 | Attendance Registration | R-09 | Appends: Session attendance record |
| F-LEG-003 | Bill Introduction | R-09 | Creates: Bill record (enters lifecycle) |
| F-LEG-004 | Floor Vote | R-09 (quorum present) | Appends: Vote record to bill/act |
| F-LEG-005 | Committee Vote | R-11 | Appends: Committee vote record |
| F-LEG-006 | Public Record Statement | R-09 | Appends: Public record entry |
| F-LEG-007 | Motion Submission | R-09 | Creates: Motion record → triggers vote |
| F-LEG-008 | Speaker Nomination/Election Vote | R-09 (first session) | Creates: Speaker role (R-10, I-SPK) |
| F-LEG-009 | Committee Creation Act | R-09 (supermajority) | Creates: Committee (I-COM) → triggers assignment |
| F-LEG-010 | Committee Preference Ranking | R-09 | Input to assignment algorithm → R-11 |
| F-LEG-011 | Committee Chair/Alternate Vote | R-09 | Creates: R-12 Chair, R-13 Alternate |
| F-LEG-012 | Election Board Creation Act | R-09 (supermajority) | Creates: I-ELB → triggers R-08 appointments |
| F-LEG-013 | Administrative Office Creation Act | R-09 | Creates: I-ADM → enables R-29 appointments |
| F-LEG-014 | Executive Committee Delegation Act | R-09 (supermajority) | Creates: I-EXC → R-14 selected proportionally |
| F-LEG-015 | Executive Office Creation/Conversion Act | R-09 (supermajority + constituent supermajority if applicable) | Creates: I-EEO → triggers executive election |
| F-LEG-016 | Department Creation Act | R-09 (exec branch exists) | Creates: I-DEP → charter + oversight |
| F-LEG-017 | Judiciary Creation Act | R-09 (supermajority) | Creates: I-JUD → triggers nomination process |
| F-LEG-018 | Judiciary Conversion Act | R-09 (supermajority + constituent supermajority) | Converts: I-JUD → I-JDE → triggers judicial election |
| F-LEG-019 | Common Good Corporation Creation Act | R-09 | Creates: I-CGC → assigns executive oversight |
| F-LEG-020 | Board of Governors Consent Vote | R-09 | Approves: R-18 → I-BOG |
| F-LEG-021 | Judicial Nomination Consent Vote | R-09 | Approves: R-19 Judge (Appointed) |
| F-LEG-022 | Removal/Impeachment/Censure/Expulsion Vote | R-09 (Speaker presides) | Modifies: Officeholder status → vacancy triggered |
| F-LEG-023 | Referendum Delegation Vote | R-09 (supermajority) | Creates: Referendum election record |
| F-LEG-024 | Emergency Powers Declaration Vote | R-09 (supermajority; disaster/invasion) | Creates: Emergency power record (max 90 days) |
| F-LEG-025 | Emergency Powers Renewal Vote | R-09 (supermajority) | Modifies: Emergency power record (extended) |
| F-LEG-026 | Monopoly Acquisition Vote | R-09 | Modifies: Organization → converts to I-CGC; fair market compensation |
| F-LEG-027 | CGC Reorganization/Sale Vote | R-09 | Modifies: CGC record → reorganized/dissolved/privatized |
| F-LEG-028 | Cultural Institution Recognition Vote | R-09 (supermajority + constituent supermajority if applicable) | Creates: Cultural Institution record |
| F-LEG-029 | Union Formation/Join Vote | R-09 | Modifies: Jurisdictional hierarchy |
| F-LEG-030 | Disintermediation Vote | R-09 (all constituents + encompassing agree) | Modifies: Jurisdiction dissolved; acts incorporated into constituents |
| F-LEG-031 | Amendable Setting Change (via Bill) | R-09 (bill lifecycle complete) | Modifies: jurisdiction_settings record within allowed range |
| F-LEG-032 | Rules of Order Adoption | R-09 | Creates/Modifies: Rules of order record |
| F-LEG-033 | Ethics Code Adoption | R-09 | Creates/Modifies: Ethics code record |
| F-LEG-034 | Referendum Act Modification Vote | R-09 (supermajority same term, or subsequent term) | Modifies: Law record |
| F-LEG-035 | Judiciary Override Vote | R-09 (supermajority, within veto window) | Modifies: Constitutional challenge → legislature prevails |
| F-LEG-036 | Vacancy Declaration | R-09 / R-10 Speaker | Creates: Vacancy record → triggers countback |

### F-SPK — Speaker Forms (9)
| ID | Name | Filed by | Creates / Mutates |
|---|---|---|---|
| F-SPK-001 | Session Call / Opening | R-10 Speaker | Creates: Session record; triggers quorum count |
| F-SPK-002 | Agenda Setting | R-10 | Creates: Session agenda (emergency powers first → constitutional matters → general) |
| F-SPK-003 | Quorum Count Publication | R-10 | Modifies: Session record (quorum met/not met) |
| F-SPK-004 | Tie-Breaking Vote | R-10 | Appends: Tie-breaking vote record |
| F-SPK-005 | Committee Assignment Administration | R-10 | Modifies: Committee assignment records → R-11 seated |
| F-SPK-006 | Member Priority Communication Facilitation | R-10 | Creates: Priority agenda items |
| F-SPK-007 | Impeachment/Censure/Expulsion Presiding | R-10 | Modifies: Proceeding record → triggers F-LEG-022 vote |
| F-SPK-008 | Attendance Compulsion Order | R-10 (quorum not met) | Creates: Compulsion order record |
| F-SPK-009 | Session Minutes Publication | R-10 / R-29 Admin Staff | Creates: Public record entry |

### F-CHR — Committee Chair Forms (4)
| ID | Name | Filed by | Creates / Mutates |
|---|---|---|---|
| F-CHR-001 | Committee Meeting Call | R-12 Chair | Creates: Committee session record |
| F-CHR-002 | Committee Agenda Setting | R-12 | Creates: Committee agenda |
| F-CHR-003 | Bill Referral to Floor | R-12 (after committee vote passes) | Modifies: Bill status → referred to floor |
| F-CHR-004 | Committee Report Filing | R-12 | Creates: Committee report record |

### F-EXE — Executive Forms (5)
| ID | Name | Filed by | Creates / Mutates |
|---|---|---|---|
| F-EXE-001 | Board of Governors Nomination | R-14/R-15/R-16 Executive | Creates: Nomination record → triggers F-LEG-020 consent vote |
| F-EXE-002 | Department Policy Proposal | R-14/15/16 | Creates: Policy proposal record |
| F-EXE-003 | Board Member Removal Request | R-14/15/16 | Creates: Removal proceeding record |
| F-EXE-004 | Department Investigation Order | R-14/15/16 | Creates: Investigation record |
| F-EXE-005 | Executive Order/Decision | R-14/15/16 (within delegated authority) | Creates: Executive order record |

### F-BOG — Board of Governors Forms (2)
| ID | Name | Filed by | Creates / Mutates |
|---|---|---|---|
| F-BOG-001 | Department Rule Implementation | R-18 Governor | Creates: Department rule/regulation record |
| F-BOG-002 | Department Report Filing | R-18 | Creates: Department report record |

### F-JDG — Judicial Forms (10)
| ID | Name | Filed by | Creates / Mutates |
|---|---|---|---|
| F-JDG-001 | Case Acceptance / Panel Assignment | R-19/R-20 Judge | Modifies: Case record (panel assigned, scheduled) |
| F-JDG-002 | Jury Selection Order | R-19/20 | Creates: Jury panel record → R-22 Jurors selected |
| F-JDG-003 | Opinion / Ruling Filing | R-19/20 | Creates: Opinion record (linked to case and laws) |
| F-JDG-004 | Constitutional Finding | R-19/20 | Creates: Constitutional finding record → starts legislative response window → notifies legislature |
| F-JDG-005 | Remedy Recommendation | R-19/20 | Appends: Remedy recommendation to finding record |
| F-JDG-006 | Judicial Remedy Application | R-19/20 (window expired, no override) | Modifies: Law record directly (new version created) |
| F-JDG-007 | Emergency Powers Review | R-19/20 | Creates: Review opinion → may recommend modification/termination |
| F-JDG-008 | Petition Constitutional Review | R-19/20 | Modifies: Petition record (constitutional/unconstitutional) |
| F-JDG-009 | Sentencing Order | R-19/20 | Creates: Sentencing record |
| F-JDG-010 | Warrant Issuance | R-19/20 (probable cause) | Creates: Warrant record |

### F-ADV — Advocate Forms (4)
| ID | Name | Filed by | Creates / Mutates |
|---|---|---|---|
| F-ADV-001 | Case Filing (on behalf of client) | R-21 Advocate | Creates: Case record |
| F-ADV-002 | Motion Filing | R-21 | Creates: Motion record |
| F-ADV-003 | Evidence Submission | R-21 | Appends: Evidence record to case |
| F-ADV-004 | Brief / Argument Filing | R-21 | Creates: Brief record |

**Total: 103** (IND 17, CAN 3, ORG 7, ELB 6, LEG 36, SPK 9, CHR 4, EXE 5, BOG 2, JDG 10, ADV 4)

### Role-Form Summary (sheet 5, acquisition vs. capability form counts)
| Role | Acq. | Cap. | Key forms |
|---|---|---|---|
| R-01 Individual | 1 | 7 | Registration, Profile, Residency Declaration, GPS Ping, ID Verification, Petition Creation/Signature |
| R-02 Resident | 1 | 0 | Residency Verification Confirmation (auto-generated) |
| R-03 Jurisdictionally Associated | 0 | 7 | Ballot, Referendum, Candidacy Reg, Org Reg, Org Membership, Worker Reg, Advocate Reg, Const. Challenge, Case Filing |
| R-04 Voter | 0 | 2 | Ballot Submission (RCV), Referendum Vote |
| R-05 Petitioner | 1 | 0 | Petition Creation |
| R-06 Candidate | 1 | 3 | Campaign Profile, Endorsement Request, Withdrawal |
| R-07 Endorsed Candidate | 0 | 0 | Status granted by org endorsement |
| R-08 Election Board Member | 1 | 6 | Scheduling, Validation, Boundary Drawing, Certification, Signature Audit, Recount |
| R-09 Legislative Rep | 1 | 36 | Oath → all F-LEG forms |
| R-10 Speaker | 1 | 9 | All F-SPK forms |
| R-11 Committee Member | 1 | 1 | Preference Ranking → Committee Vote |
| R-12 Committee Chair | 1 | 4 | All F-CHR forms |
| R-13 Alternate Chair | 0 | 4 | Same as Chair (acts when Chair absent) |
| R-14 Exec Committee Member | 0 | 5 | All F-EXE forms |
| R-15 Elected Exec (Committee) | 1 | 5 | All F-EXE forms |
| R-16 Elected Exec (Individual) | 1 | 5 | All F-EXE forms |
| R-17 Exec Advisor/Alternate | 0 | 0 | Advisory; steps in when principal absent |
| R-18 Board of Governors | 1 | 2 | Rule Implementation, Report Filing |
| R-19 Judge (Appointed) | 1 | 10 | All F-JDG forms |
| R-20 Judge (Elected) | 1 | 10 | Same 10 forms |
| R-21 Advocate | 1 | 4 | All F-ADV forms |
| R-22 Juror | 0 | 0 | Integrated into case management |
| R-23 Org Agent | 1 | 7 | All F-ORG forms |
| R-24 Member/Shareholder | 1 | 0 | Voting rights in board elections |
| R-25 Worker | 1 | 0 | Voting rights in worker board elections at 100+ |
| R-26 Owner-Elected Board | 1 | 0 | Board governance |
| R-27 Worker-Elected Board | 1 | 0 | Board governance |
| R-28 Board Chair (Org) | 1 | 0 | Presides over board |
| R-29 Admin Office Staff | 1 | 1 | Session Minutes Publication (shared with Speaker) |
| R-30 Civil Officer | 1 | 0 | Duties per department charter |

---

## C) Entity State Machines (20) — schema spec for status columns

Notation preserved from sheet: `→` transition, `[x]` optional state, `|` alternative terminal/branch.

1. **Individual**: Registered → Identity-Verified → Residency-Declared → Resident (R-02) → Jurisdictionally Associated (R-03) → [Relocating] → Re-associated | Deceased/Closed. *Hardened: association exists simultaneously at every nesting level (local→Earth); voting/candidacy unlock at R-03 with no other requirements.*
2. **Residency Claim**: Declared → Ping-Monitoring → Threshold-Met → Verified → Active → Superseded (relocation) | Lapsed. *Threshold days is an amendable setting; pings encrypted at rest.*
3. **Election**: Prior-Cycle-Certified → Approval-Phase-Open (registration + endorsements + approvals, continuous) → Finalist-Cutoff (top X by approval standing; X = f(seats in race)) → Ranked-Voting-Open (finalists + write-ins) → Voting-Closed → Tabulating (instant) → Certified → [Recount] → Final → next Approval-Phase-Open. *Approval phase spans the entire inter-election period; only finalist cutoff + ranked window are time-boxed.*
4. **Approval Standing**: Candidate-Entered → Accumulating (approvals live, revocable) → Jockeying (continuous re-rank; finalist line displayed) → Frozen-at-Cutoff → Finalist | Non-finalist (write-in eligible). *Individual approvals secret; aggregate standings public in real time — the open-ballot interface.*
5. **Ballot (Ranked)**: Issued (finalist block + write-in field) → Marked → Committed (encrypted + receipt hash) → Counted (write-ins tabulated identically) → Anonymized-Published. *Commitment scheme: secret ballot + voter-verifiable audit; write-in of any validated candidate preserves right to stand.*
6. **Candidacy**: Registered (any time after prior certification) → Validated → In-Approval-Pool → [Endorsed] → Finalist | Non-finalist (write-in eligible) → On-Ranked-Ballot / Written-In → Elected | Defeated | Withdrawn. *Profile auto-attaches public record (legislative votes, civic actions, statements); endorsements from individuals AND orgs displayed; withdrawal allowed until ballot lock.*
7. **Bill**: Introduced → Referred → In-Committee → Reported | Tabled → On-Floor → [Amended] → Passed | Failed → Enacted → Published → [Challenged] → [Edited per Art. IV §5] → Repealed/Superseded. *Bicameral: Passed requires independent agreement of both seat kinds at committee AND floor.*
8. **Motion**: Submitted → Seconded/Recognized → Debated → Voted → Adopted | Failed. *Speaker votes only to break ties.*
9. **Committee Seat**: Allocated (formula) → Preferences-Submitted → Assigned | Tie-Broken → Seated → Vacated → Refilled (whole-legislature RCV, proportion-safe). *Allocation = Total Reps / (Committees × seats per committee); multi-faction & factionless supported.*
10. **Petition**: Created → Gathering → Threshold-Reached → Signature-Audit → Constitutional-Review → Validated → On-Ballot → Adopted | Rejected | Invalidated. *Two kill-paths: failed audit, unconstitutional finding.*
11. **Referendum Question**: Delegated/Queued → Scheduled → Voted → Passed (matching threshold) | Failed → Law → [Modifiable by supermajority same term unless population supermajority] → Ordinary law after general election.
12. **Emergency Powers**: Invoked (supermajority) → Active (duration/area/methods defined) → [Under Judicial Review] → [Renewed ≤ max] → Expired | Struck | Narrowed. *Hard 90-day default max; cannot disrupt civic processes; first order of business each session.*
13. **Vacancy**: Detected → Declared → Countback-Running → Filled | Countback-Failed → Special-Election-Scheduled (90–180d) → Filled. *Applies to legislative, elected executive, and elected judicial seats.*
14. **Case**: Filed → Validated → Panel-Assigned (≥3, odd, severity-scaled) → [Jury-Empaneled] → Scheduled → Hearing → Deliberation → Decided → Opinion-Published → [Appealed] → Closed. *Major constitutional questions: full court.*
15. **Constitutional Challenge**: Filed → Heard (full court if major) → Finding+Remedy-Issued → Legislative-Window-Open → Amended-by-Legislature | Overridden (supermajority in veto window) | Judiciary-Applies-Remedy → Law-Edited → Closed. *Opinions remain commentary on law as written/edited; adjustable settings updated as needed.*
16. **Executive Office**: Delegated (committee) → [Conversion-Voted] → Elected-Office (committee 5+ STV | individual RCV+4 advisors) → [Modified by constituent supermajority] → Dissolved/Reverted. *Terms always equal legislative term.*
17. **Department / Board**: Chartered → Oversight-Assigned → Governors-Nominated → Consented → Operating → [Member Removal Requested → Voted] → Reporting → Re-chartered | Dissolved. *Governors politically neutral; default 10-yr civil-officer terms.*
18. **Organization**: Registered → Active → [Endorsing] → [Co-determination tiers] → [Transfer-Pending → Transferred] → [Converted Public↔Private] → Dissolved. *Worker headcount drives board composition recalcs at 100→2000 scale.*
19. **Jurisdiction**: Boundary-Loaded (dormant) → Critical-Population → Bootstrapping → Self-Governing → [Subdivided] → [In-Union | Intermediary] → [Disintermediated] → [Restoration-Mode (Art. VI)]. *Nesting: every individual associated at all levels simultaneously.*
20. **Federation Peer**: Discovered → Handshake → Trust-Established → Syncing → [Conflict-Resolution] → [Border-Settled] → [Merged/Union] | Departed. *Authoritative instance per jurisdiction; others mirror.*

---

## D) Clocks & Triggers (21) — scheduler spec

| ID | Name | Type | Default | Amendable? | Fires workflow | Basis |
|---|---|---|---|---|---|---|
| CLK-01 | General Election Interval | Recurring interval | 5 years | Yes | WF-ELE-01 / WF-LEG-18 | Art. II §2 |
| CLK-02 | Legislature Meeting Deadline | Rolling deadline | 90 days since last session | Yes | WF-SYS-02 → WF-LEG-05 | Art. II §2 |
| CLK-03 | Emergency Powers Maximum Duration | Countdown | 90 days | Yes | Auto-expiry in WF-LEG-11 | Art. II §7 |
| CLK-04 | Special Election Window | Bounded window | 90–180 days after vacancy | Yes | WF-ELE-04 | Art. II §5 |
| CLK-05 | Residency Verification Threshold | Accumulating threshold | N days of qualifying pings (setting) | Yes | WF-CIV-02 / WF-CIV-03 | Art. I; V §1 |
| CLK-06 | Critical Population Threshold | Population threshold | Setting per jurisdiction tier | Yes | WF-ELE-02 / WF-JUR-01 | Art. II §1 |
| CLK-07 | Legislature Maximum Size | Structural threshold | 9 members | Yes | WF-ELE-06 subdivision | Art. II §2, §8 |
| CLK-08 | Legislature Minimum Size | Structural floor | 5 members | Yes | Seat-count validation (Constitutional Engine) | Art. V §3 |
| CLK-09 | Judicial / Civil Officer Term | Term length | 10 years (appointments) | Yes | WF-JUD-07, WF-EXE-05 renewals | Art. IV §4; III §4 |
| CLK-10 | Term Lockstep | Derived schedule | — | No (structural) | WF-SYS-01 → WF-ELE-01/08/09 | Art. III §3; IV §3 |
| CLK-11 | Judicial Veto Window | Bounded window | Set by judiciary per finding | Per-case | WF-JUD-05 override deadline | Art. IV §5 |
| CLK-12 | Legislative Remedy Timeframe | Bounded window | "Reasonable timeframe" set by judiciary | Per-case | WF-JUD-05 auto-remedy trigger | Art. IV §5 |
| CLK-13 | Co-determination Minimum | Headcount threshold | 100 workers | Yes | WF-ORG-04 first worker seat | Art. III §6 |
| CLK-14 | Co-determination Parity | Headcount threshold | 2,000 workers | Yes | WF-ORG-04 board parity | Art. III §6 |
| CLK-15 | Minimum Judges per Elected Race | Structural floor | 5 | Yes | WF-ELE-09 ballot construction | Art. IV §4 |
| CLK-16 | Case Panel Minimum | Structural floor | 3 judges, odd, severity-scaled | No (hardened) | WF-JUD-03 panel assignment | Art. IV §4 |
| CLK-17 | Petition Signature Threshold | Population % threshold | Setting per jurisdiction | Yes | WF-CIV-06 audit trigger | Art. II §6 |
| CLK-18 | Approval Phase / Registration Window | Continuous window | Opens at prior election certification → closes at finalist cutoff | Structural | WF-CIV-08 open/freeze; WF-CIV-05 | Art. II §2; open-ballot spec |
| CLK-19 | Referendum Act Protection | Term-scoped flag | Same-term supermajority shield; converts to ordinary law after general election | No (hardened) | WF-LEG-19 gate | Art. II §6 |
| CLK-20 | Federation Sync Heartbeat | Recurring interval | Implementation setting | Ops setting | WF-JUR-06 | CGA federation model |
| CLK-21 | Finalist Count per Race | Derived formula | Top X = f(seats in race), e.g. multiplier × seats (setting) | Yes | Finalist cutoff in WF-ELE-01 / WF-CIV-08 | Open-ballot spec; Art. II §2 (right to stand preserved via write-in) |

(Sheet row order places CLK-21 between CLK-18 and CLK-19; renumbered here in ID order.)

---

## E) Special Vote Types (33)

### Simple Majority (3)
| Vote | Threshold | Who votes |
|---|---|---|
| Pass a bill into law | Majority of all serving members | R-09 Legislative Reps |
| Committee vote on a bill | Majority of all committee members | R-11 Committee Members |
| Board of Governors consent | Majority of legislature | R-09 |

### Supermajority (2/3 default) (19)
| Vote | Threshold | Who votes |
|---|---|---|
| Elect Speaker | Supermajority of legislature | R-09 |
| Replace Speaker | Supermajority of legislature | R-09 |
| Create committees | Supermajority of legislature | R-09 |
| Delegate exec authority to committee | Supermajority of legislature | R-09 |
| Delegate exec authority to elected office | Supermajority of legislature (+ supermajority of constituent jurisdictions) | R-09 + constituent legislatures |
| Alter existing executive office | Supermajority of constituent jurisdictions | Constituent jurisdiction legislatures |
| Create appointed judiciary | Supermajority of legislature | R-09 |
| Create elected judiciary | Supermajority of legislature + supermajority of constituent jurisdictions | R-09 + constituent legislatures |
| Delegate to referendum | Supermajority of legislature | R-09 |
| Invoke emergency powers | Supermajority of legislature | R-09 |
| Renew emergency powers | Supermajority of legislature | R-09 |
| Remove officeholder (impeach/expel) | Supermajority of legislature | R-09 |
| Override judiciary constitutional finding | Supermajority of legislature (within veto window) | R-09 |
| Recognize Cultural Institution of State | Supermajority (+ constituent supermajority) | R-09 + constituent legislatures |
| Amend additional constitutional articles | Supermajority of constituent jurisdictions OR supermajority of legislature (if no constituents) | Constituent legislatures or R-09 |
| Modify referendum-passed act (same term) | Supermajority of legislature | R-09 |
| Boundary changes between jurisdictions | Supermajority of affected population | R-04 Voters in affected area |
| Union formation/joining | Supermajority of individuals in applicant + supermajority of constituent jurisdictions in union | R-04 Voters + constituent legislatures |
| Union departure | Supermajority of individuals + supermajority of constituent jurisdictions | R-04 Voters + constituent legislatures |

### Population-Level (Referendum) (3)
| Vote | Threshold | Who votes |
|---|---|---|
| Referendum — simple majority issue | Majority of population | R-04 Voters |
| Referendum — supermajority issue | Supermajority of population | R-04 Voters |
| Citizen petition initiative | Majority or supermajority of population (matching legislative equivalent) | R-04 Voters |

### Bicameral Special (1)
| Vote | Threshold | Who votes |
|---|---|---|
| Any act in bicameral legislature | Both kinds of members (population-proportional AND equal-apportionment) must agree independently | R-09 Reps of both kinds |

### Ranked Choice / STV Elections (7)
| Vote | Method/Threshold | Who votes |
|---|---|---|
| General legislative election | STV/Droop quota — 5 to 9 seats | R-04 Voters |
| Executive committee election (elected type) | PR-STV/Droop — 5+ seats | R-04 Voters |
| Individual executive election | Single-winner RCV (top 4 runners-up become advisors) | R-04 Voters |
| Judicial election (elected type) | STV/Droop — in groups, min 5 | R-04 Voters |
| Committee chair election | Single-winner RCV by whole legislature | R-09 |
| Speaker election | Supermajority RCV by whole legislature | R-09 |
| Committee preference assignment | Ranked preferences (modified multi-faction algorithm) | R-09 |

---

## F) Workflow Inventory (80)

### A. Civic / Individual (8)
| ID | Name | Institutions | Trigger → Outcome |
|---|---|---|---|
| WF-CIV-01 | Onboarding & Identity Verification | I-JUR (context) | App download/site visit → verified individual record, eligible to declare residency |
| WF-CIV-02 | Residency Establishment | I-JUR (all nesting levels) | Residency Declaration filed → jurisdictional association at every level; voting & candidacy unlocked |
| WF-CIV-03 | Relocation & Re-association | I-JUR (old + new) | Sustained pings outside current jurisdiction → association transferred, old roles gracefully expired, federation notified |
| WF-CIV-04 | Ranked Ballot Cast & Verify | I-ELE, I-ELB | Finalist cutoff/ranked window opens → encrypted ranked ballot committed; receipt hash; anonymized hash published for self-audit |
| WF-CIV-05 | Candidacy Lifecycle | I-ELE, I-ELB, I-ORG | Prior election certified (registration opens) → candidate in approval pool; top X advance as finalists; non-finalists write-in eligible; or withdrawn |
| WF-CIV-06 | Petition Lifecycle (Law by Petition) | I-ELB, I-JUD, I-ELE | Petition Creation filed → initiative on next jurisdiction-wide ballot, or invalidated (unconstitutional / failed audit) |
| WF-CIV-07 | Advocate Registration | I-JUD | Judiciary operational, R-03 applies → registered advocate eligible for case representation |
| WF-CIV-08 | Open Ballot — Approval Phase & Candidate Discovery | I-ELE, I-ELB, I-ORG | Prior election certified (auto-opens) → live filterable candidate marketplace with revocable approvals, live standings, finalist line; freezes at cutoff |

### B. Electoral (10)
| ID | Name | Institutions | Trigger → Outcome |
|---|---|---|---|
| WF-ELE-01 | General Election Cycle | I-ELB, I-ELE, I-LEG | Prior certification + term-sync clock → approval phase → finalist cutoff (X=f(seats)) → ranked voting (write-ins) → instant STV/Droop → certification; winners auto-seated; prior referendum acts become ordinary law |
| WF-ELE-02 | Bootstrap First Election | I-JUR, bootstrap I-ELB, I-ELE | Critical population of verified residents → first legislature certified; bootstrap board flagged for replacement |
| WF-ELE-03 | Vacancy Countback | I-ELB, I-ELE (prior) | Vacancy declared → replacement winner from prior ballots (vacated member removed), or countback-failure flag |
| WF-ELE-04 | Special Election | I-ELB, I-ELE | Countback fails → vacancy filled by special election within 90–180-day constitutional window |
| WF-ELE-05 | Recount / Audit | I-ELB, I-ELE | Cause shown post-certification or board motion → confirmed/corrected results; public chain-of-custody record |
| WF-ELE-06 | Subdivision & Boundary Drawing | I-ELB, I-JUR, I-LEG | Apportionment would exceed max seats (9) → contiguous equal subdivisions, uniform rep:population ratio; next election per-district |
| WF-ELE-07 | Referendum Execution | I-ELE, I-ELB, I-LEG | Delegation act passes OR validated petition queued → question resolved at matching threshold; result entered as law |
| WF-ELE-08 | Executive Election | I-ELE, I-EEO | Elected exec office exists + term expires (or newly created) → committee: 5+ via PR-STV; individual: RCV winner + top 4 advisors |
| WF-ELE-09 | Judicial Election | I-ELE, I-JDE | Elected judiciary term expires → judges elected in groups via STV (min 5/race) |
| WF-ELE-10 | Election Board Constitution | I-LEG, I-ELB | Bootstrap or Election Board Creation Act → independent board seated; bootstrap board retired |

### C. Legislative (20)
| ID | Name | Institutions | Trigger → Outcome |
|---|---|---|---|
| WF-LEG-01 | Legislature Constitution | I-LEG, I-SPK, I-ADM, I-ELB | Election certified → fully organized legislature: seated, speaker, rules/ethics, admin office, election board, committees |
| WF-LEG-02 | Speaker Election / Replacement | I-LEG, I-SPK | First meeting after election or motion to replace → neutral Speaker by supermajority RCV |
| WF-LEG-03 | Committee Establishment & Seat Assignment | I-LEG, I-COM | Supermajority creation act(s) → committees seated via modified multi-faction preference algorithm (allocation = Total Reps / (Committees × seats per committee); ties by prior-election 1st-choice then subsequent ranks) |
| WF-LEG-04 | Committee Chair & Alternate Election | I-LEG, I-COM | Committee fully seated → chair + alternate(s) via whole-legislature RCV |
| WF-LEG-05 | Regular Session (Daily Order of Business) | I-LEG, I-SPK, I-ADM | Speaker calls session (≤90-day clock) → quorum verified → emergency powers → constitutional matters → agenda → minutes published |
| WF-LEG-06 | Bill Lifecycle (Unicameral) | I-LEG, I-COM | Bill Introduction → enacted law (peg-quorum majority of all serving members), versioned + published; or failed/tabled |
| WF-LEG-07 | Bicameral Dual-Agreement Bill Lifecycle | I-LEG (combined), I-COM | Bill introduced where constituent jurisdictions exist → passes only when both seat kinds independently agree in committee AND on floor |
| WF-LEG-08 | Committee Hearing | I-COM | Chair calls meeting / bill referred → committee action: report, amend, refer to floor, or table; record published |
| WF-LEG-09 | Motion Handling | I-LEG | Motion submitted in session → resolved per Rules of Order; Speaker breaks ties only |
| WF-LEG-10 | Referendum Delegation | I-LEG → I-ELE | Supermajority delegation act → question queued to jurisdiction-wide ballot at matching threshold |
| WF-LEG-11 | Emergency Powers Lifecycle | I-LEG, I-JUD | Disaster/invasion + supermajority → powers with defined duration/area/methods; judicially reviewable; cannot disrupt civic processes; auto-expire |
| WF-LEG-12 | Legislative Vacancy Handling | I-LEG, I-ELB | Death/resignation/removal/relocation → countback, else special election 90–180 days; committee proportionality re-checked |
| WF-LEG-13 | Committee Vacancy / New Committee Fill | I-LEG, I-COM | Mid-term vacancy or new committee → seat filled by whole-legislature RCV without violating factional proportions |
| WF-LEG-14 | Amendable Setting Change | I-LEG | Bill targeting constitutional_settings key → setting updated within hardened min/max, linked to enacting act with effective date; out-of-range rejected |
| WF-LEG-15 | Rules of Order & Ethics Adoption | I-LEG, I-ADM | First sessions → binding rules + ethics code for all elected officials and civil officers |
| WF-LEG-16 | Misconduct Investigation (Admin Office) | I-ADM, I-LEG | Complaint or own motion → findings published; referral to impeachment/censure/expulsion or closure |
| WF-LEG-17 | Impeachment / Censure / Expulsion | I-LEG, I-SPK, I-ADM | Referral or member motion → supermajority removal (or censure); Speaker presides except own case; vacancy triggers on expulsion |
| WF-LEG-18 | Term Expiration & Renewal | I-LEG, I-ELB | Term-sync clock → new general election; lockstep renewal of legislature + elected executives + elected judges; referendum acts become ordinary law |
| WF-LEG-19 | Modify / Repeal Referendum Act | I-LEG | Supermajority motion same term → act modified/repealed unless passed by population supermajority; post-election it is ordinary law |
| WF-LEG-20 | Quorum Failure & Attendance Compulsion | I-LEG, I-SPK | Session below majority of serving members → Speaker compels attendance; session proceeds or adjourns; 90-day clock still enforced |

### D. Executive (9)
| ID | Name | Institutions | Trigger → Outcome |
|---|---|---|---|
| WF-EXE-01 | Executive Committee Delegation | I-LEG, I-EXC | Supermajority delegation act → exec committee composed proportionally from legislature (same method as committees) |
| WF-EXE-02 | Conversion to Elected Executive Office | I-LEG, I-EXC→I-EEO, I-ELE | Supermajority (+constituent supermajority) → elected office created (committee 5+ PR-STV or individual RCV+4 advisors); terms locked to legislative term |
| WF-EXE-03 | Executive Office Modification | I-EEO, constituent I-LEGs | Proposal to alter office → altered only on supermajority of constituent jurisdictions |
| WF-EXE-04 | Department Creation | I-LEG, I-DEP | Department Creation Act → department with charter + oversight (Chief Exec, Treasury, Defense, State, Justice, custom) |
| WF-EXE-05 | Board of Governors Appointment | I-DEP, I-BOG, I-LEG | Unfilled board seats → nomination + consent (peg-quorum majority); neutral expert governors, 10-yr civil-officer terms |
| WF-EXE-06 | Governor Removal | I-BOG, I-LEG | Executive's good-faith competence/ethics finding → governor removed + replacement triggered, or request fails |
| WF-EXE-07 | Executive Order / Policy Proposal | I-EXC/I-EEO, I-DEP | Executive initiative → order within delegated scope (engine-validated); policy proposals to boards/legislature; judicially reviewable |
| WF-EXE-08 | Investigation Order | I-DEP, I-BOG | Oversight concern → investigation record; findings can feed removal or legislation |
| WF-EXE-09 | Department Reporting Cycle | I-BOG, I-DEP, I-LEG | Charter-defined interval → implementation rules + public reports filed to executive and legislature |

### E. Judicial (9)
| ID | Name | Institutions | Trigger → Outcome |
|---|---|---|---|
| WF-JUD-01 | Appointed Judiciary Creation & Confirmation | I-LEG, I-JUD | Supermajority Judiciary Creation Act → court with defined jurisdiction/scope; constituents nominate equal numbers (judicial committee if none); legislature confirms; 10-yr defaults |
| WF-JUD-02 | Conversion to Elected Judiciary | I-JUD→I-JDE, I-ELE | Supermajority of legislature + constituent supermajority → judges thereafter elected in groups via STV (min 5/race); terms synced |
| WF-JUD-03 | Case Lifecycle | I-JUD/JDE | Case filing → panel ≥3 and odd, severity-scaled (full court for major constitutional questions); jury where entitled; published opinion |
| WF-JUD-04 | Jury Paneling | I-JUD | Case type entitles jury → jury of peers seated with conflict screening; service protected as civic obligation |
| WF-JUD-05 | Constitutional Challenge & Law Remedy (Art. IV §5) | I-JUD, I-LEG | Challenge filed by any inhabitant → finding + remedy → legislature modifies in timeframe OR supermajority override in veto window OR judiciary edits law directly; executives enforce |
| WF-JUD-06 | Emergency Powers Judicial Review | I-JUD, I-LEG | Challenge or automatic review → powers upheld, narrowed, or struck; civic-process protections enforced |
| WF-JUD-07 | Judicial Vacancy Handling | I-JUD/JDE | Judge seat vacated → appointed: re-run nomination+confirmation; elected: countback → special election |
| WF-JUD-08 | Judge Removal | I-LEG, I-JUD | Misconduct/competence proceeding → removed by supermajority (removal parity with legislators); vacancy workflow triggered |
| WF-JUD-09 | Petition Constitutionality Review | I-JUD, I-ELB | Petition reaches signature threshold → validated for ballot or invalidated as unconstitutional |

### F. Organizational (10)
| ID | Name | Institutions | Trigger → Outcome |
|---|---|---|---|
| WF-ORG-01 | Organization Registration | I-ORG, I-JUR | R-03 files registration → registered entity (stock, partnership, equal partnership, member-owned, worker-owned, nonprofit) |
| WF-ORG-02 | Candidate Endorsement | I-ORG, I-ELE | Candidate requests / org offers → endorsement recorded; candidate gains R-07; faction linkage used for proportionality math |
| WF-ORG-03 | Membership / Worker Joining | I-ORG | Individual applies, org accepts → membership/shareholding or worker contract recorded; headcount updated (feeds co-determination) |
| WF-ORG-04 | Co-determination Scaling Event | I-ORG/I-CGC/I-DEP boards | Headcount crosses 100 / interpolation points / 2000 parity → worker board seats added on uniform scale; joint chair election at composition change |
| WF-ORG-05 | Board Elections (Owner & Worker Tracks) | I-ORG/I-CGC | Board terms expire or composition changes → board seated per current co-determination ratio; chair elected jointly by entire board |
| WF-ORG-06 | Ownership Transfer (Mutual) | I-ORG | Both entities agree → ownership transferred with full-faith-and-credit record sync across jurisdictions |
| WF-ORG-07 | Monopoly / Open-Market Acquisition | I-LEG, I-ORG→I-CGC | Legislative monopoly finding or entity for sale → conversion to CGC with ≥ fair-market compensation; prior board may join founding Board of Governors |
| WF-ORG-08 | Common Good Corporation Creation | I-LEG, I-CGC, I-EXC/I-EEO | CGC Creation Act → public enterprise with charter + executive oversight; regulated identically to private peers; IP perpetually public domain |
| WF-ORG-09 | CGC Reorganization / Sale / Dissolution | I-LEG, I-CGC | Legislative act → CGC reorganized, dissolved, or sold to private enterprise (public→private conversion) |
| WF-ORG-10 | Private Organization Dissolution | I-ORG, I-JUD | Owner/member decision or judicial order → entity wound down; obligations settled; records archived |

### G. Jurisdictional & Federation (9)
| ID | Name | Institutions | Trigger → Outcome |
|---|---|---|---|
| WF-JUR-01 | Jurisdiction Bootstrap | I-JUR → full stack | Critical verified population in loaded boundary → live self-governing jurisdiction; full bootstrap sequence executes |
| WF-JUR-02 | Union Formation | I-JUR ×N → new encompassing I-JUR | 2+ independent jurisdictions initiate → new encompassing jurisdiction with codified amendable variables + added articles; instances federate |
| WF-JUR-03 | Join Existing Union | I-JUR (union + applicant) | Applicant aligns institutions → admission on supermajority of applicant individuals + supermajority of union constituents; exit mirrors |
| WF-JUR-04 | Disintermediation | Intermediary I-JUR | All constituents + encompassing agree → intermediary dissolves; its acts incorporated into former constituents; federation topology updated |
| WF-JUR-05 | Peer Discovery & Border Settlement | Federation layer, I-JURs | Two instances both claiming most-encompassing discover each other → recognized peers with settled borders; optional sync or union path |
| WF-JUR-06 | Full Faith & Credit Record Sync | Federation layer | Any cross-jurisdiction record event → authoritative-instance writes propagated; conflicts resolved by authority claims; audit-chained |
| WF-JUR-07 | Constitutional Restoration (Art. VI) | I-JUR stack, I-ELB | Government countermanded/captured/destroyed → Tier 1: constituents elect new legislature; Tier 2: encompassing calls elections; Tier 3: individuals self-organize per Template |
| WF-JUR-08 | Authoritative Instance Migration | Federation layer | Jurisdiction stands up its own server → partition export → authority transfer → re-peer; local instance becomes authoritative |
| WF-JUR-09 | Population Records & Apportionment | I-JUR, I-ELB, I-LEG | Residency changes counts; pre-election apportionment run → accurate counts; seats/taxes/resources apportioned; subdivision triggered if max exceeded |

### H. System / Cross-Cutting (5)
| ID | Name | Institutions | Trigger → Outcome |
|---|---|---|---|
| WF-SYS-01 | Term Synchronization Engine | All institutions | Any term-bearing role created → legislative/executive/judicial terms expire in lockstep; election triggers derived from one clock |
| WF-SYS-02 | 90-Day Meeting Enforcement | I-LEG, I-SPK | Clock since last session → session scheduled or attendance compelled before deadline; violation flagged to admin office |
| WF-SYS-03 | Public Records Publication | I-LEG, I-COM, I-JUD, I-ELB | Any statement, bill, vote, or explanation recorded → public, readily available, immutable record with translations |
| WF-SYS-04 | Constitutional Validation & Audit Chain | All modules | Every state transition → hardened-rule check pre-commit; invalid transitions rejected; crypto-chained tamper-evident history |
| WF-SYS-05 | Constitutional Amendment (Art. VII) | I-LEG stack | Amendment proposal meeting Art. VII → amendable variables via settings; hardened-layer changes require code release passing full constitutional test suite |

---

## Cross-Reference Inconsistencies (stale form-ID references in CGA_Workflows_Catalog.xlsx vs. the Forms Catalog — flag for the architecture plan)

The Workflow Inventory references several form IDs that are off-by-one or use older prefixes relative to the canonical "3. Forms Catalog":

1. **F-ELB-007** — referenced by F-LEG-036 ("triggers countback (F-ELB-007)") but does not exist in the catalog (ELB stops at 006). Countback is engine-driven (WF-ELE-03).
2. **WF-ELE-03 / WF-LEG-12 cite "F-LEG-030 (vacancy declaration)"** — catalog's F-LEG-030 is Disintermediation Vote; Vacancy Declaration is **F-LEG-036**.
3. **WF-LEG-10 cites "F-LEG-022" for Referendum Delegation** — catalog's referendum delegation is **F-LEG-023** (022 is Removal/Impeachment).
4. **WF-LEG-11 cites F-LEG-023 (invoke) / F-LEG-024 (renew)** — catalog: invoke = **F-LEG-024**, renew = **F-LEG-025**.
5. **WF-LEG-17 / WF-JUD-08 cite "F-LEG-034 (removal vote)"** — catalog's removal vote is **F-LEG-022** (034 is Referendum Act Modification).
6. **WF-JUD-05 cites "F-IND-013" for constitutional challenge** — catalog: challenge filing is **F-IND-016** (013 is Org Membership Application).
7. **Prefix drift**: workflows reference **F-COM-001..004** (catalog uses **F-CHR-001..004**) and **F-GOV-001/002** (catalog uses **F-BOG-001/002**).
8. **WF-CIV-07 labels Advocate as "R-22"** — Roles sheet: Advocate = **R-21**, Juror = R-22.

Treat the Forms Catalog and Roles sheets as authoritative for IDs; the Workflows Catalog is authoritative for sequencing/triggers.