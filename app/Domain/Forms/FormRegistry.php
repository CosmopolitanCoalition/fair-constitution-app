<?php

namespace App\Domain\Forms;

use InvalidArgumentException;

/**
 * Canonical registry of the 103 constitutional forms.
 *
 * Source of truth: CGA_Constitutional_Roles_Forms_Chart.xlsx sheet
 * "3. Forms Catalog" (transcribed in docs/plans/institutions/
 * EXPLORE_registry.md §B), counts: IND 17 · CAN 3 · ORG 7 · ELB 6 ·
 * LEG 36 · SPK 9 · CHR 4 · EXE 5 · BOG 2 · JDG 10 · ADV 4 = 103.
 *
 * The registry is a constitutional artifact versioned with code (like the
 * hardened rules) — a plain PHP array, never a DB table. `audit_log.ref`
 * always stores the canonical ID; aliases are resolved on the way in.
 *
 * ALIAS RESOLUTION (mockups/MANIFEST.md §1). Two distinct classes:
 *
 * 1. PURE ALIASES — IDs that do not exist in the canonical catalog at all
 *    (prefix drift in the Workflows Catalog). These resolve automatically
 *    in canonical(): F-COM-001..004 → F-CHR-001..004, F-GOV-001/002 →
 *    F-BOG-001/002.
 *
 * 2. CATALOG DRIFT — stale references where the drifted ID is ITSELF a
 *    different canonical form (e.g. workflows cite "F-LEG-034" for the
 *    removal vote, which is canonically F-LEG-022, while F-LEG-034 is
 *    canonically Referendum Act Modification). These can NEVER be
 *    auto-rewritten: canonical() must not silently turn a valid filing of
 *    one form into a filing of another (resolving the chain F-LEG-022 →
 *    023 → 024 → 025 would collapse four distinct forms into one). They
 *    are recorded in CATALOG_DRIFT, scoped to where the Workflows Catalog
 *    uses them, and surfaced via meta()['catalog_drift'] for display —
 *    matching the mockups' "form name first, ID second" rule so drift can
 *    never mislead a reader.
 *
 * canonical() therefore resolves: canonical ID → itself; pure alias →
 * canonical target; anything else → InvalidArgumentException.
 */
