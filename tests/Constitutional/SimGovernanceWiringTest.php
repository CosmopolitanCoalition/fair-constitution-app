<?php

namespace Tests\Constitutional;

use App\Console\Commands\SimPumpCommand;
use App\Jobs\SimWorkerJob;
use App\Models\SimRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the growth dial is wired into the sim pump, and it never
 * runs away.
 *
 * GovernanceStage (the §5 growth dial) is complete on its own; this proves the
 * AUTONOMY wiring the desk greenlit post-gate: a demo populate run reaches a
 * `governance` phase after `seating`, mints one work item per seated
 * jurisdiction, and the worker dispatches it to the dial — which matures the
 * chamber through the real forms or defers with a reason, so the item always
 * COMPLETES rather than looping.
 *
 * THE INVARIANTS:
 *
 *  1. THE PHASE EXISTS AND SITS AFTER SEATING. A chamber must be seated before
 *     it can grow, and the acceptance scan (`verifying`) runs last so it sees
 *     the matured world: seating → governance → verifying → done.
 *  2. THE MINT IS ONE-PER-JURISDICTION AND IDEMPOTENT. A place with a seated
 *     chamber gets exactly one `governance_scope` item, even across multiple
 *     seated elections, and a second mint pass adds nothing.
 *  3. THE WORKER DISPATCHES IT TO THE DIAL, AND THE ITEM COMPLETES. Running the
 *     item matures the chamber through real adopted votes (committees carry
 *     `created_by_vote_id`). Crucially, execute() RETURNS — the worker settles
 *     DONE — it does not throw.
 *  4. ⚑ NO RUNAWAY. A chamber that cannot grow (bicameral, Type B half unseated)
 *     still COMPLETES its item, with the deferral reason recorded — it is not a
 *     failure, not a retry, not a REVIEW row. A growth dial that threw or looped
 *     on an ungovernable chamber would storm the pump; this one records and
 *     moves on.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class SimGovernanceWiringTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_sim_gov_wiring';

    public function test_the_governance_phase_sits_between_seating_and_verifying(): void
    {
        $phases = SimRun::PHASES;

        $seating = array_search('seating', $phases, true);
        $governance = array_search('governance', $phases, true);
        $judiciary = array_search('judiciary', $phases, true);
        $verifying = array_search('verifying', $phases, true);

        $this->assertNotFalse($governance, 'a governance phase must exist');
        $this->assertSame($seating + 1, $governance, 'governance runs immediately after seating');
        // The bench phase (operator 2026-08-08 — the courtroom gap): the
        // matured world now includes the judge pools, so verifying moves to
        // after JUDICIARY. F-LEG-017 needs seated chambers (governance-era
        // state), and the scan must see the benches.
        $this->assertNotFalse($judiciary, 'a judiciary phase must exist');
        $this->assertSame($governance + 1, $judiciary, 'the bench forms immediately after the growth dial');
        // Census-flavored civics (rubric sim-org-bill-rates = B, 2026-08-08):
        // orgs + bills follow the bench; verifying still runs LAST so the
        // scan sees the fully matured world.
        $civics = array_search('civics', $phases, true);
        $this->assertNotFalse($civics, 'a civics phase must exist');
        $this->assertSame($judiciary + 1, $civics, 'civics (orgs + bills) follows the bench');
        // Training (W7 item 7, ruling edu-arming A): pre-train the fleet AFTER
        // the content stages, so arming the gate never blocks their gated forms.
        $training = array_search('training', $phases, true);
        $this->assertNotFalse($training, 'a training phase must exist');
        $this->assertSame($civics + 1, $training, 'training (pre-train the fleet) follows civics');
        $this->assertSame($training + 1, $verifying, 'verifying (the acceptance scan) runs last, after the fleet is trained');

        $this->assertSame(['governance_scope'], SimRun::PHASE_KINDS['governance']);
        $this->assertSame(['judiciary_scope'], SimRun::PHASE_KINDS['judiciary']);
        $this->assertSame(['civics_scope'], SimRun::PHASE_KINDS['civics']);
        $this->assertSame(['training_scope'], SimRun::PHASE_KINDS['training']);
    }

    public function test_the_pump_mints_one_governance_item_per_seated_jurisdiction_and_is_idempotent(): void
    {
        $this->onLivePg(function () {
            [$runId, $jurId] = $this->seatedRun(members: 12, typeBSeats: 0);

            $minted = $this->mint($runId, 'governance');
            $this->assertSame(1, $minted, 'one governance item for the seated jurisdiction');

            $items = DB::table('sim_items')
                ->where('run_id', $runId)
                ->where('kind', 'governance_scope')
                ->get();

            $this->assertCount(1, $items);
            $this->assertSame($jurId, (string) $items->first()->jurisdiction_id);
            $this->assertSame($jurId, (string) $items->first()->unit_key, 'the growth dial is keyed on the jurisdiction, not an election');

            // Idempotent: a second mint pass adds nothing.
            $this->assertSame(0, $this->mint($runId, 'governance'), 're-minting must add nothing');
            $this->assertSame(1, DB::table('sim_items')->where('run_id', $runId)->where('kind', 'governance_scope')->count());
        });
    }

    public function test_the_worker_dispatches_the_item_to_the_dial_and_it_completes(): void
    {
        $this->onLivePg(function () {
            [$runId, $jurId, $legId] = $this->seatedRun(members: 12, typeBSeats: 0);
            $this->mint($runId, 'governance');

            $item = DB::table('sim_items')->where('run_id', $runId)->where('kind', 'governance_scope')->first();
            $run = SimRun::query()->find($runId);

            // The worker's dispatch path — execute() RETURNS (it does not throw),
            // so the worker settles the item DONE.
            $result = $this->execute($run, $item);

            $this->assertArrayHasKey('committees', $result, 'the dispatch reached GovernanceStage');
            $this->assertArrayHasKey('departments', $result);
            $this->assertSame(2, $result['committees']['created'], 'K(12) committees grew through the dial');

            // Proof it went through real adopted votes, not a direct write.
            $committees = DB::table('committees')->where('legislature_id', $legId)->whereNull('deleted_at')->get();
            $this->assertNotEmpty($committees);
            foreach ($committees as $c) {
                $this->assertNotNull($c->created_by_vote_id, 'the pump-driven dial still mints nothing — every committee carries its adopting vote');
            }
        });
    }

    public function test_an_ungovernable_chamber_completes_its_item_rather_than_storming_the_pump(): void
    {
        $this->onLivePg(function () {
            // Bicameral, Type B half unseated — the growth dial cannot pass a
            // committee act (Art. V §3). The item must still COMPLETE.
            [$runId, $jurId] = $this->seatedRun(members: 5, typeBSeats: 16);
            $this->mint($runId, 'governance');

            $item = DB::table('sim_items')->where('run_id', $runId)->where('kind', 'governance_scope')->first();
            $run = SimRun::query()->find($runId);

            // ⚑ THE NO-RUNAWAY GUARANTEE: execute() RETURNS (does not throw), so
            // the worker settles DONE with the reason — not REVIEW, not a retry.
            $result = $this->execute($run, $item);

            $this->assertSame(0, $result['committees']['created'], 'nothing grows on an ungovernable chamber');
            $this->assertStringContainsString(
                'unseated Type B half',
                (string) $result['committees']['skipped'],
                'the deferral reason is recorded on the completed item'
            );
        });
    }

    /** Call the pump's private mint for one phase. */
    private function mint(string $runId, string $phase): int
    {
        $run = SimRun::query()->find($runId);
        $cmd = app(SimPumpCommand::class);
        $ref = new \ReflectionMethod($cmd, 'mintWorklist');
        $ref->setAccessible(true);

        return (int) $ref->invoke($cmd, $run, $phase);
    }

    /** Call the worker's private dispatch for one item. */
    private function execute(SimRun $run, object $item): array
    {
        $job = new SimWorkerJob($run->id);
        $ref = new \ReflectionMethod($job, 'execute');
        $ref->setAccessible(true);

        // Item 1 (W7): execute builds its heartbeat closure from the lease token.
        // A synthetic token suffices — touch() is a no-op when no lease row
        // matches, and these tests assert the stage completes, not that it beat.
        return (array) $ref->invoke($job, $run, $item, (string) Str::uuid());
    }

    /**
     * A run in the seating phase with a seated chamber and a `done` seat_scope
     * item — the exact predecessor state the governance mint keys off.
     *
     * @return array{0:string,1:string,2:string} [runId, jurisdictionId, legislatureId]
     */
    private function seatedRun(int $members, int $typeBSeats): array
    {
        $tag = 'simgov-'.Str::lower(Str::random(6));

        $runId = (string) Str::uuid();
        DB::table('sim_runs')->insert([
            'id' => $runId, 'status' => 'running', 'phase' => 'seating',
            'options' => json_encode(['version' => 1]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $jurId = (string) Str::uuid();
        DB::table('jurisdictions')->insert([
            'id' => $jurId, 'name' => $tag, 'slug' => $tag,
            'adm_level' => 2, 'population' => 33_000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A forming executive (the shell provisioner's output) so the department
        // half has something to delegate.
        DB::table('executives')->insert([
            'id' => (string) Str::uuid(), 'jurisdiction_id' => $jurId,
            'type' => 'committee', 'term_number' => 1, 'status' => 'forming',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $legId = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id' => $legId, 'jurisdiction_id' => $jurId, 'term_number' => 1,
            'status' => 'active', 'total_seats' => max(5, $members),
            'type_a_seats' => max(5, $members) - $typeBSeats, 'type_b_seats' => $typeBSeats,
            'quorum_required' => (int) ceil(max(5, $members) / 2),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        for ($i = 0; $i < $members; $i++) {
            $userId = (string) Str::uuid();
            DB::table('users')->insert([
                'id' => $userId, 'name' => "{$tag}-m{$i}", 'email' => "{$tag}-m{$i}@example.test",
                'password' => bcrypt('password'), 'terms_accepted_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('legislature_members')->insert([
                'id' => (string) Str::uuid(), 'legislature_id' => $legId, 'user_id' => $userId,
                'seat_type' => 'A', 'seat_no' => $i + 1, 'status' => 'elected',
                'seated_on' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // The predecessor: a certified election with a DONE seat_scope item. The
        // mint keys off e.jurisdiction_id via that item's unit_key = election id.
        $electionId = (string) Str::uuid();
        DB::table('elections')->insert([
            'id' => $electionId, 'jurisdiction_id' => $jurId, 'legislature_id' => $legId,
            'kind' => 'general', 'voting_method' => 'stv_droop', 'status' => 'certified',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sim_items')->insert([
            'id' => (string) Str::uuid(), 'run_id' => $runId, 'kind' => 'seat_scope',
            'status' => 'done', 'jurisdiction_id' => $jurId, 'race_id' => $electionId,
            'adm_level' => 2, 'unit_key' => $electionId, 'position' => 1,
            'est_cost' => 0, 'metrics' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$runId, $jurId, $legId];
    }

    /** The AchievementsPageTest posture: live pg, set as default, always rolled back. */
    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
