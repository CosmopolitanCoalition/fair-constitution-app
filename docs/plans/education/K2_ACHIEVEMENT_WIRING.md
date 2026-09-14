# K2_ACHIEVEMENT_WIRING — the catalog-to-code map (AC-1)

Source doc. Records, for every PERSONAL `ACH-*` catalog entry
(`app/Domain/Achievements/AchievementCatalog.php`), the code site that proves
its trigger and awards it through the single writer
(`App\Services\AchievementService`: `awardSelf` / `awardSubject` /
`awardState`). Built for punch-list item AC-1
(operator ruling 2026-09-13, achievement-wiring-scope = A: personal keys only;
`ACH-JUR-*` / `ACH-SYS-*` Plane B is a tracked follow-up, not built now).

RULES THIS WIRING HOLDS

- One writer. No code inserts into `achievements` directly.
- Correct earner (the `ACH-CAN-005` lesson): SELF = the filer, SUBJECT =
  resolved from the record the handler wrote, STATE = the holder a fact row
  names. `awardState`/`awardSubject`/`awardSelf` refuse a mis-moded key.
- No award on rejected/rolled-back: SELF/SUBJECT awards run inside the engine
  transaction (a `ConstitutionalViolation` rolls the award back with the
  mutation). STATE awards read only COMMITTED, settled fact rows, so an
  uncommitted seat leaves nothing to sweep.
- Award once: idempotent on `(user_id, award_key)`; safe to call every time.

Legend: `wired at <file>` — an award call at that site. `sweep` —
`achievements:sweep` (`AchievementStateSweep`) reads the fact table.
`write-site` — an immediate `awardState` at the seat-mint service in addition
to the sweep. `deferred` — a real trigger exists but its earner is not
resolvable by a direct column or one member→user join this pass (tracked
follow-up). `awaiting_ui` — no reachable screen fires it yet (flag stays true).

## EARNER_SELF (awarded inside the form handler, engine-transaction-coupled)

