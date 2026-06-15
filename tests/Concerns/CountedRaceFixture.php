<?php

namespace Tests\Concerns;

use App\Domain\Ballots\BallotBox;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\ElectionBoard;
use App\Models\ElectionBoardMember;
use App\Models\ElectionRace;
use App\Models\Legislature;
use App\Models\Tabulation;
use App\Models\User;
use App\Services\ElectionLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds a small, real, COUNTED STV race on the live-pg connection — a board, a
 * chamber, an election (RANKED_OPEN), one race, four candidacies, nine ballots
 * through the secrecy boundary, then a true tabulation with a sealed record_hash.
 * Mirrors tests/Feature/TabulationCertificationPipelineTest so the Phase G ballot
 * pins (re-wrap, operational bundle) share one trustworthy fixture.
 *
 * Must run inside a live-pg transaction (the using test sets the default
 * connection and rolls back).
 */
trait CountedRaceFixture
{
    /**
     * @return array{0: Election, 1: ElectionRace, 2: string} [election, race, certified record_hash]
     */
    protected function buildCountedRace(): array
    {
        $jurisdictionId = $this->boardFreeJurisdiction();

        $board = ElectionBoard::create([
            'jurisdiction_id' => $jurisdictionId,
            'is_bootstrap'    => true,
            'status'          => 'active',
        ]);
        ElectionBoardMember::create([
            'election_board_id' => $board->id,
            'user_id'           => null,
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
            'seats'           => 2,
            'finalist_count'  => 15,
            'status'          => Election::STATUS_RANKED_OPEN,
        ]);

        $candidacies = [];
        foreach (['A', 'B', 'C', 'D'] as $label) {
            $candidacies[$label] = Candidacy::create([
                'election_id'           => $election->id,
                'race_id'               => $race->id,
                'user_id'               => $this->throwawayUser("Cand {$label}")->id,
                'status'                => Candidacy::STATUS_FINALIST,
                'residency_attested_at' => now(),
                'validated_at'          => now(),
            ]);
        }

        $box = app(BallotBox::class);
        foreach ([['A', 4], ['B', 3], ['C', 2]] as [$first, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $box->commit(
                    $this->throwawayUser("Voter {$first}{$i}"),
                    $race,
                    [(string) $candidacies[$first]->id, (string) $candidacies['D']->id],
                );
            }
        }

        $lifecycle = app(ElectionLifecycleService::class);
        $lifecycle->closeRanked($election->fresh());
        (new \App\Jobs\Elections\TabulateElectionJob((string) $election->id))->handle($lifecycle);

        $tabulation = Tabulation::query()
            ->where('race_id', $race->id)
            ->where('kind', Tabulation::KIND_INITIAL)
            ->where('status', Tabulation::STATUS_COMPLETE)
            ->firstOrFail();

        return [$election->fresh(), $race->fresh(), (string) $tabulation->record_hash];
    }

    protected function boardFreeJurisdiction(): string
    {
        $id = DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->whereNotIn('id', fn ($q) => $q->select('jurisdiction_id')->from('election_boards')
                ->where('status', 'active')->whereNull('deleted_at'))
            ->value('id');

        if ($id === null) {
            $this->markTestSkipped('Live DB has no board-free jurisdiction — seed it first.');
        }

        return (string) $id;
    }

    protected function throwawayUser(string $name): User
    {
        return User::create([
            'name'              => "Phase G Throwaway {$name}",
            'email'             => 'phaseg-'.Str::uuid().'@test.invalid',
            'password'          => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }
}
