<?php

namespace Tests\Unit;

use App\Jobs\Federation\ClusterJoinJob;
use App\Models\ClusterMembership;
use App\Services\Mirror\MirrorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * M5 — cluster:join brings the node's UI up while the foundation transfer runs asynchronously.
 * DB-free (sqlite :memory: — see phpunit.xml); MirrorService is mocked so no real /adopt or
 * multi-GB drain runs. This pins the command contract that mirrors SetupController::joinFromSetup:
 *
 *   • DEFAULT (fresh box, not a mirror): admit synchronously with sync:false (a bad key fails fast,
 *     in-band), then DISPATCH ClusterJoinJob — never drain inline.
 *   • DEFAULT (already a mirror): RESUME the active membership by dispatching ClusterJoinJob,
 *     WITHOUT re-calling joinHost (no re-admit, no second join-key use).
 *   • --sync: run the seed + drain inline (syncMembership) instead of dispatching (foreground box).
 *   • already LIVE mirror: nothing to resume; no dispatch, no re-admit.
 *
 * Only the two tables the command touches are built on the default in-memory connection. No live
 * world is involved.
 */
class ClusterJoinCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('instance_settings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('instance_name')->nullable();
            $t->uuid('server_id')->nullable();
            $t->uuid('mirror_of_server_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('cluster_memberships', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('peer_id')->nullable();
            $t->string('role')->nullable();
            $t->string('state')->nullable();
            $t->string('admission_method')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Queue::fake();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('cluster_memberships');
        Schema::dropIfExists('instance_settings');
        parent::tearDown();
    }

    private function membership(string $state = ClusterMembership::STATE_SYNCING): ClusterMembership
    {
        return ClusterMembership::create([
            'role' => ClusterMembership::ROLE_MIRROR,
            'state' => $state,
            'admission_method' => ClusterMembership::ADMISSION_JOIN_KEY,
        ]);
    }

    public function test_default_fresh_join_admits_with_sync_false_then_dispatches_the_job(): void
    {
        $membership = $this->membership();

        $this->mock(MirrorService::class, function ($m) use ($membership) {
            $m->shouldReceive('isMirror')->andReturn(false);
            // The single-use key is consumed exactly once, and the long transfer is DEFERRED
            // (sync:false): a bad key fails fast here, the drain does not run inline.
            $m->shouldReceive('joinHost')
                ->once()
                ->with('http://host.invalid:9990', 'handle.secret', [], false)
                ->andReturn($membership);
            // Never drained inline on the default path.
            $m->shouldNotReceive('syncMembership');
        });

        $this->artisan('cluster:join', ['host_url' => 'http://host.invalid:9990', '--key' => 'handle.secret'])
            ->assertExitCode(0);

        Queue::assertPushed(ClusterJoinJob::class);
    }

    public function test_default_resume_on_an_existing_mirror_dispatches_without_re_admitting(): void
    {
        $membership = $this->membership();

        $this->mock(MirrorService::class, function ($m) use ($membership) {
            $m->shouldReceive('isMirror')->andReturn(true);
            $m->shouldReceive('activeMirrorMembership')->andReturn($membership);
            // RESUME: never re-adopt, never consume a second join-key use, never drain inline.
            $m->shouldNotReceive('joinHost');
            $m->shouldNotReceive('syncMembership');
        });

        // A key MAY be passed on a rerun; it is ignored (no re-consume) because we resume.
        $this->artisan('cluster:join', ['host_url' => 'http://host.invalid:9990', '--key' => 'handle.secret'])
            ->assertExitCode(0);

        Queue::assertPushed(ClusterJoinJob::class);
    }

    public function test_sync_flag_runs_the_drain_inline_instead_of_dispatching(): void
    {
        $membership = $this->membership();

        $this->mock(MirrorService::class, function ($m) use ($membership) {
            $m->shouldReceive('isMirror')->andReturn(false);
            $m->shouldReceive('joinHost')
                ->once()
                ->with('http://host.invalid:9990', 'handle.secret', [], false)
                ->andReturn($membership);
            // --sync drains inline in the foreground process.
            $m->shouldReceive('syncMembership')->once()->with($membership)->andReturn($membership);
        });

        $this->artisan('cluster:join', [
            'host_url' => 'http://host.invalid:9990',
            '--key' => 'handle.secret',
            '--sync' => true,
        ])->assertExitCode(0);

        Queue::assertNotPushed(ClusterJoinJob::class);
    }

    public function test_an_already_live_mirror_neither_dispatches_nor_re_admits(): void
    {
        $membership = $this->membership(ClusterMembership::STATE_LIVE);

        $this->mock(MirrorService::class, function ($m) use ($membership) {
            $m->shouldReceive('isMirror')->andReturn(true);
            $m->shouldReceive('activeMirrorMembership')->andReturn($membership);
            $m->shouldNotReceive('joinHost');
            $m->shouldNotReceive('syncMembership');
        });

        $this->artisan('cluster:join', ['host_url' => 'http://host.invalid:9990', '--key' => 'handle.secret'])
            ->assertExitCode(0);

        Queue::assertNotPushed(ClusterJoinJob::class);
    }

    public function test_a_mirror_with_no_active_membership_fails_without_dispatching(): void
    {
        $this->mock(MirrorService::class, function ($m) {
            $m->shouldReceive('isMirror')->andReturn(true);
            $m->shouldReceive('activeMirrorMembership')->andReturn(null);
            $m->shouldNotReceive('joinHost');
            $m->shouldNotReceive('syncMembership');
        });

        $this->artisan('cluster:join', ['host_url' => 'http://host.invalid:9990', '--key' => 'handle.secret'])
            ->assertExitCode(1);

        Queue::assertNotPushed(ClusterJoinJob::class);
    }

    public function test_a_fresh_join_without_a_key_fails_fast(): void
    {
        $this->mock(MirrorService::class, function ($m) {
            $m->shouldReceive('isMirror')->andReturn(false);
            $m->shouldNotReceive('joinHost');
        });

        $this->artisan('cluster:join', ['host_url' => 'http://host.invalid:9990'])
            ->assertExitCode(1);

        Queue::assertNotPushed(ClusterJoinJob::class);
    }

    /**
     * The async contract the command depends on: the dispatched job is routed to the
     * long-running supervisor with no timeout, runs once (the drain is resumable, not retried),
     * and a duplicate dispatch is dropped by WithoutOverlapping so two workers never race the
     * same cursor. Without these, "serve the UI while the transfer runs" would not hold.
     */
    public function test_cluster_join_job_is_long_running_and_overlap_guarded(): void
    {
        $job = new ClusterJoinJob('11111111-1111-1111-1111-111111111111');

        $this->assertSame('redis', $job->connection);
        $this->assertSame('long-running', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertSame(0, $job->timeout);

        $mw = $job->middleware();
        $this->assertCount(1, $mw);
        $this->assertInstanceOf(WithoutOverlapping::class, $mw[0]);
    }
}