| Key | Site |
|---|---|
| ACH-CIV-001 | wired at app/Domain/Forms/Handlers/IndividualRegistration.php (awards the created user) |
| ACH-CIV-002 | wired at app/Domain/Forms/Handlers/ProfileManagement.php |
| ACH-CIV-003 | wired at app/Domain/Forms/Handlers/ResidencyDeclaration.php |
| ACH-CIV-004 | wired at app/Domain/Forms/Handlers/GpsResidencyPing.php |
| ACH-VOX-001 | wired at app/Domain/Forms/Handlers/PetitionSignature.php (sign only, not revoke) |
| ACH-VOX-002 | wired at app/Domain/Forms/Handlers/PetitionCreation.php |
| ACH-VOX-005 | wired at app/Domain/Forms/Handlers/SocialThreadPost.php |
| ACH-VOX-006 | wired at app/Domain/Forms/Handlers/SocialTestimonyFiling.php |
| ACH-VOX-007 | wired at app/Domain/Forms/Handlers/ConstitutionalChallengeFiling.php |
| ACH-VOT-005 | deferred — receipt verification is a READ (no state change, no write handler); no award site exists. `awaiting_ui` is false in the catalog but there is no filing to hook; left unwired, tracked follow-up. |
| ACH-CAN-001 | wired at app/Domain/Forms/Handlers/CandidacyRegistration.php |
| ACH-CAN-002 | wired at app/Domain/Forms/Handlers/CampaignProfileSetup.php |
| ACH-CAN-003 | wired at app/Domain/Forms/Handlers/EndorsementRequest.php |
| ACH-LEG-001 | wired at app/Domain/Forms/Handlers/OathOfOffice.php |
| ACH-LEG-002 | wired at app/Domain/Forms/Handlers/AttendanceRegistration.php |
| ACH-LEG-003 | wired at app/Domain/Forms/Handlers/BillIntroduction.php |
| ACH-LEG-004 | wired at app/Domain/Forms/Handlers/FloorVoteCast.php |
| ACH-LEG-006 | wired at app/Domain/Forms/Handlers/MotionSubmission.php |
| ACH-LEG-007 | wired at app/Domain/Forms/Handlers/PublicRecordStatement.php |
| ACH-LEG-008 | wired at app/Domain/Forms/Handlers/CommitteePreferenceRanking.php |
| ACH-LEG-010 | wired at app/Domain/Forms/Handlers/CommitteeVoteCast.php |
| ACH-LEG-014 | wired at app/Domain/Forms/Handlers/TieBreakingVote.php |
| ACH-LEG-015 | wired at app/Domain/Forms/Handlers/FloorVoteCast.php (only when `ChamberVote::threshold_basis === BASIS_SUPERMAJORITY`) |
| ACH-EXE-005 | wired at app/Domain/Forms/Handlers/ExecutiveOrder.php |
| ACH-EXE-006 | wired at app/Domain/Forms/Handlers/BoardGovernorNomination.php |
| ACH-BOG-002 | wired at app/Domain/Forms/Handlers/DepartmentRuleImplementation.php |
| ACH-BOG-003 | wired at app/Domain/Forms/Handlers/DepartmentReportFiling.php |
| ACH-BOG-006 | wired at app/Domain/Forms/Handlers/SessionMinutesPublication.php |
| ACH-JUD-001 | wired at app/Domain/Forms/Handlers/AdvocateRegistration.php |
| ACH-JUD-002 | wired at app/Domain/Forms/Handlers/CaseFiling.php AND app/Domain/Forms/Handlers/AdvocateCaseFiling.php |
| ACH-JUD-003 | wired at app/Domain/Forms/Handlers/MotionFiling.php |
| ACH-JUD-004 | wired at app/Domain/Forms/Handlers/EvidenceSubmission.php |
| ACH-JUD-005 | wired at app/Domain/Forms/Handlers/BriefFiling.php |
| ACH-JUD-009 | wired at app/Domain/Forms/Handlers/CaseAcceptanceAndPanelAssignment.php (acceptance path) |
| ACH-JUD-010 | wired at app/Domain/Forms/Handlers/OpinionRulingFiling.php |
| ACH-JUD-011 | wired at app/Domain/Forms/Handlers/ConstitutionalFinding.php |
| ACH-JUD-012 | wired at app/Domain/Forms/Handlers/JudicialRemedyApplication.php |
| ACH-ORG-001 | wired at app/Domain/Forms/Handlers/OrganizationRegistration.php |
| ACH-ORG-005 | wired at app/Domain/Forms/Handlers/CandidateEndorsementGrant.php (grant path, the org agent) |
| ACH-ORG-006 | wired at app/Domain/Forms/Handlers/BoardElectionAdministration.php |
| ACH-ORG-007 | wired at app/Domain/Forms/Handlers/WorkerBoardElectionAdministration.php |
| ACH-ORG-011 | wired at app/Domain/Forms/Handlers/OwnershipTransferInitiation.php |
| ACH-ORG-012 | wired at app/Domain/Forms/Handlers/PublicPrivateConversionRequest.php |
| ACH-ORG-013 | wired at app/Services/Organizations/CgcIpRegisterService.php::dedicate (the dedicator) |
| ACH-ELB-002 | wired at app/Domain/Forms/Handlers/ElectionSchedulingOrder.php |
| ACH-ELB-003 | wired at app/Domain/Forms/Handlers/ElectionResultsCertification.php |
| ACH-ELB-004 | wired at app/Domain/Forms/Handlers/ManualDistrictDraw.php |
| ACH-ELB-005 | wired at app/Domain/Forms/Handlers/RecountAuditOrder.php |
| ACH-EDU-001 | wired at app/Domain/Forms/Handlers/TrainingCompletion.php (pre-existing, unchanged) |
| tour arcs (13) | wired at app/Services/JourneyService.php (pre-existing, unchanged) |

