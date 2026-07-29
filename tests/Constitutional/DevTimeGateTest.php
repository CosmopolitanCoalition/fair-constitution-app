<?php

namespace Tests\Constitutional;

use App\Http\Middleware\DevTimeControlsEnabled;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * THE PLAYTEST-CONTROL GATE, TESTED SO EACH REASON DISCRIMINATES.
 *
 * Lane 9 captured /system/clocks and confirmed the "advance the world" block
 * was absent; I called the gate holding. It was — but the block would have
 * been invisible either way at that moment, because nothing rendered it. The
 * absence passed for a weaker reason than I claimed.
 *
 * The first version of this file then made the SAME mistake one layer down.
 * `phpunit.xml` sets `APP_ENV=testing`, so `environment('local')` is false in
 * every test and `refusalReason()` returned the environment message every
 * time. All three "refuses because X" cases were really testing the
 * environment gate, and passed for a reason unrelated to their own names.
 * A check that passes for the wrong reason is worse than one that cannot
 * fail, because it looks like coverage.
 *
 * So every case below forces the environment to `local` and removes exactly
 * ONE condition, then asserts the message names THAT condition. If a gate
 * stops holding, the failure says which one.
 */
class DevTimeGateTest extends TestCase
{
    use LivePgConnection;

    protected function setUp(): void
    {
        parent::setUp();

        // Without this, every case below is really the environment check.
        $this->app['env'] = 'local';

        config([
            'cga.impersonation' => true,
            'cga.dev_time'      => true,
        ]);

        // Clear any GameMode static leaked by a PRIOR test class: GameMode
        // caches its answer for the life of a request, and another class that
        // founded a demo world on a connection it left as the default would
        // otherwise make this class read sandbox ambiently. The cases that
        // need a specific mode set it explicitly via override(); the rest get
        // a clean slate here.
        \App\Support\GameMode::flush();
    }

    protected function tearDown(): void
    {
        \App\Support\GameMode::flush();
        parent::tearDown();
    }

    /** The environment gate itself — the one the others were accidentally testing. */
    public function test_the_gate_refuses_outside_the_local_environment(): void
    {
        $this->app['env'] = 'production';

        $reason = DevTimeControlsEnabled::refusalReason();

        $this->assertNotNull($reason);
        $this->assertStringContainsString('local environment', $reason);
    }

    /**
     * The controls are OFF by default on any world NOT founded in Demo mode —
     * nobody has to remember to disable them. (RULED 2026-07-28, §10 item 4:
     * the sandbox setup choice is the switch. Game mode is NOT sandbox and no
     * key is set, so the gate refuses with the sentence that explains where the
     * switch actually lives.)
     *
     * The mode is FORCED to production via override() rather than left to
     * resolve against the ambient connection — a prior test class that founded
     * a demo world and left its connection as the default would otherwise make
     * this read sandbox and the gate ALLOW. The fixture establishes the "not
     * sandbox" precondition its subject requires; it does not borrow it.
     */
    public function test_the_gate_refuses_when_dev_time_is_not_switched_on(): void
    {
        \App\Support\GameMode::override(\App\Support\GameMode::PRODUCTION);
        config(['cga.dev_time' => false]);

        $reason = DevTimeControlsEnabled::refusalReason();

        $this->assertNotNull($reason);
        $this->assertStringContainsString('Playtest time controls are off', $reason);
    }

    /**
     * ⚖ RULED 2026-07-28 (V3_SYNTHESIS_PLAN.md §10 item 4) — THE DERIVATION.
     *
     * A founder who chose Demo (sandbox) mode at setup gets working time
     * controls with NO .env edit: `cga.dev_time` resolves true when
     * game_mode = sandbox. This is the positive pin of that ruling — key OFF,
     * world sandbox, gate open. Runs against the live sandbox database so the
     * peer probes answer against real rows.
     */
    public function test_a_demo_founded_world_needs_no_env_key(): void
    {
        $pg = $this->livePg('pgsql_dev_time_gate');

        config(['database.default' => 'pgsql_dev_time_gate', 'cga.dev_time' => false]);
        $pg->getPdo();

        \App\Support\GameMode::flush();

        $this->assertNull(
            DevTimeControlsEnabled::refusalReason(),
            'RULED 2026-07-28: founding in Demo (sandbox) mode IS the switch — no env key needed.',
        );
    }

