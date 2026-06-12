<?php

namespace Tests\Feature;

use App\Domain\Ballots\BallotBox;
use App\Domain\Engine\ConstitutionalEngine;
use App\Jobs\Elections\TabulateElectionJob;
use App\Models\Ballot;
use App\Models\Candidacy;
use App\Models\ClockTimer;
use App\Models\Election;
use App\Models\ElectionBoard;
use App\Models\ElectionBoardMember;
use App\Models\ElectionRace;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Tabulation;
use App\Models\Term;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\AuditService;
use App\Services\ElectionLifecycleService;
use App\Services\RoleService;
use App\Services\TabulationRecorder;
use App\Services\VacancyService;
use App\Services\VoteCountingService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WI-B5/WI-B6 — the full tabulation → certification → seating → vacancy
 * pipeline, end to end, against the LIVE PostgreSQL (same guarded
 * connection + always-rolled-back transaction posture as
 * BallotSecrecyTest; SKIPPED when pg is unreachable, e.g. outside the app
 * container — the pins then fall to the WI-B5 tinker verification).
 *
 * One fabricated 5-seat at-large race, 24 voters, 6 candidacies with
 * engineered rankings (quota = ⌊24/6⌋+1 = 5):
 *
 *     6 × [A,F]   5 × [B,F]   5 × [C,F]   4 × [D,F]   4 × [E,F]
 *
 *   base count → A,B,C,D,E elected (F eliminated; D,E shortcut-filled)
 *   countback minus A → F,B,C,D,E → replacement = F  (found branch)
 *   countback minus {A,B} → 4 candidates / 5 seats, all sitting
 *                         → exhausted → special election (failed branch)
 *
 * Pins, in pipeline order: ESM-03 close → tabulating; tabulations/rounds/
 * race_results rows with a record_hash that REPRODUCES from an
 * independent re-count; tabulation idempotency (re-dispatch leaves
 * exactly one complete initial tabulation); vote_share_norm =
 * tally-at-election / quota; F-ELB-004 through the REAL engine (system
 * actor, bootstrap board provenance) seats members status 'elected' with
 * lockstep terms (ends = starts + election_interval_months), flips the
 * legislature active, arms CLK-01 (next cycle) + CLK-10 (per term), opens
 * the successor approval phase, derives R-09; countback fills a vacancy
 * with the INHERITED original expiry; an exhausted countback
 * auto-schedules the special election inside the Art. II §5 window with
 * the CLK-04 backstop armed; the audit chain verifies green over
 * everything appended.
 */
