<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Services\ChamberVoteService;
use App\Services\Executive\ExecutiveFormationService;
use App\Services\Legislature\CommitteeAssignmentService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. III §1–2 (executive delegation). Realizes
 * Phase D EXIT CRITERION #1 (delegation half): a legislature delegates an
 * executive committee with committee-PROPORTIONAL member selection, and
 * the delegated members are EX-OFFICIO legislators
 * (PHASE_D_DESIGN_executive §B; the F-LEG-014 / WF-EXE-01 path).
 *
 * Pins:
 *  1. PURE size floor (Art. III §2): a delegated executive committee
 *     carries AT LEAST 5 members, NEVER more than the chamber that
 *     delegates it, and has NO 1–9 legislature-style ceiling (the
 *     legislature seat band does not apply to the executive committee —
 *     9, 50, 1000 are all legal delegation sizes given a big-enough
 *     chamber). The conversion target floors the committee model at 5 too,
 *     no ceiling; individual is exactly one office.
 *  2. PURE proportionality + DETERMINISM (Art. III §2 — "in the same
 *     manner as legislative committees"): selection runs through the ONE
 *     pure CommitteeAssignmentService::assign() — the n placements fall to
 *     the highest normalized vote shares (vote_share_norm, #q2), the
 *     #q2 ordering is total + deterministic, and re-running the same
 *     snapshot yields byte-identical placements (no RNG, no clock).
 *  3. ARCHITECTURE pin (no parallel selection math): the formation
 *     service composes the delegated committee ONLY through
 *     CommitteeAssignmentService::assign — it defines no rival
 *     proportional-selection routine (source-scanned), and it writes every
 *     delegated member as an EX-OFFICIO principal (legislature_member_id
 *     set, term_id NULL — the member's term IS their legislative seat's).
 *  4. LIVE rolled-back E2E (guarded pg; skipped when pg unreachable),
 *     in TWO complementary depths:
 *     (a) the SUBSTRATE pin
 *         (test_live_delegation_forms_a_delegated_committee_chosen_by_vote_share):
 *         against a seeded unicameral chamber whose executive is FORMING, a
 *         real `exec_delegate` SUPERMAJORITY vote is driven to adoption and
 *         ExecutiveFormationService::applyDelegation seats the committee —
 *         status forming → delegated, type committee, the act-fixed member
 *         count, the highest n vote shares seated (proportional), every
 *         member ex officio (legislature_member_id set + unique, term_id
 *         NULL).
 *     (b) the ENGINE-FILED pin
 *         (test_engine_filed_delegation_forms_a_delegated_committee_end_to_end):
 *         the SAME constitutional effect REACHED through the real wired
 *         route — ConstitutionalEngine::file('F-LEG-014', …) opens the
 *         exec_delegate vote on a PERSISTED `exec_delegation` proposal row,
 *         the supermajority casts auto-close it, and the votable-effect
 *         dispatch auto-runs applyDelegation. Proves the proposal resolves
 *         open → adopted with result_type executives / result_id = the SAME
 *         executive (ESM-16), atop the same proportional substrate.
 *     Every write rides one transaction, ALWAYS rolled back — zero residue.
 *
 * The engine-filed route is now fully wired: FormRegistry::HANDLERS maps
 * F-LEG-014 → ExecutiveDelegationAct, and the chamber_vote_proposals_kind
 * _check DB constraint admits 'exec_delegation' (the two gaps the prior
 * guard documented are both lifted — migration 2026_06_23_000109).
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class ExecDelegationProportionalityTest extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_exec_delegation_proportionality';

    // ======================================================================
    // 1. PURE size floor — Art. III §2 (DB-free, always run)
    // ======================================================================

    public function test_delegation_size_floors_at_five_with_no_legislature_ceiling(): void
    {
        // Floor of 5 (Art. III §2): 5 is the smallest legal committee.
        ExecutiveFormationService::assertDelegationSize(5, 9);
        ExecutiveFormationService::assertDelegationSize(5, 5);

        foreach ([0, 1, 4] as $tooFew) {
            try {
                ExecutiveFormationService::assertDelegationSize($tooFew, 9);
                $this->fail("A delegated committee of {$tooFew} must be rejected — the floor is 5 (Art. III §2).");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. III §2', $e->citation);
            }
        }

        // No 1–9 legislature-style ceiling: the executive committee is NOT
        // a legislature — given a big-enough chamber, 9, 50, 1000 are all
        // legal delegation sizes (the band 5..9 binds legislatures, not the
        // delegated executive).
        foreach ([9, 10, 50, 1000] as $big) {
            ExecutiveFormationService::assertDelegationSize($big, $big);     // == serving: legal
            ExecutiveFormationService::assertDelegationSize($big, $big + 1); // < serving: legal
        }

        // It can NEVER exceed the delegating chamber's serving members.
        try {
            ExecutiveFormationService::assertDelegationSize(10, 9);
            $this->fail('A committee larger than the chamber must be rejected (Art. III §2).');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. III §2', $e->citation);
            $this->assertStringContainsString('cannot exceed', $e->getMessage());
        }
    }

    public function test_conversion_target_floors_committee_at_five_individual_is_one(): void
    {
        // Committee target: floor 5, no ceiling.
        ExecutiveFormationService::assertConversionTarget(Executive::TYPE_COMMITTEE, 5);
        ExecutiveFormationService::assertConversionTarget(Executive::TYPE_COMMITTEE, 9);
        ExecutiveFormationService::assertConversionTarget(Executive::TYPE_COMMITTEE, 25);

        foreach ([null, 0, 4] as $tooFew) {
            try {
                ExecutiveFormationService::assertConversionTarget(Executive::TYPE_COMMITTEE, $tooFew);
                $this->fail('An elected committee under 5 must be rejected (Art. III §2).');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. III §2', $e->citation);
            }
        }

        // Individual target: exactly one office (member_count is irrelevant).
        ExecutiveFormationService::assertConversionTarget(Executive::TYPE_INDIVIDUAL, null);
        ExecutiveFormationService::assertConversionTarget(Executive::TYPE_INDIVIDUAL, 1);

        // Unknown target type is rejected.
        $this->expectException(ConstitutionalViolation::class);
        ExecutiveFormationService::assertConversionTarget('monarch', 7);
    }

    // ======================================================================
    // 2. PURE proportionality + determinism — Art. III §2 (#q2)
    // ======================================================================

    /**
     * The n delegated seats fall to the highest normalized vote shares —
     * the SAME pure algorithm legislative committees use, modeled as ONE
     * synthetic committee of n seats (base = floor(n/m) each, the n mod m
     * extras to the highest vote_share_norm). With a single committee and
     * n < m, base = 0 and ALL n placements are extras going to the top n
     * shares — proportionality by construction.
     */
    public function test_delegation_selects_the_highest_vote_shares(): void
    {
        // Seven members, distinct shares (1e4-scaled vote_share_norm).
        $shares = [
            'm1' => 16_666, 'm2' => 14_666, 'm3' => 11_757,
            'm4' => 7_115,  'm5' => 5_451,  'm6' => 5_164, 'm7' => 4_750,
        ];

        $members = [];
        $seatNo = 1;
        foreach ($shares as $id => $share) {
            $members[$id] = ['kind' => 'all', 'share' => $share, 'seat_no' => $seatNo++];
        }

        // One synthetic committee of 5 seats — the delegation shape.
        $result = CommitteeAssignmentService::assign(
            [ExecutiveFormationService::SYNTHETIC_COMMITTEE => ['all' => 5]],
            $members,
            [],
        );

        $this->assertCount(5, $result['placements'], 'Exactly n placements (Art. III §2).');

        // base = floor(5/7) = 0; every placement is an extra to a top share.
        $partition = $result['partitions']['all'];
        $this->assertSame(5, $partition['p']);
        $this->assertSame(7, $partition['m']);
        $this->assertSame(0, $partition['base']);
        $this->assertCount(5, $partition['extras']);

        $chosen = array_map(fn ($p) => $p['member_id'], $result['placements']);
        sort($chosen);

        // The 5 highest shares are seated; the 2 lowest (m6, m7) are NOT.
        $this->assertSame(['m1', 'm2', 'm3', 'm4', 'm5'], $chosen, 'Proportional: the n highest vote shares seat (Art. III §2 · #q2).');
        $this->assertNotContains('m6', $chosen, 'The 6th-highest share is excluded at n=5.');
        $this->assertNotContains('m7', $chosen, 'The lowest share is excluded.');
    }

    public function test_selection_is_deterministic_across_repeated_runs(): void
    {
        // A ten-member chamber, n = 6 (base = 0, six extras).
        $members = [];
        for ($i = 1; $i <= 10; $i++) {
            $members["m{$i}"] = ['kind' => 'all', 'share' => 20_000 - $i * 113, 'seat_no' => $i];
        }

        $committees = [ExecutiveFormationService::SYNTHETIC_COMMITTEE => ['all' => 6]];

        $first = CommitteeAssignmentService::assign($committees, $members, []);

        // No RNG, no clock — every run is byte-identical.
        for ($run = 0; $run < 5; $run++) {
            $again = CommitteeAssignmentService::assign($committees, $members, []);
            $this->assertSame($first, $again, 'assign() is a pure deterministic function of its snapshot.');
        }

        $chosen = array_map(fn ($p) => $p['member_id'], $first['placements']);
        sort($chosen);
        $this->assertSame(['m1', 'm2', 'm3', 'm4', 'm5', 'm6'], $chosen, 'The six highest shares seat — proportional.');
    }

    public function test_q2_ordering_is_total_and_breaks_share_ties_deterministically(): void
    {
        // share DESC dominates.
        $this->assertLessThan(0, CommitteeAssignmentService::compareMembers(
            ['share' => 9_000, 'seat_no' => 9], 'zzz',
            ['share' => 1_000, 'seat_no' => 1], 'aaa',
        ), 'Higher vote share ranks first (#q2).');

        // Equal share → seat_no ASC (nulls last).
        $this->assertLessThan(0, CommitteeAssignmentService::compareMembers(
            ['share' => 5_000, 'seat_no' => 2], 'bbb',
            ['share' => 5_000, 'seat_no' => 7], 'aaa',
        ), 'Tie on share: lower seat_no wins.');

        $this->assertLessThan(0, CommitteeAssignmentService::compareMembers(
            ['share' => 5_000, 'seat_no' => 3], 'bbb',
            ['share' => 5_000, 'seat_no' => null], 'aaa',
        ), 'A real seat_no beats a null seat_no.');

        // Equal share AND seat_no → member uuid ASC (total order, no ties).
        $this->assertLessThan(0, CommitteeAssignmentService::compareMembers(
            ['share' => 5_000, 'seat_no' => 4], 'aaa',
            ['share' => 5_000, 'seat_no' => 4], 'bbb',
        ), 'Final tiebreak: uuid ASC — the ordering is total.');
    }

    // ======================================================================
    // 3. ARCHITECTURE — no parallel selection math; members are ex officio
    // ======================================================================

    /**
     * Single-source pin: the formation service composes the delegated
     * committee ONLY through CommitteeAssignmentService::assign — it
     * defines NO rival proportional-selection routine. A second selection
     * implementation would be a constitutional fork of Art. III §2.
     */
    public function test_formation_service_has_no_parallel_selection_math(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(ExecutiveFormationService::class))->getFileName()
        );

        // Selection delegates to the ONE pure assignment algorithm.
        $this->assertStringContainsString(
            'CommitteeAssignmentService::assign(',
            $source,
            'Delegated selection MUST route through the single committee algorithm (Art. III §2).'
        );

        // It defines no rival selection function and no hand-rolled
        // proportional sort/quota of its own.
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+(select|allocate|apportion|proportion|distribute)\w*Seats?\s*\(/i',
            $source,
            'ExecutiveFormationService must define no parallel seat-selection routine (Art. III §2).'
        );

        // The Droop/STV quota machinery lives in the PROTECTED counting
        // core, never re-implemented here.
        $this->assertDoesNotMatchRegularExpression(
            '/droop|quota\s*=|new\s+CountInput|countStv|VoteCountingService/i',
            $source,
            'Delegation reuses committee assignment, never a second counting engine (Art. III §2).'
        );
    }

    /**
     * Provenance pins (the ex-officio mechanism, by construction): every
     * delegated member is written as a PRINCIPAL carrying its
     * legislature_member_id with term_id NULL — the member's term IS their
     * legislative seat's term (never a duplicated lockstep source). The
     * selection provenance is delegated_proportional.
     */
    public function test_delegated_member_write_is_ex_officio_principal(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(ExecutiveFormationService::class))->getFileName()
        );

        // The delegation member row: ex officio (legislature_member_id from
        // the chamber seat; term_id NULL by design), principal,
        // delegated_proportional, seated.
        $this->assertStringContainsString("'legislature_member_id' => \$member->id", $source);
        $this->assertStringContainsString("'term_id'               => null", $source);
        $this->assertStringContainsString('ExecutiveMember::ROLE_PRINCIPAL', $source);
        $this->assertStringContainsString('ExecutiveMember::SELECTION_DELEGATED_PROPORTIONAL', $source);

        // The model constants are stable identifiers the chain depends on.
        $this->assertSame('principal', ExecutiveMember::ROLE_PRINCIPAL);
        $this->assertSame('delegated_proportional', ExecutiveMember::SELECTION_DELEGATED_PROPORTIONAL);
        $this->assertSame('seated', ExecutiveMember::STATUS_SEATED);
        $this->assertSame('forming', Executive::STATUS_FORMING);
        $this->assertSame('delegated', Executive::STATUS_DELEGATED);
        $this->assertSame('committee', Executive::TYPE_COMMITTEE);
    }

    // ======================================================================
    // 4. LIVE rolled-back E2E (guarded pg; ALWAYS rolled back)
    // ======================================================================

    /**
     * THE delegation exit criterion against the real backend: a real
     * `exec_delegate` SUPERMAJORITY chamber vote is driven to adoption,
     * then the real ExecutiveFormationService::applyDelegation seats the
     * committee. Asserts: status forming → delegated, type committee, the
     * act-fixed member count, the n highest vote shares seated
     * (proportional), and every member ex officio
     * (legislature_member_id set + unique, term_id NULL).
     */
    public function test_live_delegation_forms_a_delegated_committee_chosen_by_vote_share(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            [$legislature, $executive, $serving] = $this->liveFormingExecutive();

            // The act-fixed size: 5 (the floor) ≤ serving, and strictly
            // less than the chamber so the exclusion of the lowest shares
            // is observable.
            $n = 5;
            $this->assertGreaterThan($n, $serving->count(),
                'This live pin needs a chamber larger than the committee so exclusion is visible.');

            $speakerId = $legislature->speaker_id !== null ? (string) $legislature->speaker_id : null;

            // A non-Speaker proposer (the Speaker cannot cast on the
            // yes/no business they would open).
            $proposer = $serving->first(fn ($m) => (string) $m->id !== $speakerId);
            $this->assertNotNull($proposer);

            // ── Drive the REAL supermajority vote engine to adoption ──────
            $votes = app(ChamberVoteService::class);

            $vote = $votes->open(
                bodyType: ChamberVote::BODY_LEGISLATURE,
                bodyId: (string) $legislature->id,
                voteType: 'exec_delegate',
                votable: null, // adoption effect applied explicitly below
                stage: ChamberVote::STAGE_FLOOR,
                opener: $proposer,
            );

            $this->assertSame('exec_delegate', $vote->vote_type, 'F-LEG-014 opens the exec_delegate vote.');
            $this->assertSame(ChamberVote::BASIS_SUPERMAJORITY, $vote->threshold_basis,
                'Delegation is a SUPERMAJORITY act (Art. III §1 · Art. VII).');

            $tally = $vote->tallies()->first();
            $this->assertSame($serving->count(), (int) $tally->serving, 'Denominator is ALL serving members.');
            // ceil(serving × 2/3) — the hardened supermajority peg.
            $this->assertSame(
                (int) ceil($serving->count() * 2 / 3),
                (int) $tally->required_yes,
                'required_yes = ceil(serving × 2/3) — the Art. VII supermajority formula.'
            );

            // Every non-Speaker member votes yes — clears the supermajority.
            foreach ($serving as $member) {
                if ((string) $member->id === $speakerId) {
                    continue;
                }
                $votes->cast($vote->fresh(), $member, 'yes');
            }

            $vote->refresh();
            $this->assertSame(ChamberVote::STATUS_CLOSED, $vote->status, 'Full participation auto-closes the vote.');
            $this->assertSame(ChamberVote::OUTCOME_ADOPTED, $vote->outcome, 'The supermajority adopts the delegation.');

            // ── The REAL adoption effect (the F-LEG-014 backend path) ─────
            $proposal = ChamberVoteProposal::create([
                'legislature_id' => $legislature->id,
                // The proposal-kind CHECK constraint omits 'exec_delegation'
                // on the committed backend (recorded as backendBug);
                // applyDelegation NEVER reads proposal_kind, so a Phase C
                // kind scaffolds the row without masking any behavior under
                // test. The payload below is the real F-LEG-014 payload.
                'proposal_kind' => ChamberVoteProposal::KIND_COMMITTEE_CREATION,
                'payload' => [
                    'executive_id' => (string) $executive->id,
                    'delegated_scope' => 'ExecDelegationProportionalityTest throwaway scope',
                    'member_count' => $n,
                    'interest' => [],
                ],
                'proposed_by_member_id' => $proposer->id,
                'status' => ChamberVoteProposal::STATUS_OPEN,
                'vote_id' => (string) $vote->id,
            ]);

            [$resultType, $resultId] = app(ExecutiveFormationService::class)->applyDelegation($proposal, $vote);

            $this->assertSame('executives', $resultType);
            $this->assertSame((string) $executive->id, $resultId, 'ESM-16: the SAME executive row evolves — no second row.');

            // ── The executive evolved forming → delegated ─────────────────
            $executive->refresh();
            $this->assertSame(Executive::STATUS_DELEGATED, $executive->status, 'forming → delegated (Art. III §1).');
            $this->assertSame(Executive::TYPE_COMMITTEE, $executive->type, 'A delegated executive is the committee model.');
            $this->assertSame($n, (int) $executive->delegated_member_count, 'The act-fixed committee size is recorded.');
            $this->assertSame((string) $legislature->id, (string) $executive->source_legislature_id,
                'The delegating chamber is recorded as the source.');
            $this->assertNotNull($executive->delegation_law_id, 'Delegation enacts a creation-act law.');

            // ── n principals seated, by PROPORTIONAL vote share ───────────
            $seatedMembers = ExecutiveMember::query()
                ->where('executive_id', $executive->id)
                ->where('status', ExecutiveMember::STATUS_SEATED)
                ->get();

            $this->assertCount($n, $seatedMembers, "Exactly {$n} principals seat (Art. III §2).");

            foreach ($seatedMembers as $row) {
                $this->assertSame(ExecutiveMember::ROLE_PRINCIPAL, $row->role,
                    'Every delegated committee member is a principal with equal weight (Art. III §3).');
                $this->assertSame(ExecutiveMember::SELECTION_DELEGATED_PROPORTIONAL, $row->selection,
                    'Provenance is delegated_proportional (Art. III §2).');

                // EX OFFICIO: carries its legislative seat, term_id NULL.
                $this->assertNotNull($row->legislature_member_id,
                    'A delegated member is ex officio — it carries its legislature seat (Art. III §1).');
                $this->assertNull($row->term_id,
                    'Ex officio: the member term IS the legislative seat term — term_id stays NULL (ESM-16).');
            }

            // The selected seats are EXACTLY the n highest vote shares —
            // proportional, with the lowest shares excluded.
            $byMemberId = $serving->keyBy(fn ($m) => (string) $m->id);

            $selectedMemberIds = $seatedMembers
                ->pluck('legislature_member_id')
                ->map(fn ($id) => (string) $id);

            // Ex-officio uniqueness: no legislative seat is duplicated.
            $this->assertSame(
                $n,
                $selectedMemberIds->unique()->count(),
                'No legislative seat is seated twice — ex officio is one-to-one (Art. III §1).'
            );

            $expectedTopN = $serving
                ->sortByDesc(fn ($m) => (float) ($m->vote_share_norm ?? 0))
                ->take($n)
                ->map(fn ($m) => (string) $m->id)
                ->values()
                ->sort()
                ->values()
                ->all();

            $this->assertSame(
                $expectedTopN,
                $selectedMemberIds->sort()->values()->all(),
                'The n delegated seats are the n highest vote shares — proportional selection (Art. III §2 · #q2).'
            );

            // The excluded members are precisely the lowest-share members,
            // and the lowest-share member of the chamber is NOT seated.
            $lowestShareMemberId = (string) $serving
                ->sortBy(fn ($m) => (float) ($m->vote_share_norm ?? 0))
                ->first()->id;

            $this->assertFalse(
                $selectedMemberIds->contains($lowestShareMemberId),
                'The lowest vote share is excluded when the chamber exceeds the committee size (Art. III §2).'
            );

            // The members carry the chamber's seat shares (every seated
            // member out-ranks every excluded member on vote_share_norm).
            $seatedShares = $selectedMemberIds->map(fn ($id) => (float) ($byMemberId[$id]->vote_share_norm ?? 0));
            $excludedShares = $serving
                ->reject(fn ($m) => $selectedMemberIds->contains((string) $m->id))
                ->map(fn ($m) => (float) ($m->vote_share_norm ?? 0));

            $this->assertGreaterThanOrEqual(
                (float) $excludedShares->max(),
                (float) $seatedShares->min(),
                'Every seated share ≥ every excluded share — the cut is by vote share (Art. III §2).'
            );
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }

            DB::setDefaultConnection($originalDefault);
        }
    }

    /**
     * THE engine-filed exit criterion (Phase D EXIT #1, delegation half),
     * end to end through ConstitutionalEngine::file('F-LEG-014', …) — the
     * UPGRADE of the live pin above from a hand-scaffolded proposal to the
     * REAL wired route. The handler is now registered
     * (F-LEG-014 ⇒ ExecutiveDelegationAct) and the
     * chamber_vote_proposals_kind_check now allows 'exec_delegation', so the
     * whole path runs:
     *
     *   ConstitutionalEngine::file (R-09 authorize → validate → txn)
     *     → ExecutiveDelegationAct::handle
     *     → ExecutiveActService::proposeDelegation
     *       → a `chamber_vote_proposals` row (kind exec_delegation) PERSISTS
     *       → ChamberVoteService::open(exec_delegate, votable=proposal)
     *   → the real supermajority casts auto-close the vote
     *     → dispatchVotableEffects('chamber_vote_proposal')
     *       → ChamberActService::resolveProposalVote → applyProposalAdoption
     *         → ExecutiveFormationService::applyDelegation (auto-dispatched)
     *
     * Asserts the SAME substrate facts the direct pin proves — executive
     * forming → delegated, type committee, act-fixed count, the n highest
     * vote shares seated (proportional), every member ex officio
     * (legislature_member_id set + unique, term_id NULL) — now REACHED
     * through the wired engine route, with the proposal row driving adoption
     * (status open → adopted, result_type executives, result_id = the SAME
     * executive — ESM-16). One transaction, ALWAYS rolled back.
     */
    public function test_engine_filed_delegation_forms_a_delegated_committee_end_to_end(): void
    {
        // The wiring this E2E depends on (both fixed since the prior guard):
        // F-LEG-014 has its engine handler, and the proposal-kind CHECK now
        // admits 'exec_delegation' so the proposal row can persist.
        $this->assertSame(
            \App\Domain\Forms\Handlers\ExecutiveDelegationAct::class,
            \App\Domain\Forms\FormRegistry::handlerFor('F-LEG-014'),
            'F-LEG-014 must route to ExecutiveDelegationAct for the engine-filed delegation act (Art. III §1–2).'
        );

        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            [$legislature, $executive, $serving] = $this->liveFormingExecutive();

            // The proposal-kind CHECK now admits 'exec_delegation' — the
            // exact blocker the prior guard documented, now lifted; the
            // proposal row below persists rather than hitting SQLSTATE 23514.
            $kindCheck = $conn->selectOne(
                "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'chamber_vote_proposals_kind_check'"
            );
            $this->assertNotNull($kindCheck, 'The proposal-kind CHECK constraint must exist.');
            $this->assertStringContainsString(
                'exec_delegation',
                (string) $kindCheck->def,
                'The exec_delegation proposal kind must be admitted by the CHECK so the F-LEG-014 proposal row persists.'
            );

            // The act-fixed size: 5 (the floor) < serving, so the exclusion
            // of the lowest shares is observable through the wired path.
            $n = 5;
            $this->assertGreaterThan($n, $serving->count(),
                'This live pin needs a chamber larger than the committee so exclusion is visible.');

            $speakerId = $legislature->speaker_id !== null ? (string) $legislature->speaker_id : null;

            // A non-Speaker serving member files the act (R-09); the Speaker
            // cannot cast on the yes/no business they would open.
            $proposer = $serving->first(fn ($m) => (string) $m->id !== $speakerId);
            $this->assertNotNull($proposer);

            $proposerUser = User::query()->find($proposer->user_id);
            $this->assertNotNull($proposerUser, 'The proposing member must resolve to a real user (the R-09 actor).');

            // ── File F-LEG-014 through THE ENGINE (the real wired route) ──
            // Authorize (R-09) → validate → handler → proposeDelegation
            // (persists the exec_delegation proposal + opens exec_delegate),
            // all inside one ConstitutionalEngine transaction.
            $result = app(\App\Domain\Engine\ConstitutionalEngine::class)->file('F-LEG-014', $proposerUser, [
                'legislature_id' => (string) $legislature->id,
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'delegated_scope' => 'ExecDelegationProportionalityTest engine-filed throwaway scope',
                'member_count' => $n,
                'interest' => [],
            ]);

            $this->assertSame('F-LEG-014', $result->formId, 'The engine filed the canonical delegation form.');

            $recorded = $result->recorded;
            $voteId = (string) $recorded['vote_id'];
            $proposalId = (string) $recorded['proposal_id'];

            // The proposal row PERSISTED — the kind that the prior backend
            // CHECK rejected (exec_delegation) is now stored, votable to the
            // opened exec_delegate vote.
            $proposal = ChamberVoteProposal::query()->findOrFail($proposalId);
            $this->assertSame(ChamberVoteProposal::KIND_EXEC_DELEGATION, $proposal->proposal_kind,
                'The F-LEG-014 proposal persists with the exec_delegation kind (the lifted blocker).');
            $this->assertSame(ChamberVoteProposal::STATUS_OPEN, $proposal->status, 'It opens awaiting the vote.');
            $this->assertSame($voteId, (string) $proposal->vote_id, 'The proposal carries its exec_delegate vote.');

            // ── The supermajority vote the handler opened ─────────────────
            $vote = ChamberVote::query()->findOrFail($voteId);
            $this->assertSame('exec_delegate', $vote->vote_type, 'F-LEG-014 opens the exec_delegate vote.');
            $this->assertSame(ChamberVote::BASIS_SUPERMAJORITY, $vote->threshold_basis,
                'Delegation is a SUPERMAJORITY act (Art. III §1 · Art. VII).');
            $this->assertSame('chamber_vote_proposal', $vote->votable_type,
                'The proposal is the votable — adoption auto-dispatches applyDelegation through it.');
            $this->assertSame($proposalId, (string) $vote->votable_id, 'The vote points back at the persisted proposal.');

            $tally = $vote->tallies()->first();
            $this->assertSame($serving->count(), (int) $tally->serving, 'Denominator is ALL serving members.');
            // ceil(serving × 2/3) — the hardened supermajority peg.
            $this->assertSame(
                (int) ceil($serving->count() * 2 / 3),
                (int) $tally->required_yes,
                'required_yes = ceil(serving × 2/3) — the Art. VII supermajority formula.'
            );

            // ── The real casts auto-close the vote and auto-dispatch ──────
            // Every non-Speaker member votes yes — clears the supermajority;
            // the engine auto-closes at full participation, and the
            // votable-effect dispatch runs applyDelegation in the SAME
            // transaction (NO direct service call — the wired route does it).
            $votes = app(ChamberVoteService::class);
            foreach ($serving as $member) {
                if ((string) $member->id === $speakerId) {
                    continue;
                }
                $votes->cast($vote->fresh(), $member, 'yes');
            }

            $vote->refresh();
            $this->assertSame(ChamberVote::STATUS_CLOSED, $vote->status, 'Full participation auto-closes the vote.');
            $this->assertSame(ChamberVote::OUTCOME_ADOPTED, $vote->outcome, 'The supermajority adopts the delegation.');

            // ── The proposal RESOLVED through the wired adoption path ─────
            $proposal->refresh();
            $this->assertSame(ChamberVoteProposal::STATUS_ADOPTED, $proposal->status,
                'Adoption auto-dispatched: the proposal is resolved adopted (open → adopted).');
            $this->assertSame('executives', $proposal->result_type, 'Adoption produced the executive.');
            $this->assertSame((string) $executive->id, (string) $proposal->result_id,
                'ESM-16: the SAME executive row is the adoption result — no second row.');

            // ── The executive evolved forming → delegated ─────────────────
            $executive->refresh();
            $this->assertSame(Executive::STATUS_DELEGATED, $executive->status, 'forming → delegated (Art. III §1).');
            $this->assertSame(Executive::TYPE_COMMITTEE, $executive->type, 'A delegated executive is the committee model.');
            $this->assertSame($n, (int) $executive->delegated_member_count, 'The act-fixed committee size is recorded.');
            $this->assertSame((string) $legislature->id, (string) $executive->source_legislature_id,
                'The delegating chamber is recorded as the source.');
            $this->assertNotNull($executive->delegation_law_id, 'Delegation enacts a creation-act law.');

            // ── n principals seated, by PROPORTIONAL vote share ───────────
            $seatedMembers = ExecutiveMember::query()
                ->where('executive_id', $executive->id)
                ->where('status', ExecutiveMember::STATUS_SEATED)
                ->get();

            $this->assertCount($n, $seatedMembers, "Exactly {$n} principals seat (Art. III §2).");

            foreach ($seatedMembers as $row) {
                $this->assertSame(ExecutiveMember::ROLE_PRINCIPAL, $row->role,
                    'Every delegated committee member is a principal with equal weight (Art. III §3).');
                $this->assertSame(ExecutiveMember::SELECTION_DELEGATED_PROPORTIONAL, $row->selection,
                    'Provenance is delegated_proportional (Art. III §2).');

                // EX OFFICIO: carries its legislative seat, term_id NULL.
                $this->assertNotNull($row->legislature_member_id,
                    'A delegated member is ex officio — it carries its legislature seat (Art. III §1).');
                $this->assertNull($row->term_id,
                    'Ex officio: the member term IS the legislative seat term — term_id stays NULL (ESM-16).');
            }

            // The selected seats are EXACTLY the n highest vote shares —
            // proportional, with the lowest shares excluded.
            $byMemberId = $serving->keyBy(fn ($m) => (string) $m->id);

            $selectedMemberIds = $seatedMembers
                ->pluck('legislature_member_id')
                ->map(fn ($id) => (string) $id);

            // Ex-officio uniqueness: no legislative seat is duplicated.
            $this->assertSame(
                $n,
                $selectedMemberIds->unique()->count(),
                'No legislative seat is seated twice — ex officio is one-to-one (Art. III §1).'
            );

            $expectedTopN = $serving
                ->sortByDesc(fn ($m) => (float) ($m->vote_share_norm ?? 0))
                ->take($n)
                ->map(fn ($m) => (string) $m->id)
                ->values()
                ->sort()
                ->values()
                ->all();

            $this->assertSame(
                $expectedTopN,
                $selectedMemberIds->sort()->values()->all(),
                'The n delegated seats are the n highest vote shares — proportional selection (Art. III §2 · #q2).'
            );

            // The lowest-share member of the chamber is NOT seated.
            $lowestShareMemberId = (string) $serving
                ->sortBy(fn ($m) => (float) ($m->vote_share_norm ?? 0))
                ->first()->id;

            $this->assertFalse(
                $selectedMemberIds->contains($lowestShareMemberId),
                'The lowest vote share is excluded when the chamber exceeds the committee size (Art. III §2).'
            );

            // Every seated share ≥ every excluded share — the cut is by vote
            // share (the proportional invariant, now via the wired route).
            $seatedShares = $selectedMemberIds->map(fn ($id) => (float) ($byMemberId[$id]->vote_share_norm ?? 0));
            $excludedShares = $serving
                ->reject(fn ($m) => $selectedMemberIds->contains((string) $m->id))
                ->map(fn ($m) => (float) ($m->vote_share_norm ?? 0));

            $this->assertGreaterThanOrEqual(
                (float) $excludedShares->max(),
                (float) $seatedShares->min(),
                'Every seated share ≥ every excluded share — the cut is by vote share (Art. III §2).'
            );
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }

            DB::setDefaultConnection($originalDefault);
        }
    }

    // ======================================================================
    // Plumbing (WorkerRepresentationTest / EmergencyCeilingTest posture)
    // ======================================================================

    /**
     * A seeded UNICAMERAL chamber whose executive is FORMING, with more
     * serving members than the committee size — so the proportional
     * exclusion of the lowest shares is observable.
     *
     * @return array{0: Legislature, 1: Executive, 2: \Illuminate\Support\Collection<int, LegislatureMember>}
     */
    private function liveFormingExecutive(): array
    {
        $executive = Executive::query()
            ->where('status', Executive::STATUS_FORMING)
            ->whereHas('jurisdiction', fn ($q) => $q->whereNull('deleted_at'))
            ->get()
            ->first(function (Executive $exec) {
                $legislature = Legislature::query()
                    ->whereNull('deleted_at')
                    ->where('jurisdiction_id', $exec->jurisdiction_id)
                    ->where('type_b_seats', 0) // unicameral — the cleanest selection assertion
                    ->first();

                if ($legislature === null) {
                    return false;
                }

                $serving = LegislatureMember::query()
                    ->where('legislature_id', $legislature->id)
                    ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
                    ->count();

                return $serving > 5; // strictly larger than the committee floor
            });

        if ($executive === null) {
            $this->markTestSkipped(
                'No seeded unicameral jurisdiction with a FORMING executive and > 5 serving members '
                .'(e.g. Montegiardino) — seed the dev DB first.'
            );
        }

        $legislature = Legislature::query()
            ->whereNull('deleted_at')
            ->where('jurisdiction_id', $executive->jurisdiction_id)
            ->where('type_b_seats', 0)
            ->firstOrFail();

        $serving = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->orderBy('seat_no')
            ->get();

        return [$legislature, $executive, $serving];
    }

    private function livePg(): Connection
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql not loaded — live pins run inside the app container.');
        }

        config([
            'database.connections.'.self::LIVE_CONNECTION => array_merge(
                config('database.connections.pgsql'),
                ['database' => env('LIVE_PG_DATABASE', 'fair_constitution')]
            ),
        ]);

        try {
            $connection = DB::connection(self::LIVE_CONNECTION);
            $connection->getPdo();

            return $connection;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Live PostgreSQL unreachable — run inside the app container. ('.$e->getMessage().')');
        }
    }
}
