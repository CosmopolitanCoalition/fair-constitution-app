<?php

namespace App\Domain\Achievements;

use InvalidArgumentException;

/**
 * The K-2 achievement catalog — a CODE REGISTRY, never a table (charter:
 * "AchievementCatalog (a code registry, not a table)").
 *
 * 139 entries derived from the as-built app: the 108 canonical forms, the 30
 * derived roles, and the tables that already record every act. Design and
 * rationale: docs/plans/education/K2_ACHIEVEMENT_LIBRARY.md.
 *
 * THREE THINGS EVERY ENTRY CARRIES, AND WHY
 *
 * 1. `earner` — THE ACTOR ON A FORM IS NOT ALWAYS THE EARNER. Many forms are
 *    filed by one person ABOUT another: a board member validates a candidate
 *    (F-ELB-002), an org agent grants an endorsement (F-ORG-002), a judge
 *    summons a juror (F-JDG-002). Awarding on audit_log.actor_user_id alone
 *    would decorate the validator instead of the candidate. So:
 *      EARNER_SELF    the filer earns          -> audit_log.actor_user_id
 *      EARNER_SUBJECT a DIFFERENT person earns -> resolved via the record made
 *      EARNER_STATE   a fact table names them  -> the seating/membership row
 *    This field is REQUIRED on every entry. It is not an annotation on the
 *    exceptions; it is the thing that stops the class reappearing by omission.
 *
 * 2. `scope` — personal rows live in `achievements` (user-scoped, federating);
 *    jurisdiction and system milestones publish through PublicRecordService and
 *    carry NO user. That is what makes a jurisdiction leaderboard lawful while
 *    an individual one never is (PI-1).
 *
 * 3. `tier` — TIER_VERIFIED entries name a server-side source that proves the
 *    act happened. TIER_TOUR entries are the 13 guided journey arcs: they are
 *    self-reported ticks and the UI must label them as walkthroughs, so they
 *    stop borrowing the verified tier's credibility.
 *
 * RAILS THIS REGISTRY IS BOUND BY
 * - CI-1: no entry mints a role or clock. R-01..R-30 and CLK-01..CLK-21 are
 *   closed sets and are untouched. An achievement grants nothing, ever.
 * - PI-2: electoral participation reads `ballot_envelopes`, NEVER `ballots`.
 *   And absence must render as "not yet earned", never as "did not vote".
 * - PI-6: no sum, percentage, rank or level is derived from these — not stored
 *   and not computed at view time.
 * - The agency/condition rule (economy): the personal plane records what a
 *   person DID as a civic actor, never what they RECEIVED or what their
 *   economic condition is. Giving help is an act; needing help is a condition.
 *   The schema agrees — assistance_requests carries `privacy` on the request
 *   and none on the response.
 *
 * `trigger` is a human-readable descriptor of the proving source. The wiring
 * from descriptor to query lives in AchievementService, not here: this file is
 * the catalog, and a catalog that also executed would be two things.
 */
final class AchievementCatalog
{
    public const SCOPE_PERSONAL     = 'personal';
    public const SCOPE_JURISDICTION = 'jurisdiction';
    public const SCOPE_SYSTEM       = 'system';

    public const EARNER_SELF    = 'self';
    public const EARNER_SUBJECT = 'subject';
    public const EARNER_STATE   = 'state';

    public const TIER_VERIFIED = 'verified';
    public const TIER_TOUR     = 'tour';

    /**
     * The 13 guided journey arcs. Keys match config/cga/journeys.php exactly —
     * they are the durable award keys already stored on existing rows, and they
     * stay valid untouched by the journey_id -> award_key generalisation.
     *
     * 3 of the 13 (budget, mutual-aid, stipend-and-tax) are `planned` in that
     * config because the economy has services and schema but no screens a
     * player can reach. They are listed here for completeness and are NOT
     * awardable while planned — JourneyService::liveJourneyOrFail is the gate.
     */
    public const TOUR_ARCS = [
        'become-a-resident',
        'election', 'committee-session', 'bill', 'court-case', 'start-org',
        'board-meeting', 'form-a-group', 'petition-to-referendum',
        'public-service', 'two-governments',
        'budget', 'mutual-aid', 'stipend-and-tax',
    ];