## EARNER_SUBJECT (awarded to the record's subject, at the action site)

| Key | Site |
|---|---|
| ACH-VOX-003 | wired at app/Domain/Forms/Handlers/PetitionSignature.php — when the signature carries the petition to `STATUS_THRESHOLD_REACHED`, the petition CREATOR (`petitions.creator_user_id`) earns |
| ACH-VOX-004 | wired at app/Services/ReferendumService.php (queue-from-petition) — the petition CREATOR earns when the petition reaches the ballot |
| ACH-CAN-004 | wired at app/Domain/Forms/Handlers/CandidateEndorsementGrant.php (grant path) — the endorsed CANDIDATE (`candidacies.user_id`) earns |
| ACH-CAN-005 | wired at app/Domain/Forms/Handlers/CandidateValidation.php (validate path) — the validated CANDIDATE earns |
| ACH-LEG-005 | deferred — a passed bill's introducer earns when the law version is minted. Enactment is a system step (EnactmentService); the introducer resolves via `bills.sponsor_member_id → legislature_members.user_id` at the SOURCE_ENACTMENT write. No clean single action-site with the introducer in hand this pass. Tracked follow-up. |
| ACH-ECO-003 | awaiting_ui — budget author; no economy screens exist yet (flag stays true). |

## EARNER_STATE (achievements:sweep + write-site immediacy where the seat is minted)

Wired in `AchievementStateSweep::KEYS`, read from the fact tables by keyset,
awarded to the holder each row names.

Cursor semantics: the sweep pages `id > cursor ORDER BY id LIMIT chunk` and
commits the cursor per chunk, so a run KILLED mid-scan resumes from its last
committed chunk. On NORMAL completion of a key the cursor is CLEARED
(`runKey` saves null), so the next scheduled run rescans every fact row from
the start. The keyset id is a random `gen_random_uuid()`, so a persisted
high-water id would skip any holder created afterward whose id sorts below it;
clearing on completion forces the full rescan. The rescan is safe because
`AchievementService::awardState` is idempotent (`hasEarned` short-circuits
before the audit append; `insertOrIgnore` on the write).

| Key | Fact source | Immediate write-site |
|---|---|---|
| ACH-CIV-005 | residency_confirmations (is_active) | — |
| ACH-VOT-001 | ballot_envelopes (kind=ranked) | — |
| ACH-VOT-002 | ballot_envelopes (kind=referendum) | — |
| ACH-LEG-009 | committee_seats (seated) → legislature_members.user_id | app/Services/Legislature/CommitteeAssignmentService.php |
| ACH-LEG-011 | committees.chair_member_id → legislature_members.user_id | — |
| ACH-LEG-012 | committees.alternate_member_id → legislature_members.user_id | — |
| ACH-LEG-013 | legislature_members (is_speaker, seated) | — |
| ACH-EXE-001 | executive_members (principal, delegated_proportional, seated) | — |
| ACH-EXE-002 | executive_members (principal, elected_stv, seated) | — |
| ACH-EXE-003 | executive_members (principal, elected_rcv, seated) | — |
| ACH-EXE-004 | executive_members (advisor, seated) | — |
| ACH-BOG-001 | board_seats (seated) on a department board (boards.boardable_type=departments) | — |
| ACH-JUD-006 | jury_members | — |
| ACH-JUD-007 | jury_members (screening_status=empaneled) | — |
| ACH-JUD-008 | judicial_seats (seated) | app/Services/Judiciary/JudicialSeatService.php |
| ACH-ORG-002 | organizations.agent_user_id | — |
| ACH-ORG-003 | org_memberships (active) | — |
| ACH-ORG-004 | org_workers (active) | — |
| ACH-ORG-008 | board_seats (owner_elected, seated) | — |
| ACH-ORG-009 | board_seats (worker_elected, seated) | — |
| ACH-ORG-010 | board_seats (is_chair, seated) | — |
| ACH-ELB-001 | election_board_members | — |