    /**
     * ⚖ RULED 2026-07-28 (§10 item 4) — THE REFINED RAIL.
     *
     * Demo instances are EXPECTED to peer for full-scale multibox testing, so
     * the refusal keys on "connected to any NON-demo node", never on "peered
     * at all". A peer that AFFIRMATIVELY declared itself a demo in its signed
     * handshake (instance_class = scale_demo, persisted to the peer row's
     * metadata) does not refuse the controls; one undeclared node makes the
     * whole mesh real and refuses. Exercised inside a rolled-back transaction
     * — this test creates the peers it measures rather than borrowing
     * whatever the shared box happens to hold.
     */
    public function test_a_declared_demo_mesh_may_time_travel_and_one_real_node_stops_it(): void
    {
        $pg = $this->livePg('pgsql_dev_time_gate');

        config(['database.default' => 'pgsql_dev_time_gate', 'cga.dev_time' => false]);
        $pg->getPdo();
        $pg->beginTransaction();

        try {
            \App\Support\GameMode::override(\App\Support\GameMode::SANDBOX);

            // This test's world holds exactly the peers it says it does.
            \Illuminate\Support\Facades\DB::table('federation_peers')->delete();

            $demoServerId = (string) \Illuminate\Support\Str::uuid();
            \Illuminate\Support\Facades\DB::table('federation_peers')->insert([
                'server_id' => $demoServerId,
                'url' => 'http://demo-peer.test',
                'status' => 'trust_established',
                'metadata' => json_encode(['instance_class' => 'scale_demo']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertNull(
                DevTimeControlsEnabled::refusalReason(),
                'a mesh made only of declared demo instances may time-travel (ruling 2026-07-28)',
            );

            // A peer that declared game_mode = sandbox (and NO instance class)
            // is equally a demo declaration — the rail honours either field,
            // and the handshake now carries both (lane 2, Wave 2).
            \Illuminate\Support\Facades\DB::table('federation_peers')->insert([
                'server_id' => (string) \Illuminate\Support\Str::uuid(),
                'url' => 'http://sandbox-peer.test',
                'status' => 'trust_established',
                'metadata' => json_encode(['game_mode' => 'sandbox']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertNull(
                DevTimeControlsEnabled::refusalReason(),
                'a declared game_mode=sandbox peer counts as a demo — the rail honours either declaration',
            );

            // A mirror of that declared demo host is part of the same demo mesh.
            \Illuminate\Support\Facades\DB::table('instance_settings')
                ->update(['mirror_of_server_id' => $demoServerId]);

            $this->assertNull(
                DevTimeControlsEnabled::refusalReason(),
                'mirroring a DECLARED DEMO host is demo-mesh membership, not a real chain of trust',
            );

            \Illuminate\Support\Facades\DB::table('instance_settings')
                ->update(['mirror_of_server_id' => null]);

            // One node that declared nothing: the whole mesh is real — refused.
            // Absence of the declaration is the fail-closed direction; every
            // pre-ruling peer row and every adoption-minted row reads this way.
            \Illuminate\Support\Facades\DB::table('federation_peers')->insert([
                'server_id' => (string) \Illuminate\Support\Str::uuid(),
                'url' => 'http://undeclared-peer.test',
                'status' => 'trust_established',
                'metadata' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $reason = DevTimeControlsEnabled::refusalReason();

            $this->assertNotNull($reason, 'one undeclared node makes the mesh real');
            $this->assertStringContainsString('has not declared itself a demo', $reason);
        } finally {
            while ($pg->transactionLevel() > 0) {
                $pg->rollBack();
            }
            \App\Support\GameMode::flush();
        }
    }

    /** The controls must not outlive the wider dev toolbox that gates them. */
    public function test_the_gate_refuses_when_the_dev_toolbox_is_off(): void
    {
        config(['cga.impersonation' => false]);

        $reason = DevTimeControlsEnabled::refusalReason();

        $this->assertNotNull($reason);
        $this->assertStringContainsString('dev toolbox', $reason);
    }

    /**
     * FAIL-CLOSED. The sandbox and peer probes both read the database; the
     * default test connection has no schema, so they throw and the middleware
     * treats the instance as connected.
     *
     * "I could not tell" must mean no: the cost of being wrong in the
     * permissive direction is a fabricated record inside somebody else's chain
     * of trust.
     */
    public function test_the_gate_refuses_when_it_cannot_inspect_the_instance(): void
    {
        $this->assertNotNull(
            DevTimeControlsEnabled::refusalReason(),
            'an instance we cannot inspect must be refused, not allowed',
        );
    }

    /**
     * ⚑ THE POSITIVE CONTROL — the case that makes every refusal above mean
     * something.
     *
     * Without it, all four assertions are equally satisfied by a gate that
     * refuses unconditionally, which is indistinguishable from a control that
     * was never wired. Run against the live sandbox database so the game-mode
     * and peer probes can actually answer.
     */
    public function test_the_gate_allows_when_every_condition_is_met(): void
    {
        $pg = $this->livePg('pgsql_dev_time_gate');

        // Point the default connection at the real sandbox world so
        // GameMode::isSandbox() and the peer probe resolve against real rows.
        config(['database.default' => 'pgsql_dev_time_gate']);
        $pg->getPdo();

        // GameMode caches its answer in a static for the life of a REQUEST,
        // which is right in production and wrong across tests: the refusal
        // cases above resolve it to null against the schema-less default
        // connection, and without this flush the positive control would read
        // that stale null and "fail" for a reason that has nothing to do with
        // the gate.
        \App\Support\GameMode::flush();

        $reason = DevTimeControlsEnabled::refusalReason();

        $this->assertNull(
            $reason,
            'with local env, toolbox on, dev_time on, sandbox mode and no peers, the gate MUST allow — '
            .'otherwise the refusals above prove nothing. Refusal given: '.((string) $reason),
        );
    }
}