class FormRegistry
{
    /**
     * All 103 canonical forms: id => [name, roles allowed to file].
     * Roles per the catalog's "Filed by" column; 'roles' lists the role
     * codes whose holders may file (any one suffices). F-IND-006 is
     * additionally system-filed (see its handler's systemOnly()).
     */
    public const FORMS = [
        // ── F-IND — Individual Forms (17) ───────────────────────────────────
        'F-IND-001' => ['name' => 'Individual Registration',                    'roles' => ['R-01']],
        'F-IND-002' => ['name' => 'Profile Management',                         'roles' => ['R-01']],
        'F-IND-003' => ['name' => 'Residency Declaration',                      'roles' => ['R-01']],
        'F-IND-004' => ['name' => 'Identity Verification Submission',           'roles' => ['R-01']],
        'F-IND-005' => ['name' => 'GPS Residency Ping',                         'roles' => ['R-01']],
        'F-IND-006' => ['name' => 'Residency Verification Confirmation',        'roles' => ['R-02']],
        'F-IND-007' => ['name' => 'Ballot Submission (Ranked Choice)',          'roles' => ['R-04']],
        'F-IND-008' => ['name' => 'Referendum Vote',                            'roles' => ['R-04']],
        'F-IND-009' => ['name' => 'Petition Creation',                          'roles' => ['R-05']],
        'F-IND-010' => ['name' => 'Petition Signature',                         'roles' => ['R-03']],
        'F-IND-011' => ['name' => 'Candidacy Registration',                     'roles' => ['R-03']],
        'F-IND-012' => ['name' => 'Organization Registration',                  'roles' => ['R-03']],
        'F-IND-013' => ['name' => 'Organization Membership Application',        'roles' => ['R-01']],
        'F-IND-014' => ['name' => 'Worker Registration',                        'roles' => ['R-01']],
        'F-IND-015' => ['name' => 'Advocate Registration',                      'roles' => ['R-03']],
        'F-IND-016' => ['name' => 'Constitutional Challenge Filing',            'roles' => ['R-03']],
        'F-IND-017' => ['name' => 'Civil/Criminal Case Filing',                 'roles' => ['R-03', 'R-21']],

        // ── F-CAN — Candidate Forms (3) ─────────────────────────────────────
        'F-CAN-001' => ['name' => 'Campaign Profile Setup',                     'roles' => ['R-06']],
        'F-CAN-002' => ['name' => 'Endorsement Request',                        'roles' => ['R-06']],
        'F-CAN-003' => ['name' => 'Candidacy Withdrawal',                       'roles' => ['R-06']],

        // ── F-ORG — Organization Agent Forms (7) ────────────────────────────
        'F-ORG-001' => ['name' => 'Organization Profile Management',            'roles' => ['R-23']],
        'F-ORG-002' => ['name' => 'Candidate Endorsement Grant',                'roles' => ['R-23']],
        'F-ORG-003' => ['name' => 'Board Election Administration',              'roles' => ['R-23']],
        'F-ORG-004' => ['name' => 'Worker Board Election Administration',       'roles' => ['R-23']],
        'F-ORG-005' => ['name' => 'Ownership Transfer Initiation',              'roles' => ['R-23']],
        'F-ORG-006' => ['name' => 'Public-Private Conversion Request',          'roles' => ['R-23', 'R-09']],
        'F-ORG-007' => ['name' => 'Organization Dissolution',                   'roles' => ['R-23']],

        // ── F-ELB — Election Board Forms (6) ────────────────────────────────
        'F-ELB-001' => ['name' => 'Election Scheduling Order',                  'roles' => ['R-08']],
        'F-ELB-002' => ['name' => 'Candidate Validation',                       'roles' => ['R-08']],
        'F-ELB-003' => ['name' => 'Subdivision Boundary Drawing',               'roles' => ['R-08']],
        'F-ELB-004' => ['name' => 'Election Results Certification',             'roles' => ['R-08']],
        'F-ELB-005' => ['name' => 'Petition Signature Audit',                   'roles' => ['R-08']],
        'F-ELB-006' => ['name' => 'Recount/Audit Order',                        'roles' => ['R-08']],

        // ── F-LEG — Legislative Representative Forms (36) ───────────────────
        'F-LEG-001' => ['name' => 'Oath of Office / Seating Acceptance',        'roles' => ['R-09']],
        'F-LEG-002' => ['name' => 'Attendance Registration',                    'roles' => ['R-09']],
        'F-LEG-003' => ['name' => 'Bill Introduction',                          'roles' => ['R-09']],
        'F-LEG-004' => ['name' => 'Floor Vote',                                 'roles' => ['R-09']],
        'F-LEG-005' => ['name' => 'Committee Vote',                             'roles' => ['R-11']],
        'F-LEG-006' => ['name' => 'Public Record Statement',                    'roles' => ['R-09']],
        'F-LEG-007' => ['name' => 'Motion Submission',                          'roles' => ['R-09']],
        'F-LEG-008' => ['name' => 'Speaker Nomination/Election Vote',           'roles' => ['R-09']],
        'F-LEG-009' => ['name' => 'Committee Creation Act',                     'roles' => ['R-09']],
        'F-LEG-010' => ['name' => 'Committee Preference Ranking',               'roles' => ['R-09']],
        'F-LEG-011' => ['name' => 'Committee Chair/Alternate Vote',             'roles' => ['R-09']],
        'F-LEG-012' => ['name' => 'Election Board Creation Act',                'roles' => ['R-09']],
        'F-LEG-013' => ['name' => 'Administrative Office Creation Act',         'roles' => ['R-09']],
        'F-LEG-014' => ['name' => 'Executive Committee Delegation Act',         'roles' => ['R-09']],
        'F-LEG-015' => ['name' => 'Executive Office Creation/Conversion Act',   'roles' => ['R-09']],
        'F-LEG-016' => ['name' => 'Department Creation Act',                    'roles' => ['R-09']],
        'F-LEG-017' => ['name' => 'Judiciary Creation Act',                     'roles' => ['R-09']],
        'F-LEG-018' => ['name' => 'Judiciary Conversion Act',                   'roles' => ['R-09']],
        'F-LEG-019' => ['name' => 'Common Good Corporation Creation Act',       'roles' => ['R-09']],
        'F-LEG-020' => ['name' => 'Board of Governors Consent Vote',            'roles' => ['R-09']],
        'F-LEG-021' => ['name' => 'Judicial Nomination Consent Vote',           'roles' => ['R-09']],
        'F-LEG-022' => ['name' => 'Removal/Impeachment/Censure/Expulsion Vote', 'roles' => ['R-09']],
        'F-LEG-023' => ['name' => 'Referendum Delegation Vote',                 'roles' => ['R-09']],
        'F-LEG-024' => ['name' => 'Emergency Powers Declaration Vote',          'roles' => ['R-09']],
        'F-LEG-025' => ['name' => 'Emergency Powers Renewal Vote',              'roles' => ['R-09']],
        'F-LEG-026' => ['name' => 'Monopoly Acquisition Vote',                  'roles' => ['R-09']],
        'F-LEG-027' => ['name' => 'CGC Reorganization/Sale Vote',               'roles' => ['R-09']],
        'F-LEG-028' => ['name' => 'Cultural Institution Recognition Vote',      'roles' => ['R-09']],
        'F-LEG-029' => ['name' => 'Union Formation/Join Vote',                  'roles' => ['R-09']],
        'F-LEG-030' => ['name' => 'Disintermediation Vote',                     'roles' => ['R-09']],
        'F-LEG-031' => ['name' => 'Amendable Setting Change (via Bill)',        'roles' => ['R-09']],
        'F-LEG-032' => ['name' => 'Rules of Order Adoption',                    'roles' => ['R-09']],
        'F-LEG-033' => ['name' => 'Ethics Code Adoption',                       'roles' => ['R-09']],
        'F-LEG-034' => ['name' => 'Referendum Act Modification Vote',           'roles' => ['R-09']],
        'F-LEG-035' => ['name' => 'Judiciary Override Vote',                    'roles' => ['R-09']],
        'F-LEG-036' => ['name' => 'Vacancy Declaration',                        'roles' => ['R-09', 'R-10']],

        // ── F-SPK — Speaker Forms (9) ───────────────────────────────────────
        'F-SPK-001' => ['name' => 'Session Call / Opening',                     'roles' => ['R-10']],
        'F-SPK-002' => ['name' => 'Agenda Setting',                             'roles' => ['R-10']],
        'F-SPK-003' => ['name' => 'Quorum Count Publication',                   'roles' => ['R-10']],
        'F-SPK-004' => ['name' => 'Tie-Breaking Vote',                          'roles' => ['R-10']],
        'F-SPK-005' => ['name' => 'Committee Assignment Administration',        'roles' => ['R-10']],
        'F-SPK-006' => ['name' => 'Member Priority Communication Facilitation', 'roles' => ['R-10']],
        'F-SPK-007' => ['name' => 'Impeachment/Censure/Expulsion Presiding',    'roles' => ['R-10']],
        'F-SPK-008' => ['name' => 'Attendance Compulsion Order',                'roles' => ['R-10']],
        'F-SPK-009' => ['name' => 'Session Minutes Publication',                'roles' => ['R-10', 'R-29']],

        // ── F-CHR — Committee Chair Forms (4) ───────────────────────────────
        'F-CHR-001' => ['name' => 'Committee Meeting Call',                     'roles' => ['R-12']],
        'F-CHR-002' => ['name' => 'Committee Agenda Setting',                   'roles' => ['R-12']],
        'F-CHR-003' => ['name' => 'Bill Referral to Floor',                     'roles' => ['R-12']],
        'F-CHR-004' => ['name' => 'Committee Report Filing',                    'roles' => ['R-12']],

        // ── F-EXE — Executive Forms (5) ─────────────────────────────────────
        'F-EXE-001' => ['name' => 'Board of Governors Nomination',              'roles' => ['R-14', 'R-15', 'R-16']],
        'F-EXE-002' => ['name' => 'Department Policy Proposal',                 'roles' => ['R-14', 'R-15', 'R-16']],
        'F-EXE-003' => ['name' => 'Board Member Removal Request',               'roles' => ['R-14', 'R-15', 'R-16']],
        'F-EXE-004' => ['name' => 'Department Investigation Order',             'roles' => ['R-14', 'R-15', 'R-16']],
        'F-EXE-005' => ['name' => 'Executive Order/Decision',                   'roles' => ['R-14', 'R-15', 'R-16']],

        // ── F-BOG — Board of Governors Forms (2) ────────────────────────────
        'F-BOG-001' => ['name' => 'Department Rule Implementation',             'roles' => ['R-18']],
        'F-BOG-002' => ['name' => 'Department Report Filing',                   'roles' => ['R-18']],

        // ── F-JDG — Judicial Forms (10) ─────────────────────────────────────
        'F-JDG-001' => ['name' => 'Case Acceptance / Panel Assignment',         'roles' => ['R-19', 'R-20']],
        'F-JDG-002' => ['name' => 'Jury Selection Order',                       'roles' => ['R-19', 'R-20']],
        'F-JDG-003' => ['name' => 'Opinion / Ruling Filing',                    'roles' => ['R-19', 'R-20']],
        'F-JDG-004' => ['name' => 'Constitutional Finding',                     'roles' => ['R-19', 'R-20']],
        'F-JDG-005' => ['name' => 'Remedy Recommendation',                      'roles' => ['R-19', 'R-20']],
        'F-JDG-006' => ['name' => 'Judicial Remedy Application',                'roles' => ['R-19', 'R-20']],
        'F-JDG-007' => ['name' => 'Emergency Powers Review',                    'roles' => ['R-19', 'R-20']],
        'F-JDG-008' => ['name' => 'Petition Constitutional Review',             'roles' => ['R-19', 'R-20']],
        'F-JDG-009' => ['name' => 'Sentencing Order',                           'roles' => ['R-19', 'R-20']],
        'F-JDG-010' => ['name' => 'Warrant Issuance',                           'roles' => ['R-19', 'R-20']],

        // ── F-ADV — Advocate Forms (4) ──────────────────────────────────────
        'F-ADV-001' => ['name' => 'Case Filing (on behalf of client)',          'roles' => ['R-21']],
        'F-ADV-002' => ['name' => 'Motion Filing',                              'roles' => ['R-21']],
        'F-ADV-003' => ['name' => 'Evidence Submission',                        'roles' => ['R-21']],
        'F-ADV-004' => ['name' => 'Brief / Argument Filing',                    'roles' => ['R-21']],
    ];

