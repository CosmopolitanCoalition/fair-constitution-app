<?php

namespace Tests\Constitutional;

use App\Models\Appointment;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\ChamberVote;
use App\Models\ChamberVoteTally;
use App\Models\ClockTimer;
use App\Models\Department;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\GovernorRemovalRequest;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Term;
use App\Models\User;
use App\Models\VoteCast;
use App\Services\ChamberVoteService;
use App\Services\ConstitutionalValidator;
use App\Services\Executive\BoardGovernorService;
use App\Services\Executive\ExecutiveActService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. III §4 (Board of Governors removal). Pins the
 * Phase D asymmetry between APPOINTMENT (consent) and REMOVAL: a seated
 * board-of-governors member is removable by ORDINARY MAJORITY of all
 * serving — hiring-and-firing — deliberately NOT the supermajority
 * `officeholder_remove` impeachment machinery (owner ruling #14). Folding
 * governor removal into the impeachment path would invite threshold drift.
 *
 * The committed backend already names this file as its pin
 * (BoardGovernorService::requestRemoval comment;
 * ExecutiveActService::GOVERNOR_REMOVAL_VOTE_TYPE docblock); this file
 * makes that promise GREEN against the real backend.
 *
 * Pins:
 *  1. PURE: the governor-removal vote class is `procedural_motion` whose
 *     registry basis is `majority` → snapshots threshold_basis MAJORITY,
 *     NOT supermajority. The impeachment class `officeholder_remove`
 *     snapshots SUPERMAJORITY — the two thresholds genuinely diverge for
 *     every serving count ≥ 5. The appointment-consent class `bog_consent`
 *     is itself ordinary majority (consent ≠ supermajority), so the
 *     asymmetry is in the WORKFLOW, not the threshold ladder.
 *  2. PURE: the snapshotted required_yes for the removal vote equals the
 *     ordinary-majority peg quorum(serving) = floor(serving/2)+1, and is
 *     STRICTLY below supermajority(serving) for serving ≥ 5.
 *  3. SOURCE: BoardGovernorService::requestRemoval opens the removal vote
 *     through GOVERNOR_REMOVAL_VOTE_TYPE and never references
 *     `officeholder_remove`.
 *  4. LIVE (guarded pg; one transaction, ALWAYS rolled back): stand up a
 *     throwaway department board with a seated governor (10-year civil
 *     term + armed CLK-09); open the removal via the real service; assert
 *     the vote is vote_type procedural_motion, basis MAJORITY, required_yes
 *     = the ordinary-majority peg; an ordinary majority of all serving
 *     ADOPTS → the governor is removed, the seat vacated, the term closed,
 *     the CLK-09 timer cancelled, and a fresh vacant governor seat opens
 *     for renomination. A below-majority tally RETAINS.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class GovernorRemovalOrdinaryMajorityTest extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_governor_removal_ordinary_majority';

    // ======================================================================
    // 1. Pure pins (DB-free, always run)
    // ======================================================================

    /**
     * THE pin: governor removal rides `procedural_motion` (ordinary
     * majority), never the supermajority impeachment class. The contrast
     * is constitutional: removal ≠ impeachment.
     */
    public function test_governor_removal_vote_type_is_ordinary_majority_not_supermajority(): void
    {
        // The owner-ruling #14 constant the service files under.
        $this->assertSame(
            'procedural_motion',
            ExecutiveActService::GOVERNOR_REMOVAL_VOTE_TYPE,
            'Art. III §4 — governor removal is hiring-and-firing (ordinary majority), the '
            .'unstated-threshold `procedural_motion` class, NOT the impeachment machinery.'
        );

        // The registry resolves that class to an ORDINARY-MAJORITY basis.
        $removalConfig = ChamberVoteService::voteTypeConfig(ExecutiveActService::GOVERNOR_REMOVAL_VOTE_TYPE);
        $this->assertSame('majority', $removalConfig['basis'],
            'Art. III §4 — the governor-removal vote class resolves to an ordinary-majority basis.');

        // The vote engine snapshots that as BASIS_MAJORITY, never BASIS_SUPERMAJORITY.
        $removalSnapshot = ChamberVoteService::laneThresholds(7, $this->basisFor($removalConfig['basis']));
        $this->assertNotSame(ChamberVote::BASIS_SUPERMAJORITY, $this->basisFor($removalConfig['basis']),
            'Art. III §4 — removal must never snapshot a supermajority basis.');
        $this->assertSame(ChamberVote::BASIS_MAJORITY, $this->basisFor($removalConfig['basis']));

        // CONTRAST: the impeachment class `officeholder_remove` IS
        // supermajority — the path the backend deliberately did NOT take.
        $impeachConfig = ChamberVoteService::voteTypeConfig('officeholder_remove');
        $this->assertSame('supermajority', $impeachConfig['basis'],
            'Art. II §3 — the impeachment class is supermajority; governor removal must not borrow it.');
        $this->assertNotSame(
            ExecutiveActService::GOVERNOR_REMOVAL_VOTE_TYPE,
            'officeholder_remove',
            'Art. III §4 — the two removal classes are deliberately distinct keys.'
        );

        // CONTRAST: the APPOINTMENT-consent class `bog_consent` is itself
        // ordinary majority — the appointment↔removal asymmetry lives in
        // the WORKFLOW (consent vs. removal), not in the threshold ladder.
        $consentConfig = ChamberVoteService::voteTypeConfig(BoardGovernorService::CONSENT_VOTE_TYPE);
        $this->assertSame('bog_consent', BoardGovernorService::CONSENT_VOTE_TYPE);
        $this->assertSame('majority', $consentConfig['basis'],
            'Art. III §4 — consent is ordinary majority of all serving (not supermajority).');

        // The snapshot really differs from the impeachment snapshot at the
        // same serving count: a supermajority peg sits strictly higher.
        $impeachSnapshot = ChamberVoteService::laneThresholds(7, ChamberVote::BASIS_SUPERMAJORITY);
        $this->assertLessThan(
            $impeachSnapshot['required_yes'],
            $removalSnapshot['required_yes'],
            'Art. III §4 — at 7 serving the ordinary-majority peg (4) sits strictly below the '
            .'supermajority peg (5): removal is the lighter, hiring-and-firing threshold.'
        );
    }

    /**
     * The snapshotted required_yes for the removal vote is exactly the
     * ordinary-majority peg quorum(serving) = floor(serving/2)+1, and it
     * is STRICTLY below the supermajority peg for every chamber the
     * constitution allows (serving ≥ 5 — Art. II §2 min seats).
     */
    public function test_required_yes_is_the_ordinary_majority_peg_below_supermajority(): void
    {
        foreach (range(1, 60) as $serving) {
            $removal = ChamberVoteService::laneThresholds($serving, ChamberVote::BASIS_MAJORITY);

            $this->assertSame(
                intdiv($serving, 2) + 1,
                $removal['required_yes'],
                "Art. III §4 — removal required_yes at serving={$serving} is the ordinary-majority "
                .'peg floor(serving/2)+1.'
            );

            // It also equals the quorum peg (an ordinary-majority vote's
            // required_yes IS the quorum — the PROTECTED helper).
            $this->assertSame(
                ConstitutionalValidator::quorum($serving),
                $removal['required_yes'],
                'Art. III §4 — the removal peg is the PROTECTED quorum() function, never reimplemented.'
            );

            // Below the supermajority peg for any real chamber (≥ 5 seats).
            if ($serving >= 5) {
                $this->assertLessThan(
                    ConstitutionalValidator::supermajority($serving),
                    $removal['required_yes'],
                    "Art. III §4 — removal must clear a LOWER bar than impeachment at serving={$serving}."
                );
            }
        }
    }

    /**
     * SOURCE pin: BoardGovernorService opens the removal vote through the
     * ordinary-majority constant and never reaches for `officeholder_remove`
     * or the supermajority basis directly — invariance by construction.
     */
    public function test_removal_service_never_references_the_impeachment_class(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(BoardGovernorService::class))->getFileName()
        );

        $this->assertStringContainsString(
            'ExecutiveActService::GOVERNOR_REMOVAL_VOTE_TYPE',
            $source,
            'Art. III §4 — requestRemoval opens the vote through the ordinary-majority constant.'
        );

        // The impeachment class may appear ONLY in the deliberate "NOT
        // `officeholder_remove`" disclaimer comments — never in executable
        // code. Strip every comment, then it must be wholly absent.
        $code = $this->stripPhpComments($source);

        $this->assertStringNotContainsString(
            'officeholder_remove',
            $code,
            'Art. III §4 — the governor pipeline must never borrow the supermajority impeachment '
            .'class in EXECUTABLE code (the disclaimer comment is the only allowed mention).'
        );

        $this->assertDoesNotMatchRegularExpression(
            "/voteType:\s*['\"]officeholder_remove['\"]/",
            $source,
            'Art. III §4 — no removal vote opens under the impeachment vote type.'
        );

        // The model itself documents the deliberate divergence (a second
        // architectural witness: not a removal_proceedings row).
        $modelSource = file_get_contents(
            (new \ReflectionClass(GovernorRemovalRequest::class))->getFileName()
        );
        $this->assertMatchesRegularExpression(
            '/ORDINARY-MAJORITY/i',
            $modelSource,
            'Art. III §4 — the GovernorRemovalRequest model pins the ordinary-majority intent.'
        );

        // The model is its OWN table, deliberately not removal_proceedings —
        // no executable line references the impeachment table.
        $modelCode = $this->stripPhpComments($modelSource);
        $this->assertStringNotContainsString(
            'removal_proceedings',
            $modelCode,
            'Art. III §4 — the model is not wired to the impeachment removal_proceedings table.'
        );
    }

    /** Strip // line comments and / * * / block comments (incl. docblocks). */
    private function stripPhpComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    // ======================================================================
    // 2. Live pins (guarded pg; one transaction, ALWAYS rolled back)
    // ======================================================================

    /**
     * THE live exit criterion: a real removal vote opened by the real
     * service pegs at the ordinary-majority threshold, and an ordinary
     * majority of all serving REMOVES the governor + vacates + re-seats.
     */
    public function test_ordinary_majority_removes_the_governor_and_reseats(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            $fx = $this->throwawayDepartmentWithSeatedGovernor($conn, servingMembers: 5);

            $service = app(BoardGovernorService::class);

            // ── File the removal through the REAL service API ────────────
            $result = $service->requestRemoval(
                $fx['governor_seat'],
                $fx['requester'],
                'Good-faith competence finding (throwaway).',
            );

            $vote = ChamberVote::query()->findOrFail($result['vote_id']);

            // The vote is the ORDINARY-MAJORITY class, basis MAJORITY.
            $this->assertSame(
                ExecutiveActService::GOVERNOR_REMOVAL_VOTE_TYPE,
                $vote->vote_type,
                'Art. III §4 — the live removal vote opens under the ordinary-majority class.'
            );
            $this->assertSame('procedural_motion', $vote->vote_type);
            $this->assertSame(ChamberVote::BASIS_MAJORITY, $vote->threshold_basis,
                'Art. III §4 — the live snapshot is MAJORITY, never supermajority.');
            $this->assertNotSame(ChamberVote::BASIS_SUPERMAJORITY, $vote->threshold_basis);
            $this->assertSame(ChamberVote::METHOD_YES_NO, $vote->vote_method);
            $this->assertSame('governor_removal', $vote->votable_type);

            // The single 'all' lane pegs at the ordinary-majority threshold.
            $tally = $vote->tallies()->where('lane', ChamberVoteTally::LANE_ALL)->firstOrFail();
            $this->assertSame(5, (int) $tally->serving, 'all five members are serving');
            $this->assertSame(3, (int) $tally->required_yes,
                'Art. III §4 — required_yes = floor(5/2)+1 = 3 (ordinary-majority peg).');
            $this->assertSame(
                ConstitutionalValidator::supermajority(5),
                4,
                'sanity: the supermajority peg at 5 serving would have been 4 — strictly higher.'
            );

            // The seat is parked pending the vote.
            $this->assertSame(
                BoardSeat::STATUS_REMOVAL_REQUESTED,
                $fx['governor_seat']->refresh()->status,
                'Art. III §4 — the seat → removal_requested at filing.'
            );

            // ── Cast an ORDINARY MAJORITY (3 of 5) yes ───────────────────
            $members = $fx['members'];
            $svc = app(ChamberVoteService::class);

            $svc->cast($vote, $members[0], VoteCast::VALUE_YES);
            $svc->cast($vote, $members[1], VoteCast::VALUE_YES);
            $svc->cast($vote, $members[2], VoteCast::VALUE_YES);
            $svc->cast($vote, $members[3], VoteCast::VALUE_NO);
            $svc->cast($vote, $members[4], VoteCast::VALUE_NO);

            $vote->refresh();
            $this->assertSame(ChamberVote::STATUS_CLOSED, $vote->status, 'auto-closes at full participation');
            $this->assertSame(ChamberVote::OUTCOME_ADOPTED, $vote->outcome,
                'Art. III §4 — 3 of 5 clears the ordinary-majority peg and ADOPTS the removal.');

            // ── The governor is removed; the seat vacates; the term closes ──
            $request = GovernorRemovalRequest::query()->findOrFail($result['request_id']);
            $this->assertSame(GovernorRemovalRequest::OUTCOME_REMOVED, $request->outcome);
            $this->assertNotNull($request->decided_at);

            $removedSeat = $fx['governor_seat']->refresh();
            $this->assertSame(BoardSeat::STATUS_REMOVED, $removedSeat->status,
                'Art. III §4 — the adopted removal vacates the governor seat.');
            $this->assertNull($removedSeat->holder_user_id);
            $this->assertNull($removedSeat->term_id);
            $this->assertFalse((bool) $removedSeat->is_chair);

            // The 10-year civil term closes as `removed`…
            $this->assertSame(
                Term::STATUS_REMOVED,
                Term::query()->findOrFail($fx['term']->id)->status,
                'Art. III §4 — the civil-appointment term closes on removal (never extended).'
            );

            // …and its CLK-09 timer is cancelled (no orphan expiry fires).
            $this->assertSame(
                ClockTimer::STATE_CANCELLED,
                ClockTimer::query()->findOrFail($fx['clk09']->id)->state,
                'Art. III §4 — the CLK-09 lockstep timer is cancelled when the term is cut short.'
            );

            // A fresh vacant governor seat opens for renomination (the loop).
            $vacantGovernorSeats = BoardSeat::query()
                ->where('board_id', $fx['board']->id)
                ->where('seat_class', BoardSeat::CLASS_GOVERNOR)
                ->where('status', BoardSeat::STATUS_VACANT)
                ->count();
            $this->assertSame(1, $vacantGovernorSeats,
                'Art. III §4 — removal reopens a vacant governor seat (WF-EXE-05 renomination loop).');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }

            DB::setDefaultConnection($originalDefault);
        }
    }

    /**
     * The mirror case: a tally that misses the ordinary-majority peg
     * RETAINS the governor and restores the seat (the threshold genuinely
     * gates — it is not a rubber stamp).
     */
    public function test_below_majority_retains_the_governor(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            $fx = $this->throwawayDepartmentWithSeatedGovernor($conn, servingMembers: 5);

            $service = app(BoardGovernorService::class);

            $result = $service->requestRemoval(
                $fx['governor_seat'],
                $fx['requester'],
                'Good-faith finding that will not carry (throwaway).',
            );

            $vote = ChamberVote::query()->findOrFail($result['vote_id']);
            $members = $fx['members'];
            $svc = app(ChamberVoteService::class);

            // 2 of 5 yes — below the peg of 3.
            $svc->cast($vote, $members[0], VoteCast::VALUE_YES);
            $svc->cast($vote, $members[1], VoteCast::VALUE_YES);
            $svc->cast($vote, $members[2], VoteCast::VALUE_NO);
            $svc->cast($vote, $members[3], VoteCast::VALUE_NO);
            $svc->cast($vote, $members[4], VoteCast::VALUE_NO);

            $vote->refresh();
            $this->assertSame(ChamberVote::STATUS_CLOSED, $vote->status);
            $this->assertSame(ChamberVote::OUTCOME_FAILED, $vote->outcome,
                'Art. III §4 — 2 of 5 misses the ordinary-majority peg of 3 and FAILS.');

            $request = GovernorRemovalRequest::query()->findOrFail($result['request_id']);
            $this->assertSame(GovernorRemovalRequest::OUTCOME_RETAINED, $request->outcome,
                'Art. III §4 — a failed removal RETAINS the governor.');

            $seat = $fx['governor_seat']->refresh();
            $this->assertSame(BoardSeat::STATUS_SEATED, $seat->status,
                'Art. III §4 — the seat is restored to seated when the removal fails.');
            $this->assertSame((string) $fx['governor']->getKey(), (string) $seat->holder_user_id);
            $this->assertSame((string) $fx['term']->id, (string) $seat->term_id, 'the term survives a failed removal');

            // The CLK-09 timer is untouched (the term lives on).
            $this->assertSame(
                ClockTimer::STATE_ARMED,
                ClockTimer::query()->findOrFail($fx['clk09']->id)->state,
                'Art. III §4 — a failed removal leaves the lockstep timer armed.'
            );
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }

            DB::setDefaultConnection($originalDefault);
        }
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    /** Map a registry basis to the ChamberVote threshold-basis constant (the open() rule). */
    private function basisFor(string $basis): string
    {
        return in_array($basis, ['supermajority', 'rcv_supermajority'], true)
            ? ChamberVote::BASIS_SUPERMAJORITY
            : ChamberVote::BASIS_MAJORITY;
    }

    /**
     * A fully isolated throwaway: jurisdiction → active legislature with
     * N seated members → committee executive with one seated member (the
     * requester) → chartered department whose board carries a SEATED
     * governor on a 10-year civil term + an armed CLK-09 timer.
     *
     * @return array{
     *   jurisdiction_id: string, legislature: Legislature, members: array<int, LegislatureMember>,
     *   executive: Executive, requester: ExecutiveMember, department: Department, board: Board,
     *   governor: User, governor_seat: BoardSeat, term: Term, clk09: ClockTimer
     * }
     */
    private function throwawayDepartmentWithSeatedGovernor(Connection $conn, int $servingMembers): array
    {
        $jurisdictionId = (string) Str::uuid();

        $conn->table('jurisdictions')->insert([
            'id' => $jurisdictionId,
            'name' => 'GovernorRemoval Throwaway '.Str::random(6),
            'slug' => 'gov-removal-'.strtolower(Str::random(10)),
            'adm_level' => 4,
            'is_active' => true,
            'source' => 'user_defined',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ── Legislature with N serving members ──────────────────────────
        $legislature = Legislature::create([
            'jurisdiction_id' => $jurisdictionId,
            'term_number' => 1,
            'status' => Legislature::STATUS_ACTIVE,
            'total_seats' => $servingMembers,
            'type_a_seats' => $servingMembers,
            'type_b_seats' => 0,
        ]);

        $members = [];
        for ($i = 0; $i < $servingMembers; $i++) {
            $user = $this->throwawayUser("member-{$i}");

            $members[] = LegislatureMember::create([
                'legislature_id' => (string) $legislature->id,
                'user_id' => (string) $user->getKey(),
                'seat_type' => 'a',
                'seat_no' => $i + 1,
                'status' => LegislatureMember::STATUS_SEATED,
            ]);
        }

        // ── Committee executive with one seated member (the requester) ──
        $executive = Executive::create([
            'id' => (string) Str::uuid(),
            'jurisdiction_id' => $jurisdictionId,
            'type' => Executive::TYPE_COMMITTEE,
            'term_number' => 1,
            'status' => Executive::STATUS_DELEGATED,
        ]);

        $requester = ExecutiveMember::create([
            'id' => (string) Str::uuid(),
            'executive_id' => (string) $executive->id,
            'user_id' => (string) $this->throwawayUser('exec-member')->getKey(),
            'role' => ExecutiveMember::ROLE_PRINCIPAL,
            'rank' => 0,
            'selection' => ExecutiveMember::SELECTION_DELEGATED_PROPORTIONAL,
            'status' => ExecutiveMember::STATUS_SEATED,
        ]);

        // ── Department + board + SEATED governor ────────────────────────
        // A minimal charter law (departments.charter_law_id is NOT NULL).
        $charterLaw = \App\Models\Law::create([
            'jurisdiction_id' => $jurisdictionId,
            'legislature_id' => (string) $legislature->id,
            'act_number' => 'THROWAWAY-'.strtoupper(Str::random(6)),
            'title' => 'Throwaway Department Charter',
            'kind' => \App\Models\Law::KIND_CHARTER,
            'scale' => [],
            'origin' => \App\Models\Law::ORIGIN_BILL,
            'status' => \App\Models\Law::STATUS_IN_FORCE,
            'current_version_no' => 1,
            'effective_at' => now(),
            'enacted_at' => now(),
        ]);

        $department = Department::create([
            'jurisdiction_id' => $jurisdictionId,
            'executive_id' => (string) $executive->id,
            'kind' => Department::KIND_TREASURY,
            'name' => 'Throwaway Treasury '.Str::random(5),
            'charter_law_id' => (string) $charterLaw->id,
            'status' => Department::STATUS_OPERATING,
        ]);

        $board = Board::create([
            'boardable_type' => Board::BOARDABLE_DEPARTMENTS,
            'boardable_id' => (string) $department->id,
            'owner_seats' => 1,
            'worker_seats' => 0,
            'status' => Board::STATUS_ACTIVE,
        ]);

        $department->forceFill(['board_id' => (string) $board->id])->save();

        $governor = $this->throwawayUser('governor');

        // The seated governor's appointment + 10-year civil term + CLK-09.
        $appointment = Appointment::create([
            'appointable_type' => 'board_seats',
            'appointable_id' => (string) Str::uuid(), // back-filled below
            'nominee_user_id' => (string) $governor->getKey(),
            'nominated_via_form' => 'F-EXE-001',
            'status' => Appointment::STATUS_NOMINATED,
        ]);

        $term = Term::create([
            'office_kind' => 'board_governor',
            'office_type' => 'board_seats',
            'office_id' => (string) Str::uuid(), // back-filled below
            'holder_user_id' => (string) $governor->getKey(),
            'jurisdiction_id' => $jurisdictionId,
            'legislature_id' => (string) $legislature->id,
            'term_class' => Term::CLASS_CIVIL_APPOINTMENT,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addYears(10)->toDateString(),
            'source_appointment_id' => (string) $appointment->id,
            'status' => Term::STATUS_ACTIVE,
        ]);

        $governorSeat = BoardSeat::create([
            'board_id' => (string) $board->id,
            'seat_class' => BoardSeat::CLASS_GOVERNOR,
            'seat_no' => 1,
            'holder_user_id' => (string) $governor->getKey(),
            'appointment_id' => (string) $appointment->id,
            'term_id' => (string) $term->id,
            'status' => BoardSeat::STATUS_SEATED,
        ]);

        // Back-fill the polymorphic office pointers now that the seat exists.
        $appointment->forceFill(['appointable_id' => (string) $governorSeat->id, 'term_id' => (string) $term->id])->save();
        $term->forceFill(['office_id' => (string) $governorSeat->id])->save();

        // The armed CLK-09 lockstep timer the removal must cancel.
        $clk09 = ClockTimer::create([
            'id' => (string) Str::uuid(),
            'clock_id' => 'CLK-09',
            'jurisdiction_id' => $jurisdictionId,
            'subject_type' => 'term',
            'subject_id' => (string) $term->id,
            'armed_at' => now(),
            'fires_at' => now()->addYears(10),
            'state' => ClockTimer::STATE_ARMED,
            'payload' => ['derive' => 'civil_appointment_years'],
        ]);

        return [
            'jurisdiction_id' => $jurisdictionId,
            'legislature' => $legislature,
            'members' => $members,
            'executive' => $executive,
            'requester' => $requester,
            'department' => $department,
            'board' => $board,
            'governor' => $governor,
            'governor_seat' => $governorSeat,
            'term' => $term,
            'clk09' => $clk09,
        ];
    }

    private function throwawayUser(string $label): User
    {
        return User::create([
            'name' => "GovernorRemoval {$label}",
            'email' => 'gov-removal-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
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