class TabulationCertificationPipelineTest extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_pipeline_test';

    public function test_full_pipeline_tabulation_certification_seating_vacancy(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            $audit    = app(AuditService::class);
            $seqStart = $audit->latestSeq();

            // ── Fabricate the chamber, board, election, race ──────────────
            $jurisdictionId = $this->jurisdictionWithoutActiveBoard($conn);

            $board = ElectionBoard::create([
                'jurisdiction_id' => $jurisdictionId,
                'is_bootstrap'    => true,
                'status'          => 'active',
            ]);
            ElectionBoardMember::create([
                'election_board_id' => $board->id,
                'user_id'           => null, // the system itself (design §A B-2)
                'status'            => 'seated',
            ]);

            $legislature = Legislature::create([
                'jurisdiction_id' => $jurisdictionId,
                'status'          => Legislature::STATUS_FORMING,
                'total_seats'     => 5,
                'type_a_seats'    => 5,
                'type_b_seats'    => 0,
                'term_number'     => 1,
                'quorum_required' => 3,
            ]);

            $election = Election::create([
                'jurisdiction_id'   => $jurisdictionId,
                'legislature_id'    => $legislature->id,
                'kind'              => Election::KIND_GENERAL,
                'status'            => Election::STATUS_RANKED_OPEN,
                'trigger'           => 'manual',
                'voting_method'     => 'stv_droop',
                'election_board_id' => $board->id,
            ]);

            $race = ElectionRace::create([
                'election_id'     => $election->id,
                'jurisdiction_id' => $jurisdictionId,
                'seat_kind'       => ElectionRace::SEAT_KIND_TYPE_A,
                'seats'           => 5,
                'finalist_count'  => 15,
                'status'          => Election::STATUS_RANKED_OPEN,
            ]);

            // ── 6 candidacies (A..F), associated inside the footprint ─────
            $candidacies = [];
            foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $label) {
                $user = $this->throwawayUser("Candidate {$label}");

                $conn->table('residency_confirmations')->insert([
                    'id'              => (string) Str::uuid(),
                    'user_id'         => $user->id,
                    'jurisdiction_id' => $jurisdictionId,
                    'days_confirmed'  => 30,
                    'confirmed_at'    => now(),
                    'is_active'       => true,
                    'depth'           => 0,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                $candidacies[$label] = Candidacy::create([
                    'election_id'           => $election->id,
                    'race_id'               => $race->id,
                    'user_id'               => $user->id,
                    'status'                => Candidacy::STATUS_FINALIST,
                    'residency_attested_at' => now(),
                    'validated_at'          => now(),
                ]);
            }

            // ── 24 engineered ballots through the secrecy boundary ────────
            $box = app(BallotBox::class);

            $pattern = [['A', 6], ['B', 5], ['C', 5], ['D', 4], ['E', 4]];
            foreach ($pattern as [$first, $count]) {
                for ($i = 0; $i < $count; $i++) {
                    $box->commit(
                        $this->throwawayUser("Voter {$first}{$i}"),
                        $race,
                        [(string) $candidacies[$first]->id, (string) $candidacies['F']->id],
                    );
                }
            }

            // ── Close the window, tabulate (inline — sync queue) ──────────
            $lifecycle = app(ElectionLifecycleService::class);
            $lifecycle->closeRanked($election->refresh());

            (new TabulateElectionJob((string) $election->id))->handle($lifecycle);

            $election->refresh();
            $race->refresh();

            $this->assertSame(Election::STATUS_TABULATING, $election->status);
            $this->assertSame(5, $race->quota, 'Droop: floor(24/6)+1 = 5.');
            $this->assertSame(24, $race->total_valid_ballots);

            $tabulation = Tabulation::query()
                ->where('race_id', $race->id)
                ->where('kind', Tabulation::KIND_INITIAL)
                ->where('status', Tabulation::STATUS_COMPLETE)
                ->firstOrFail();

            $this->assertNotNull($tabulation->record_hash);
            $this->assertSame(VoteCountingService::ENGINE_VERSION, $tabulation->engine_version);
            $this->assertSame(0, Ballot::query()->where('race_id', $race->id)->where('counted', false)->count());

            // The sealed hash REPRODUCES from an independent re-count of
            // the same inputs — the whole point of the record.
            $recount = app(VoteCountingService::class)->countStv(
                app(TabulationRecorder::class)->countInput($race)
            );
            $this->assertSame($recount->recordHash(), $tabulation->record_hash);
            $this->assertSame(
                count($recount->rounds),
                $conn->table('tabulation_rounds')->where('tabulation_id', $tabulation->id)->count(),
                'Stored rounds must match the count record.'
            );

            // Winners: A,B,C,D,E — F eliminated.
            $electedIds = array_column($recount->elected, 'candidacy_id');
            foreach (['A', 'B', 'C', 'D', 'E'] as $label) {
                $this->assertContains((string) $candidacies[$label]->id, $electedIds);
            }
            $this->assertNotContains((string) $candidacies['F']->id, $electedIds);

            // ── Idempotency: re-dispatch changes nothing ──────────────────
            (new TabulateElectionJob((string) $election->id))->handle($lifecycle);

            $this->assertSame(
                1,
                Tabulation::query()->where('race_id', $race->id)
                    ->where('kind', Tabulation::KIND_INITIAL)
                    ->where('status', Tabulation::STATUS_COMPLETE)->count(),
                'Re-dispatch must not produce a second initial tabulation (tabulations.status authority).'
            );
            $this->assertSame(
                count($recount->rounds),
                $conn->table('tabulation_rounds')->where('tabulation_id', $tabulation->id)->count()
            );

            $this->assertSame(
                1,
                $conn->table('audit_log')->where('event', 'election.tabulated')
                    ->where('payload->election_id', (string) $election->id)->count(),
                'Exactly one ready-to-certify watermark.'
            );

            // ── F-ELB-004 through the REAL engine (system-as-board) ───────
            $result = app(ConstitutionalEngine::class)->file('F-ELB-004', null, [
                'election_id'     => (string) $election->id,
                'jurisdiction_id' => $jurisdictionId,
            ]);

            $this->assertSame(Election::STATUS_CERTIFIED, $election->refresh()->status);

            // Seated: 5 members, status 'elected', vote_share_norm set.
            $members = LegislatureMember::query()
                ->where('legislature_id', $legislature->id)
                ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
                ->get();

            $this->assertCount(5, $members);

            $byUser = $members->keyBy(fn ($m) => (string) $m->user_id);

            // vote_share_norm = tally-at-election / quota: A=6/5, B=C=5/5, D=E=4/5.
            $expectedShares = ['A' => '1.2000', 'B' => '1.0000', 'C' => '1.0000', 'D' => '0.8000', 'E' => '0.8000'];
            foreach ($expectedShares as $label => $share) {
                $member = $byUser[(string) $candidacies[$label]->user_id];
                $this->assertSame($share, (string) $member->vote_share_norm, "vote_share_norm({$label})");
                $this->assertSame(LegislatureMember::STATUS_ELECTED, $member->status);
                $this->assertNotNull($member->term_id);
            }

            // Lockstep terms: starts = certification day, ends = +60 months.
            $terms = Term::query()->where('legislature_id', $legislature->id)->where('status', Term::STATUS_ACTIVE)->get();
            $this->assertCount(5, $terms);

            $legislature->refresh();
            $this->assertSame(Legislature::STATUS_ACTIVE, $legislature->status);
            $this->assertNotNull($legislature->term_ends_on);

            foreach ($terms as $term) {
                $this->assertSame(Term::CLASS_LOCKSTEP, $term->term_class);
                $this->assertSame($legislature->term_starts_on->toDateString(), $term->starts_on->toDateString());
                $this->assertSame($legislature->term_ends_on->toDateString(), $term->ends_on->toDateString());
                $this->assertSame(
                    $term->starts_on->copy()->addMonthsNoOverflow(60)->toDateString(),
                    $term->ends_on->toDateString(),
                    'Lockstep ends_on = starts + election_interval_months (default 60).'
                );
            }

            // Clocks: CLK-01 next cycle (legislature) + CLK-10 per term.
            $this->assertSame(1, ClockTimer::query()->armed()
                ->where('clock_id', 'CLK-01')->where('subject_type', 'legislature')
                ->where('subject_id', $legislature->id)->count());
            $this->assertSame(5, ClockTimer::query()->armed()
                ->where('clock_id', 'CLK-10')->where('subject_type', 'term')
                ->whereIn('subject_id', $terms->pluck('id'))->count());

            // Successor: election N+1 with its approval phase open.
            $successorId = $result->recorded['next_election_id'] ?? null;
            $this->assertNotNull($successorId);
            $successor = Election::query()->findOrFail($successorId);
            $this->assertSame(Election::STATUS_APPROVAL_OPEN, $successor->status);
            $this->assertSame((string) $election->id, (string) $successor->prior_election_id);
            $this->assertTrue($successor->races()->exists(), 'Successor races generate from the same plan.');

            // R-09 derives from the seat rows.
            $roleService = app(RoleService::class);
            $roleService->flush();
            $this->assertContains('R-09', $roleService->rolesFor($candidacies['A']->user()->first()));

            // ── Vacancy 1: countback FINDS a winner (F) ───────────────────
            $vacancies = app(VacancyService::class);

            $memberA = $members->first(fn ($m) => (string) $m->user_id === (string) $candidacies['A']->user_id);
            $vacancy = $vacancies->declare($memberA, 'resigned', queueCountback: false);
            $vacancy = $vacancies->runCountback($vacancy);

            $this->assertSame(Vacancy::STATUS_FILLED, $vacancy->status);
            $this->assertSame((string) $candidacies['F']->user_id, (string) $vacancy->filled_by_user_id);

            $memberF = LegislatureMember::query()
                ->where('legislature_id', $legislature->id)
                ->where('user_id', $candidacies['F']->user_id)
                ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
                ->firstOrFail();

            // THE CLK-10 pin: the replacement term inherits the ORIGINAL expiry.
            $termF = Term::query()->findOrFail($memberF->term_id);
            $this->assertSame(
                $legislature->term_ends_on->toDateString(),
                $termF->ends_on->toDateString(),
                'Countback replacement term must inherit the original lockstep expiry — never a fresh term.'
            );
            $this->assertSame($memberA->seat_no, $memberF->seat_no, 'The vacated seat number is reused.');

            $this->assertSame(
                5,
                LegislatureMember::query()->where('legislature_id', $legislature->id)
                    ->whereIn('status', LegislatureMember::CURRENT_STATUSES)->count(),
                'Chamber back to full strength.'
            );

            $countbackTab = Tabulation::query()->findOrFail($vacancy->countback_tabulation_id);
            $this->assertSame(Tabulation::KIND_COUNTBACK, $countbackTab->kind);
            $this->assertSame((string) $candidacies['A']->id, (string) $countbackTab->excluded_candidacy_id);

            // ── Vacancy 2: countback EXHAUSTS → special election ──────────
            $memberB = LegislatureMember::query()
                ->where('legislature_id', $legislature->id)
                ->where('user_id', $candidacies['B']->user_id)
                ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
                ->firstOrFail();

            $vacancy2 = $vacancies->declare($memberB, 'resigned', queueCountback: false);
            $vacancy2 = $vacancies->runCountback($vacancy2);

            $this->assertSame(Vacancy::STATUS_SPECIAL_SCHEDULED, $vacancy2->status);
            $this->assertNotNull($vacancy2->special_election_id);

            $special = Election::query()->findOrFail($vacancy2->special_election_id);
            $this->assertSame(Election::KIND_SPECIAL, $special->kind);
            $this->assertSame((string) $vacancy2->id, (string) $special->vacancy_id);
            $this->assertSame(Election::STATUS_APPROVAL_OPEN, $special->status);

            // Ranked window inside [declared+90d, declared+180d] (Art. II §5).
            $declaredAt = $vacancy2->declared_at;
            $this->assertTrue($special->ranked_opens_at->gte($declaredAt->copy()->addDays(90)));
            $this->assertTrue($special->ranked_closes_at->lte($declaredAt->copy()->addDays(180)));

            // CLK-04 backstop armed at the window close.
            $backstop = ClockTimer::query()->armed()
                ->where('clock_id', 'CLK-04')
                ->where('subject_type', 'vacancy')
                ->where('subject_id', $vacancy2->id)
                ->firstOrFail();
            $this->assertSame('special_window_close', $backstop->payload['step'] ?? null);

            // The special election's single race covers exactly the vacant seat.
            $specialRace = $special->races()->firstOrFail();
            $this->assertSame(1, (int) $specialRace->seats);

            // ── Chain integrity over everything this test appended ────────
            $this->assertTrue($audit->verifyChain($seqStart + 1));
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }

            DB::setDefaultConnection($originalDefault);
        }
    }

    // ======================================================================
    // Plumbing (same posture as BallotSecrecyTest)
    // ======================================================================

    private function livePg(): Connection
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql not loaded — live pipeline pins run inside the app container.');
        }

        config([
            'database.connections.' . self::LIVE_CONNECTION => array_merge(
                config('database.connections.pgsql'),
                ['database' => env('LIVE_PG_DATABASE', 'fair_constitution')]
            ),
        ]);

        try {
            $connection = DB::connection(self::LIVE_CONNECTION);
            $connection->getPdo();

            return $connection;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Live PostgreSQL unreachable — run inside the app container. (' . $e->getMessage() . ')');
        }
    }

    /** A jurisdiction with no active election board (partial-unique safe). */
    private function jurisdictionWithoutActiveBoard(Connection $conn): string
    {
        $id = $conn->table('jurisdictions')
            ->whereNull('deleted_at')
            ->whereNotIn('id', fn ($q) => $q->select('jurisdiction_id')->from('election_boards')
                ->where('status', 'active')->whereNull('deleted_at'))
            ->value('id');

        $this->assertNotNull($id, 'Live DB has no board-free jurisdiction — seed it first.');

        return (string) $id;
    }

    private function throwawayUser(string $name): User
    {
        return User::create([
            'name'              => "Pipeline Throwaway {$name}",
            'email'             => 'pipeline-' . Str::uuid() . '@test.invalid',
            'password'          => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }
}