    /**
     * Pure aliases — IDs that exist nowhere in the canonical catalog
     * (prefix drift). Safe to auto-resolve. alias => canonical.
     */
    public const ALIASES = [
        'F-COM-001' => 'F-CHR-001',
        'F-COM-002' => 'F-CHR-002',
        'F-COM-003' => 'F-CHR-003',
        'F-COM-004' => 'F-CHR-004',
        'F-GOV-001' => 'F-BOG-001',
        'F-GOV-002' => 'F-BOG-002',
    ];

    /**
     * Catalog drift — stale Workflows Catalog references whose IDs collide
     * with OTHER canonical forms (MANIFEST §1). NEVER auto-resolved (see
     * class docblock); recorded for display and cross-referencing only.
     *
     * stale id used in catalog => [canonical target, where the catalog uses it]
     */
    public const CATALOG_DRIFT = [
        'F-IND-005' => ['F-IND-004', 'WF-CIV-01'],               // Identity Verification mislabeled (swap)
        'F-IND-004' => ['F-IND-005', 'WF-CIV-02'],               // GPS Residency Ping mislabeled (swap)
        'F-IND-013' => ['F-IND-016', 'WF-JUD-05'],               // Constitutional Challenge Filing
        'F-LEG-034' => ['F-LEG-022', 'impeachment flows'],       // Removal/Impeachment vote
        'F-LEG-022' => ['F-LEG-023', 'WF-LEG-10'],               // Referendum Delegation
        'F-LEG-023' => ['F-LEG-024', 'WF-LEG-11'],               // Emergency Powers Declaration
        'F-LEG-024' => ['F-LEG-025', 'WF-LEG-11'],               // Emergency Powers Renewal
        'F-LEG-030' => ['F-LEG-036', 'WF-ELE-03, WF-LEG-12'],    // Vacancy Declaration
    ];