STATE deferred (real trigger, but earner not resolvable by a direct column or
one member→user join this pass — tracked follow-up):

| Key | Why deferred |
|---|---|
| ACH-CIV-006 | "association chain depth" — ambiguous threshold; needs a defined depth rule over `residency_confirmations.depth`. |
| ACH-CIV-007 | "second confirmed association after a lapse" — needs confirmation history/lapse semantics. |
| ACH-VOT-003 | envelope × race jurisdiction depth ≥ 3 — cross-table depth join. |
| ACH-VOT-004 | every race on one election day — cross-race day-coverage aggregate. |
| ACH-CAN-006 | seated with zero active endorsements — join + negation over endorsements. |
| ACH-BOG-004 | appointments + active term (civil_officer) — appointable_type/office-class semantics. |
| ACH-BOG-005 | appointments on admin_offices — appointable_type/office-class semantics. |
| ACH-FED-001 | peer record — federation_peers carries no user_id (no personal holder). |
| ACH-FED-002 | operator record — no user_id link on the source. |
| ACH-ECO-001 | awaiting_ui — tax filing existence; no economy screens exist yet (flag stays true). |
| ACH-ECO-002 | awaiting_ui — budget-act floor vote (EARNER_SELF); no economy screens yet (flag stays true). |
| ACH-ECO-004 | awaiting_ui — assistance responder; no economy screens yet (flag stays true). |
| ACH-ECO-005 | awaiting_ui — marketplace listing; no economy screens yet (flag stays true). |
| ACH-ECO-006 | awaiting_ui — marketplace order settled; no economy screens yet (flag stays true). |
| ACH-ECO-007 | awaiting_ui — work posting; no economy screens yet (flag stays true). |
| ACH-ECO-008 | awaiting_ui — joint ledger party; no economy screens yet (flag stays true). |

## Plane B — tracked follow-up, NOT built this pass

`ACH-JUR-001..026` and `ACH-SYS-001..009` publish through
`PublicRecordService` with no `user_id`. They do not surface on a person
profile and there is no publication path built. Out of AC-1 scope
(achievement-wiring-scope = A). No catalog flags changed for these.

## Demo mode

`achievements` is NOT in `DemoMode::CAPTURE_EXCLUDED` (verified
`app/Support/DemoMode.php`), per the ruling: a demo-session award is captured
by the `cga_demo_capture` trigger and voided with the session like any other
demo write. `AchievementService::awardAs`'s audit seal rides the normal
engine/demo path.

Void of a captured achievement (RESOLVED): the append-only
`achievements_immutable` trigger runs `achievements_block_mutation()` BEFORE
every UPDATE/DELETE and raised unconditionally, so the demo purge's soft delete
(`DemoSessionService::reverse` runs `UPDATE ... SET deleted_at = now()` on the
captured INSERT) was caught and recorded `skipped`, leaving a demo medal
permanent. Migration
`2026_09_13_190000_achievements_demo_void_soft_delete.php` redefines the guard
to permit EXACTLY ONE case: an UPDATE that stamps `deleted_at` (NULL → value)
with every other column unchanged
(`to_jsonb(NEW) - 'deleted_at' = to_jsonb(OLD) - 'deleted_at'`), while the
transaction-local demo-void GUC `DemoMode::VOID_GUC` (`cga.demo_void`) is `'1'`.
`DemoSessionService::reverse` sets that GUC around the soft delete only. Every
other UPDATE, every DELETE, and TRUNCATE stay blocked — the ledger is
append-only for all non-demo mutation. The migration is write-only this lane;
the desk applies it after merge.

## Catalog `awaiting_ui` flags

No flag was flipped this pass. The only `awaiting_ui = true` keys are the
economy set (`ACH-ECO-001..008`) and the Plane B economy jurisdiction
milestones (`ACH-JUR-023..026`); none were wired (no economy screens; Plane B
out of scope), so every flag correctly stays true. Flip a flag only after its
trigger is wired and tested.