    /**
     * @var array<string, array{title_key: string, scope: string, earner: string, tier: string, trigger: string, awaiting_ui?: bool}>
     */
    public const AWARDS = [

        // ── Arc 1 — Becoming a citizen (R-01 -> R-02 -> R-03/R-04/R-05) ──────
        'ACH-CIV-001' => ['title_key' => 'achievement.ach_civ_001', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-IND-001'],
        'ACH-CIV-002' => ['title_key' => 'achievement.ach_civ_002', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-IND-002'],
        'ACH-CIV-003' => ['title_key' => 'achievement.ach_civ_003', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-IND-003'],
        'ACH-CIV-004' => ['title_key' => 'achievement.ach_civ_004', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-IND-005'],
        // The moment Art. I rights attach. EARNER_STATE, not SELF: F-IND-006 is
        // filed by R-02 OR by the system on the CLK-05 threshold, so the
        // confirmation row is the only correct source for who it belongs to.
        'ACH-CIV-005' => ['title_key' => 'achievement.ach_civ_005', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'residency_confirmations.is_active'],
        'ACH-CIV-006' => ['title_key' => 'achievement.ach_civ_006', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'association chain depth'],
        'ACH-CIV-007' => ['title_key' => 'achievement.ach_civ_007', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'second confirmed association after a lapse'],

        // ── Arc 2 — The franchise (R-04). ENVELOPE ONLY, never `ballots`. ────
        'ACH-VOT-001' => ['title_key' => 'achievement.ach_vot_001', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'ballot_envelopes kind=ranked'],
        'ACH-VOT-002' => ['title_key' => 'achievement.ach_vot_002', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'ballot_envelopes kind=referendum'],
        'ACH-VOT-003' => ['title_key' => 'achievement.ach_vot_003', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'ballot_envelopes x race jurisdiction depth >= 3'],
        'ACH-VOT-004' => ['title_key' => 'achievement.ach_vot_004', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'ballot_envelopes covering every race on one election day'],
        'ACH-VOT-005' => ['title_key' => 'achievement.ach_vot_005', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'receipt verification hit (no hash stored)'],

        // ── Arc 3 — Voice: petition, square, testimony (R-05, R-03) ─────────
        'ACH-VOX-001' => ['title_key' => 'achievement.ach_vox_001', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-IND-010'],
        'ACH-VOX-002' => ['title_key' => 'achievement.ach_vox_002', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-IND-009'],
        'ACH-VOX-003' => ['title_key' => 'achievement.ach_vox_003', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SUBJECT, 'tier' => self::TIER_VERIFIED, 'trigger' => 'petition signature threshold reached -> petition creator'],
        'ACH-VOX-004' => ['title_key' => 'achievement.ach_vox_004', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SUBJECT, 'tier' => self::TIER_VERIFIED, 'trigger' => 'referendum linked to petition -> petition creator'],
        'ACH-VOX-005' => ['title_key' => 'achievement.ach_vox_005', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-SOC-001'],
        'ACH-VOX-006' => ['title_key' => 'achievement.ach_vox_006', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-SOC-002'],
        'ACH-VOX-007' => ['title_key' => 'achievement.ach_vox_007', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-IND-016'],

        // ── Arc 4 — Standing for office (R-06 -> R-07) ──────────────────────
        'ACH-CAN-001' => ['title_key' => 'achievement.ach_can_001', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-IND-011'],
        'ACH-CAN-002' => ['title_key' => 'achievement.ach_can_002', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-CAN-001'],
        'ACH-CAN-003' => ['title_key' => 'achievement.ach_can_003', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-CAN-002'],
        // Filed by the ORG AGENT (R-23) via F-ORG-002 — the CANDIDATE earns.
        'ACH-CAN-004' => ['title_key' => 'achievement.ach_can_004', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SUBJECT, 'tier' => self::TIER_VERIFIED, 'trigger' => 'endorsements endorser_type=organization -> candidate_id holder'],
        // Filed by the BOARD MEMBER (R-08) via F-ELB-002 — the CANDIDATE earns.
        'ACH-CAN-005' => ['title_key' => 'achievement.ach_can_005', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SUBJECT, 'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-ELB-002 -> validated candidacy holder'],
        'ACH-CAN-006' => ['title_key' => 'achievement.ach_can_006', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE,   'tier' => self::TIER_VERIFIED, 'trigger' => 'seated with zero active endorsements'],

        // ── Arc 5 — Serving in a legislature (R-09 -> R-10/R-11/R-12/R-13) ──
        'ACH-LEG-001' => ['title_key' => 'achievement.ach_leg_001', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-LEG-001'],
        'ACH-LEG-002' => ['title_key' => 'achievement.ach_leg_002', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-LEG-002'],
        'ACH-LEG-003' => ['title_key' => 'achievement.ach_leg_003', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-LEG-003'],
        'ACH-LEG-004' => ['title_key' => 'achievement.ach_leg_004', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-LEG-004'],
        'ACH-LEG-005' => ['title_key' => 'achievement.ach_leg_005', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SUBJECT, 'tier' => self::TIER_VERIFIED, 'trigger' => 'law version traced to bill -> bill introducer'],
        'ACH-LEG-006' => ['title_key' => 'achievement.ach_leg_006', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-LEG-007'],
        'ACH-LEG-007' => ['title_key' => 'achievement.ach_leg_007', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-LEG-006'],
        'ACH-LEG-008' => ['title_key' => 'achievement.ach_leg_008', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-LEG-010'],
        'ACH-LEG-009' => ['title_key' => 'achievement.ach_leg_009', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE,   'tier' => self::TIER_VERIFIED, 'trigger' => 'committee_seats seated'],
        'ACH-LEG-010' => ['title_key' => 'achievement.ach_leg_010', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-LEG-005'],
        'ACH-LEG-011' => ['title_key' => 'achievement.ach_leg_011', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE,   'tier' => self::TIER_VERIFIED, 'trigger' => 'committees.chair_member_id'],
        'ACH-LEG-012' => ['title_key' => 'achievement.ach_leg_012', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE,   'tier' => self::TIER_VERIFIED, 'trigger' => 'committees.alternate_member_id'],
        'ACH-LEG-013' => ['title_key' => 'achievement.ach_leg_013', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE,   'tier' => self::TIER_VERIFIED, 'trigger' => 'legislatures.speaker_id'],
        'ACH-LEG-014' => ['title_key' => 'achievement.ach_leg_014', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-SPK-004'],
        'ACH-LEG-015' => ['title_key' => 'achievement.ach_leg_015', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'floor vote on a 2/3 threshold act'],

        // ── Arc 6 — Executive (R-14/R-15/R-16/R-17) ─────────────────────────
        'ACH-EXE-001' => ['title_key' => 'achievement.ach_exe_001', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'executive_members delegated principal'],
        'ACH-EXE-002' => ['title_key' => 'achievement.ach_exe_002', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'executive type=committee, elected'],
        'ACH-EXE-003' => ['title_key' => 'achievement.ach_exe_003', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'executive type=individual, elected'],
        'ACH-EXE-004' => ['title_key' => 'achievement.ach_exe_004', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'executive_members role=advisor'],
        'ACH-EXE-005' => ['title_key' => 'achievement.ach_exe_005', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-EXE-005'],
        'ACH-EXE-006' => ['title_key' => 'achievement.ach_exe_006', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-EXE-001'],

        // ── Arc 7 — Departments, boards & civil service (R-18/R-29/R-30) ────
        'ACH-BOG-001' => ['title_key' => 'achievement.ach_bog_001', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'board_seats on departments'],
        'ACH-BOG-002' => ['title_key' => 'achievement.ach_bog_002', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-BOG-001'],
        'ACH-BOG-003' => ['title_key' => 'achievement.ach_bog_003', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-BOG-002'],
        'ACH-BOG-004' => ['title_key' => 'achievement.ach_bog_004', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'appointments + active term (civil_officer)'],
        'ACH-BOG-005' => ['title_key' => 'achievement.ach_bog_005', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'appointments on admin_offices'],
        'ACH-BOG-006' => ['title_key' => 'achievement.ach_bog_006', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-SPK-009'],

        // ── Arc 8 — Justice (R-19/R-20/R-21/R-22) ───────────────────────────
        'ACH-JUD-001' => ['title_key' => 'achievement.ach_jud_001', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-IND-015'],
        'ACH-JUD-002' => ['title_key' => 'achievement.ach_jud_002', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-IND-017 or F-ADV-001'],
        'ACH-JUD-003' => ['title_key' => 'achievement.ach_jud_003', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-ADV-002'],
        'ACH-JUD-004' => ['title_key' => 'achievement.ach_jud_004', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-ADV-003'],
        'ACH-JUD-005' => ['title_key' => 'achievement.ach_jud_005', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-ADV-004'],
        // Summons is F-JDG-002, filed by a JUDGE — the juror is named on the row.
        'ACH-JUD-006' => ['title_key' => 'achievement.ach_jud_006', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'jury_members summoned'],
        'ACH-JUD-007' => ['title_key' => 'achievement.ach_jud_007', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'jury_members empaneled'],
        'ACH-JUD-008' => ['title_key' => 'achievement.ach_jud_008', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'judicial_seats seated'],
        'ACH-JUD-009' => ['title_key' => 'achievement.ach_jud_009', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-JDG-001'],
        'ACH-JUD-010' => ['title_key' => 'achievement.ach_jud_010', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-JDG-003'],
        'ACH-JUD-011' => ['title_key' => 'achievement.ach_jud_011', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-JDG-004'],
        'ACH-JUD-012' => ['title_key' => 'achievement.ach_jud_012', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-JDG-006'],

        // ── Arc 9 — Organizations & work (R-23..R-28) ───────────────────────
        'ACH-ORG-001' => ['title_key' => 'achievement.ach_org_001', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-IND-012'],
        'ACH-ORG-002' => ['title_key' => 'achievement.ach_org_002', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'organizations.agent_user_id'],
        'ACH-ORG-003' => ['title_key' => 'achievement.ach_org_003', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'org_memberships status=active'],
        'ACH-ORG-004' => ['title_key' => 'achievement.ach_org_004', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'org_workers status=active (countersigned)'],
        // `self` is correct: the AGENT performed the act. The organisation's
        // view of the same event is the org-profile mirror (IK:106, display
        // chrome); the candidate's view is ACH-CAN-004. One event, three
        // surfaces, no double-award — the ledger is idempotent on (user, key).
        'ACH-ORG-005' => ['title_key' => 'achievement.ach_org_005', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-ORG-002'],
        'ACH-ORG-006' => ['title_key' => 'achievement.ach_org_006', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-ORG-003'],
        'ACH-ORG-007' => ['title_key' => 'achievement.ach_org_007', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-ORG-004'],
        'ACH-ORG-008' => ['title_key' => 'achievement.ach_org_008', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'board_seats seat_class=owner_elected'],
        'ACH-ORG-009' => ['title_key' => 'achievement.ach_org_009', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'board_seats seat_class=worker_elected'],
        'ACH-ORG-010' => ['title_key' => 'achievement.ach_org_010', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'board_seats.is_chair'],
        'ACH-ORG-011' => ['title_key' => 'achievement.ach_org_011', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-ORG-005'],
        'ACH-ORG-012' => ['title_key' => 'achievement.ach_org_012', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-ORG-006'],
        // Added 2026-07-26. Lane 6's playtest worksheet walked the public-service
        // arc against this catalogue and exposed the gap: 12 organisation
        // achievements and NONE for the thing that makes a Common Good
        // Corporation a CGC — dedicating its work to the public domain under
        // Art. III §5, which is arguably the sharpest clause in the constitution.
        // Everything a CGC creates is universally and eternally public domain,
        // with no mechanism anywhere to privatise it. That deserved an entry.
        'ACH-ORG-013' => ['title_key' => 'achievement.ach_org_013', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'cgc_ip_register entry via CgcIpRegisterService::dedicate()'],

        // ── Arc 10 — Running elections (R-08) ───────────────────────────────
        'ACH-ELB-001' => ['title_key' => 'achievement.ach_elb_001', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'election_board_members seated'],
        'ACH-ELB-002' => ['title_key' => 'achievement.ach_elb_002', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-ELB-001'],
        'ACH-ELB-003' => ['title_key' => 'achievement.ach_elb_003', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-ELB-004'],
        'ACH-ELB-004' => ['title_key' => 'achievement.ach_elb_004', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-ELB-008'],
        'ACH-ELB-005' => ['title_key' => 'achievement.ach_elb_005', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,  'tier' => self::TIER_VERIFIED, 'trigger' => 'audit_log ref=F-ELB-006'],

        // ── Arc 11 — Federation & node operation ────────────────────────────
        // Copy MUST carry the live house line: "Keeping it online buys no vote
        // and no seat." Operating infrastructure confers nothing (CI-1).
        'ACH-FED-001' => ['title_key' => 'achievement.ach_fed_001', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'peer record'],
        'ACH-FED-002' => ['title_key' => 'achievement.ach_fed_002', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'operator record'],

        // ── Arc 13 — Economy & work (L+M). AWAITING UI: engine and schema are
        // built, but there are no economy routes, no Pages/Economy and no
        // registered economy surfaces, so a player cannot yet perform these.
        //
        // THE AGENCY/CONDITION RULE APPLIES HERE AND NOWHERE ELSE SO SHARPLY:
        // every economy table is local-only, but `achievements` FEDERATES, so
        // an achievement derived from a private financial record would export
        // that record's existence across instances. Hence: no entry for
        // receiving a stipend, holding a balance, or asking for help. Those
        // are jurisdiction milestones (ACH-JUR-023..026) where they carry no
        // person. ACH-ECO-001 records THAT a return was filed, never what.
        'ACH-ECO-001' => ['title_key' => 'achievement.ach_eco_001', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE,   'tier' => self::TIER_VERIFIED, 'trigger' => 'tax_filings existence only (never amount)',      'awaiting_ui' => true],
        'ACH-ECO-002' => ['title_key' => 'achievement.ach_eco_002', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SELF,    'tier' => self::TIER_VERIFIED, 'trigger' => 'floor vote on a budget act',                    'awaiting_ui' => true],
        'ACH-ECO-003' => ['title_key' => 'achievement.ach_eco_003', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_SUBJECT, 'tier' => self::TIER_VERIFIED, 'trigger' => 'budget authored -> budget author',              'awaiting_ui' => true],
        // The RESPONDER earns; the requester never does. assistance_requests
        // carries `privacy` on the request and none on the response.
        'ACH-ECO-004' => ['title_key' => 'achievement.ach_eco_004', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE,   'tier' => self::TIER_VERIFIED, 'trigger' => 'assistance_requests.responder_account_id',      'awaiting_ui' => true],
        'ACH-ECO-005' => ['title_key' => 'achievement.ach_eco_005', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE,   'tier' => self::TIER_VERIFIED, 'trigger' => 'marketplace_listings',                         'awaiting_ui' => true],
        'ACH-ECO-006' => ['title_key' => 'achievement.ach_eco_006', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE,   'tier' => self::TIER_VERIFIED, 'trigger' => 'marketplace_orders settled',                   'awaiting_ui' => true],
        'ACH-ECO-007' => ['title_key' => 'achievement.ach_eco_007', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE,   'tier' => self::TIER_VERIFIED, 'trigger' => 'work_postings',                                'awaiting_ui' => true],
        'ACH-ECO-008' => ['title_key' => 'achievement.ach_eco_008', 'scope' => self::SCOPE_PERSONAL, 'earner' => self::EARNER_STATE,   'tier' => self::TIER_VERIFIED, 'trigger' => 'joint_ledger_parties',                         'awaiting_ui' => true],

        // ── JURISDICTION MILESTONES — Plane B. No user_id, ever. ────────────
        // These publish through PublicRecordService::publish(kind: participation
        // | certification), k-anon floored, never person-attributed. This is
        // what makes a jurisdiction leaderboard lawful (PI-1).
        'ACH-JUR-001' => ['title_key' => 'achievement.ach_jur_001', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'first residency_confirmations'],
        'ACH-JUR-002' => ['title_key' => 'achievement.ach_jur_002', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'jurisdiction_activations -> CLK-06'],
        'ACH-JUR-003' => ['title_key' => 'achievement.ach_jur_003', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'WF-JUR-01 bootstrap board seated'],
        'ACH-JUR-004' => ['title_key' => 'achievement.ach_jur_004', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'first F-ELB-001'],
        'ACH-JUR-005' => ['title_key' => 'achievement.ach_jur_005', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'first F-ELB-004'],
        'ACH-JUR-006' => ['title_key' => 'achievement.ach_jur_006', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'first legislature_members seated'],
        'ACH-JUR-007' => ['title_key' => 'achievement.ach_jur_007', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'legislatures.speaker_id set'],
        'ACH-JUR-008' => ['title_key' => 'achievement.ach_jur_008', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'first F-LEG-009'],
        'ACH-JUR-009' => ['title_key' => 'achievement.ach_jur_009', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'first law version created'],
        'ACH-JUR-010' => ['title_key' => 'achievement.ach_jur_010', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'first referendum result'],
        'ACH-JUR-011' => ['title_key' => 'achievement.ach_jur_011', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'petition -> referendum'],
        'ACH-JUR-012' => ['title_key' => 'achievement.ach_jur_012', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'first F-LEG-014'],
        'ACH-JUR-013' => ['title_key' => 'achievement.ach_jur_013', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'first F-LEG-015'],
        'ACH-JUR-014' => ['title_key' => 'achievement.ach_jur_014', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'first F-LEG-016'],
        'ACH-JUR-015' => ['title_key' => 'achievement.ach_jur_015', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'first F-LEG-017'],
        'ACH-JUR-016' => ['title_key' => 'achievement.ach_jur_016', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'first F-JDG-003'],
        'ACH-JUR-017' => ['title_key' => 'achievement.ach_jur_017', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'first F-LEG-019'],
        'ACH-JUR-018' => ['title_key' => 'achievement.ach_jur_018', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'district map activated'],
        'ACH-JUR-019' => ['title_key' => 'achievement.ach_jur_019', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'Type B seats filled'],
        'ACH-JUR-020' => ['title_key' => 'achievement.ach_jur_020', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'legislature + executive + judiciary all live'],
        'ACH-JUR-021' => ['title_key' => 'achievement.ach_jur_021', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'F-LEG-029 or F-LEG-030'],
        // Celebrates the LAPSE, not the declaration: the constitutional
        // achievement is the 90-day ceiling holding (Art. II §7 + CLK-03).
        'ACH-JUR-022' => ['title_key' => 'achievement.ach_jur_022', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'F-LEG-024 then CLK-03 lapse within 90 days'],
        'ACH-JUR-023' => ['title_key' => 'achievement.ach_jur_023', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'budgets enacted',                'awaiting_ui' => true],
        'ACH-JUR-024' => ['title_key' => 'achievement.ach_jur_024', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'ubi_disbursements run',          'awaiting_ui' => true],
        'ACH-JUR-025' => ['title_key' => 'achievement.ach_jur_025', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'revenue_streams established',   'awaiting_ui' => true],
        'ACH-JUR-026' => ['title_key' => 'achievement.ach_jur_026', 'scope' => self::SCOPE_JURISDICTION, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'assistance_requests resolved',  'awaiting_ui' => true],

        // ── SYSTEM / PLANET MILESTONES — Plane B. ───────────────────────────
        'ACH-SYS-001' => ['title_key' => 'achievement.ach_sys_001', 'scope' => self::SCOPE_SYSTEM, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'first activation'],
        'ACH-SYS-002' => ['title_key' => 'achievement.ach_sys_002', 'scope' => self::SCOPE_SYSTEM, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => '100 jurisdictions active'],
        'ACH-SYS-003' => ['title_key' => 'achievement.ach_sys_003', 'scope' => self::SCOPE_SYSTEM, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => '1,000 jurisdictions active'],
        'ACH-SYS-004' => ['title_key' => 'achievement.ach_sys_004', 'scope' => self::SCOPE_SYSTEM, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => '1,000,000 confirmed residents'],
        'ACH-SYS-005' => ['title_key' => 'achievement.ach_sys_005', 'scope' => self::SCOPE_SYSTEM, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'first peer handshake'],
        'ACH-SYS-006' => ['title_key' => 'achievement.ach_sys_006', 'scope' => self::SCOPE_SYSTEM, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'first F-LEG-029 union'],
        // SUPPRESSION RULE: lane 3's design does COMPLEMENTARY suppression —
        // hiding one cell is provably insufficient when siblings reconstruct
        // it. A reach milestone published from a suppressed or sub-floor
        // snapshot would re-disclose exactly what the floor protects. These two
        // publish ONLY where suppressed = false and state is measurable, and a
        // missing milestone renders as "not yet reached", NEVER "suppressed" —
        // naming the reason leaks the population fact (PI-2, PI-3).
        'ACH-SYS-007' => ['title_key' => 'achievement.ach_sys_007', 'scope' => self::SCOPE_SYSTEM, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'legitimacy_snapshots coverage (suppressed=false only)'],
        'ACH-SYS-008' => ['title_key' => 'achievement.ach_sys_008', 'scope' => self::SCOPE_SYSTEM, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'LegitimacyService::reachRatio bands 25/50/75/100 (suppressed=false only)'],
        'ACH-SYS-009' => ['title_key' => 'achievement.ach_sys_009', 'scope' => self::SCOPE_SYSTEM, 'earner' => self::EARNER_STATE, 'tier' => self::TIER_VERIFIED, 'trigger' => 'association geography covers every continent'],
    ];

    /** Every award key the catalog knows: the 126 ACH-* codes plus the 13 arcs. */
    public static function keys(): array
    {
        return array_merge(array_keys(self::AWARDS), self::TOUR_ARCS);
    }

    public static function has(string $awardKey): bool
    {
        return isset(self::AWARDS[$awardKey]) || in_array($awardKey, self::TOUR_ARCS, true);
    }

    /**
     * Catalog metadata for an award key. Tour arcs resolve to a synthesised
     * TIER_TOUR record — their titles live in config/cga/journeys.php, which
     * stays the single source of truth for the arcs themselves.
     */
    public static function get(string $awardKey): array
    {
        if (isset(self::AWARDS[$awardKey])) {
            return self::AWARDS[$awardKey] + ['awaiting_ui' => false];
        }

        if (in_array($awardKey, self::TOUR_ARCS, true)) {
            return [
                'title_key'   => 'achievement.tour.' . str_replace('-', '_', $awardKey),
                'scope'       => self::SCOPE_PERSONAL,
                'earner'      => self::EARNER_SELF,
                'tier'        => self::TIER_TOUR,
                'trigger'     => 'journey arc completed (self-reported)',
                'awaiting_ui' => false,
            ];
        }

        throw new InvalidArgumentException("Unknown achievement award key [{$awardKey}].");
    }

    /** @return list<string> keys in the given scope. */
    public static function inScope(string $scope): array
    {
        $keys = array_keys(array_filter(
            self::AWARDS,
            static fn (array $a): bool => $a['scope'] === $scope,
        ));

        return $scope === self::SCOPE_PERSONAL
            ? array_merge($keys, self::TOUR_ARCS)
            : $keys;
    }

    /** @return list<string> keys whose earner is the given resolution mode. */
    public static function withEarner(string $earner): array
    {
        return array_keys(array_filter(
            self::AWARDS,
            static fn (array $a): bool => $a['earner'] === $earner,
        ));
    }
}