    /**
     * Handler map: canonical form id => handler class.
     * Handlers live at app/Domain/Forms/Handlers/{StudlyName}.php.
     * Forms without a handler cannot be filed yet (later phases).
     *
     * Phase A: the identity/residency slice + F-LEG-031.
     * Phase B (WI-B4): the 11 election handlers of
     * PHASE_B_DESIGN_schema_lifecycle §C.
     */
    public const HANDLERS = [
        // ── Phase A ─────────────────────────────────────────────────────────
        'F-IND-001' => Handlers\IndividualRegistration::class,
        'F-IND-002' => Handlers\ProfileManagement::class,
        'F-IND-003' => Handlers\ResidencyDeclaration::class,
        'F-IND-004' => Handlers\IdentityVerificationSubmission::class,
        'F-IND-005' => Handlers\GpsResidencyPing::class,
        'F-IND-006' => Handlers\ResidencyVerificationConfirmation::class,
        'F-LEG-031' => Handlers\AmendableSettingChange::class,

        // ── Phase B — elections (WI-B4) ─────────────────────────────────────
        'F-ELB-001' => Handlers\ElectionSchedulingOrder::class,
        'F-ELB-002' => Handlers\CandidateValidation::class,
        'F-ELB-003' => Handlers\SubdivisionBoundaryDrawing::class,
        'F-ELB-004' => Handlers\ElectionResultsCertification::class,
        'F-ELB-006' => Handlers\RecountAuditOrder::class,
        'F-IND-007' => Handlers\BallotSubmission::class,
        'F-IND-011' => Handlers\CandidacyRegistration::class,
        'F-CAN-001' => Handlers\CampaignProfileSetup::class,
        'F-CAN-002' => Handlers\EndorsementRequest::class,
        'F-CAN-003' => Handlers\CandidacyWithdrawal::class,
        'F-ORG-002' => Handlers\CandidateEndorsementGrant::class,

        // ── Phase C — legislature operations, votes-laws scope ──────────────
        // (PHASE_C_DESIGN_votes_laws §G. The chamber-ops scope registers:
        // F-LEG-001, 008–013, 020–022, 032/033, F-SPK-004/005/006/007,
        // F-CHR-001..004, F-LEG-036.)
        // Batch 2 (C-8..C-10): referendums, petitions, emergency powers —
        // F-LEG-023/024/025 are CANONICAL here; the Workflows Catalog's
        // F-LEG-022/023/024 citations for them are recorded CATALOG_DRIFT
        // and never auto-resolve.
        'F-LEG-023' => Handlers\ReferendumDelegation::class,
        'F-LEG-024' => Handlers\EmergencyPowersDeclaration::class,
        'F-LEG-025' => Handlers\EmergencyPowersRenewal::class,
        'F-LEG-034' => Handlers\ReferendumActModification::class,
        'F-IND-008' => Handlers\ReferendumVote::class,
        'F-IND-009' => Handlers\PetitionCreation::class,
        'F-IND-010' => Handlers\PetitionSignature::class,
        'F-ELB-005' => Handlers\PetitionSignatureAudit::class,
        'F-LEG-002' => Handlers\AttendanceRegistration::class,
        'F-LEG-003' => Handlers\BillIntroduction::class,
        'F-LEG-004' => Handlers\FloorVoteCast::class,
        'F-LEG-005' => Handlers\CommitteeVoteCast::class,
        'F-LEG-006' => Handlers\PublicRecordStatement::class,
        'F-LEG-007' => Handlers\MotionSubmission::class,
        'F-SPK-001' => Handlers\SessionCall::class,
        'F-SPK-002' => Handlers\AgendaSetting::class,
        'F-SPK-003' => Handlers\QuorumCountPublication::class,
        'F-SPK-008' => Handlers\AttendanceCompulsionOrder::class,
        'F-SPK-009' => Handlers\SessionMinutesPublication::class,

        // ── Phase C — legislature operations, chamber-ops scope ─────────────
        // (PHASE_C_DESIGN_chamber_ops §G.1: speaker, committees, oversight,
        // board transition, vacancy loop. F-LEG-020/021 stay unregistered —
        // no seated BoG/judicial subjects until Phase D/E.)
        'F-LEG-001' => Handlers\OathOfOffice::class,
        'F-LEG-008' => Handlers\SpeakerElectionVote::class,
        'F-LEG-009' => Handlers\CommitteeCreationAct::class,
        'F-LEG-010' => Handlers\CommitteePreferenceRanking::class,
        'F-LEG-011' => Handlers\CommitteeChairVote::class,
        'F-LEG-012' => Handlers\ElectionBoardCreationAct::class,
        'F-LEG-013' => Handlers\AdminOfficeCreationAct::class,
        'F-LEG-022' => Handlers\RemovalVote::class,
        'F-LEG-032' => Handlers\RulesOfOrderAdoption::class,
        'F-LEG-033' => Handlers\EthicsCodeAdoption::class,
        'F-LEG-036' => Handlers\VacancyDeclaration::class,
        'F-SPK-004' => Handlers\TieBreakingVote::class,
        'F-SPK-005' => Handlers\CommitteeAssignmentAdministration::class,
        'F-SPK-006' => Handlers\MemberPriorityFacilitation::class,
        'F-SPK-007' => Handlers\RemovalPresiding::class,
        'F-CHR-001' => Handlers\CommitteeMeetingCall::class,
        'F-CHR-002' => Handlers\CommitteeAgendaSetting::class,
        'F-CHR-003' => Handlers\BillReferralToFloor::class,
        'F-CHR-004' => Handlers\CommitteeReportFiling::class,

        // ── Phase D — organizations scope (PHASE_D_DESIGN_organizations §D.2:
        // registry/membership/workers, board elections, transfers,
        // conversions, CGC chartering. F-LEG-020 stays unregistered — it is
        // the consent VOTE, cast via F-LEG-004 like every consent.)
        'F-IND-012' => Handlers\OrganizationRegistration::class,
        'F-IND-013' => Handlers\OrganizationMembershipApplication::class,
        'F-IND-014' => Handlers\WorkerRegistration::class,
        'F-ORG-001' => Handlers\OrganizationProfileManagement::class,
        'F-ORG-003' => Handlers\BoardElectionAdministration::class,
        'F-ORG-004' => Handlers\WorkerBoardElectionAdministration::class,
        'F-ORG-005' => Handlers\OwnershipTransferInitiation::class,
        'F-ORG-006' => Handlers\PublicPrivateConversionRequest::class,
        'F-ORG-007' => Handlers\OrganizationDissolution::class,
        'F-LEG-019' => Handlers\CgcCreationAct::class,
        'F-LEG-026' => Handlers\MonopolyAcquisitionVote::class,
        'F-LEG-027' => Handlers\CgcReorganizationSaleVote::class,

        // ── Phase D — executive scope (PHASE_D_DESIGN_executive §B/§D:
        // delegation/conversion + department creation are LEGISLATIVE acts
        // that ride the bill→proposal lane; F-EXE-* act through a seated
        // executive member; F-BOG-* through a seated governor (R-18).
        // F-LEG-020 stays unregistered — it is the consent VOTE, cast via
        // F-LEG-004 like every consent.)
        'F-LEG-014' => Handlers\ExecutiveDelegationAct::class,
        'F-LEG-015' => Handlers\ExecutiveOfficeCreationAct::class,
        'F-LEG-016' => Handlers\DepartmentCreationAct::class,
        'F-EXE-001' => Handlers\BoardGovernorNomination::class,
        'F-EXE-002' => Handlers\DepartmentPolicyProposal::class,
        'F-EXE-003' => Handlers\BoardMemberRemovalRequest::class,
        'F-EXE-004' => Handlers\DepartmentInvestigationOrder::class,
        'F-EXE-005' => Handlers\ExecutiveOrder::class,
        'F-BOG-001' => Handlers\DepartmentRuleImplementation::class,
        'F-BOG-002' => Handlers\DepartmentReportFiling::class,

        // ── Phase E — judiciary scope (PHASE_E_DESIGN_judiciary §B/§E.1:
        // creation/conversion are LEGISLATIVE acts that ride the
        // proposal→vote→adoption lane. F-LEG-021 stays unregistered — it is
        // the Judicial Nomination Consent VOTE, cast via F-LEG-004 like
        // every consent (the F-LEG-020 posture). F-LEG-022 (now accepting
        // subject_type 'judicial_seats') and F-JDG-*/F-ADV-* are sibling /
        // cases-agent scope.)
        'F-LEG-017' => Handlers\JudiciaryCreationAct::class,
        'F-LEG-018' => Handlers\JudiciaryConversionAct::class,

        // ── Phase E — cases / juries / advocates scope
        // (PHASE_E_DESIGN_cases_juries §B: the adjudication core. F-IND-015
        // registers the bar (R-21); F-IND-017 / F-ADV-001 open cases (the
        // double-jeopardy bar runs at the validator stage); F-ADV-002/003/004
        // are advocate hearing filings under the attach-window gate; F-JDG-001
        // accepts + panels (odd ≥3, en-banc), F-JDG-002 empanels the jury,
        // F-JDG-003 publishes the opinion + closes, F-JDG-009 sentences a
        // guilty verdict, F-JDG-010 issues a warrant (Art. II §8 facts). The
        // VERDICT is NOT a form — it is a CaseService transition.)
        'F-IND-015' => Handlers\AdvocateRegistration::class,
        'F-IND-017' => Handlers\CaseFiling::class,
        'F-ADV-001' => Handlers\AdvocateCaseFiling::class,
        'F-ADV-002' => Handlers\MotionFiling::class,
        'F-ADV-003' => Handlers\EvidenceSubmission::class,
        'F-ADV-004' => Handlers\BriefFiling::class,
        'F-JDG-001' => Handlers\CaseAcceptanceAndPanelAssignment::class,
        'F-JDG-002' => Handlers\JurySelectionOrder::class,
        'F-JDG-003' => Handlers\OpinionRulingFiling::class,
        'F-JDG-009' => Handlers\SentencingOrder::class,
        'F-JDG-010' => Handlers\WarrantIssuance::class,

        // ── Phase E — challenge & law scope (PHASE_E_DESIGN_challenge_law §B/§D:
        // the Art. IV §5 machine — F-IND-016 absolute-right filing → F-JDG-004
        // finding → F-JDG-005 remedy+windows → the three paths. F-JDG-006 is
        // the §5.5 judicial remedy (also the CLK-11 fired path); F-JDG-007
        // emergency review (Art. II §7); F-JDG-008 the real petition review
        // (supersedes the Phase C stub); F-LEG-035 the supermajority override
        // (Path 2, riding the chamber_vote_proposal lane). Path 1 reuses the
        // already-registered F-LEG-003 — no new handler.)
        'F-IND-016' => Handlers\ConstitutionalChallengeFiling::class,
        'F-JDG-004' => Handlers\ConstitutionalFinding::class,
        'F-JDG-005' => Handlers\RemedyRecommendation::class,
        'F-JDG-006' => Handlers\JudicialRemedyApplication::class,
        'F-JDG-007' => Handlers\EmergencyPowersReview::class,
        'F-JDG-008' => Handlers\PetitionConstitutionalReview::class,
        'F-LEG-035' => Handlers\JudiciaryOverrideVote::class,

        // ── Phase F — the four jurisdiction processes (Art. V §2/§7/§8). The
        // three previously-dangling catalog forms now ride the chamber-act
        // adoption lane: F-LEG-028 recognizes a powerless cultural institution
        // on a supermajority; F-LEG-029 union (formation/join/exit) and F-LEG-030
        // disintermediation OPEN their dual-meter / unanimity constituent process
        // on adoption. Border settlement + restoration are services-with-audit
        // (not catalog forms). ─────────────────────────────────────────────────
        'F-LEG-028' => Handlers\CulturalInstitutionRecognitionVote::class,
        'F-LEG-029' => Handlers\UnionFormationJoinVote::class,
        'F-LEG-030' => Handlers\DisintermediationVote::class,
    ];

    /**
     * Resolve any form ID (canonical or pure alias) to its canonical ID.
     *
     * @throws InvalidArgumentException for IDs in neither set.
     */
    public static function canonical(string $id): string
    {
        $id = strtoupper(trim($id));

        if (isset(self::FORMS[$id])) {
            return $id;
        }

        if (isset(self::ALIASES[$id])) {
            return self::ALIASES[$id];
        }

        throw new InvalidArgumentException("Unknown constitutional form ID [{$id}].");
    }

    /** Whether the ID resolves to a canonical form (alias-tolerant). */
    public static function exists(string $id): bool
    {
        $id = strtoupper(trim($id));

        return isset(self::FORMS[$id]) || isset(self::ALIASES[$id]);
    }

    /**
     * Full metadata for a form (alias-tolerant input).
     *
     * @return array{id: string, name: string, roles: list<string>, aliases: list<string>, catalog_drift: array<string, string>, handler: class-string|null}
     */
    public static function meta(string $id): array
    {
        $canonical = self::canonical($id);

        $aliases = array_keys(array_filter(self::ALIASES, fn (string $target) => $target === $canonical));

        $drift = [];
        foreach (self::CATALOG_DRIFT as $stale => [$target, $where]) {
            if ($target === $canonical) {
                $drift[$stale] = $where;
            }
        }

        return [
            'id' => $canonical,
            'name' => self::FORMS[$canonical]['name'],
            'roles' => self::FORMS[$canonical]['roles'],
            'aliases' => $aliases,
            'catalog_drift' => $drift,
            'handler' => self::HANDLERS[$canonical] ?? null,
        ];
    }

    /** Handler class for a canonical form ID, or null when not yet implemented. */
    public static function handlerFor(string $canonicalId): ?string
    {
        return self::HANDLERS[$canonicalId] ?? null;
    }

    /** @return list<string> all 103 canonical form IDs. */
    public static function ids(): array
    {
        return array_keys(self::FORMS);
    }
}
