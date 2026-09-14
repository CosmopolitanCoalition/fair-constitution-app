<?php

namespace Tests\Unit;

use App\Console\Commands\FederationResumeJoinCommand;
use App\Models\ClusterMembership;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Mirror\MirrorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M3 primitives, DB-free (sqlite :memory: — see phpunit.xml). This pins the two guarantees
 * the deploy scripts now lean on:
 *   1. federation:init on a rerun is a no-op — ensureIdentity() keeps server_id + keypair, so
 *      preserving APP_KEY keeps the encrypted private key decryptable. rotate() (the only
 *      --clone-rekey path) does change them.
 *   2. federation:resume-join with NO active membership returns the DISTINCT exit code 3 and
 *      consumes no join key / posts no /adopt (the MirrorService is never touched).
 *
 * The full pg baseline (PostGIS) cannot load on sqlite, so this builds only the two tables the
 * primitives touch, on the default in-memory connection. No live world is involved.
 */
class InstanceIdentityIdempotenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('instance_settings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('instance_name')->nullable();
            $t->string('map_mode')->nullable();
            $t->string('time_mode')->nullable();
            $t->integer('setup_step_completed')->nullable();
            $t->uuid('server_id')->nullable();
            $t->text('public_key')->nullable();
            $t->text('private_key_encrypted')->nullable();
            $t->timestamp('signing_key_generated_at')->nullable();
            $t->boolean('federation_enabled')->nullable();
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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('cluster_memberships');
        Schema::dropIfExists('instance_settings');
        parent::tearDown();
    }

    public function test_ensure_identity_is_idempotent(): void
    {
        $svc = app(InstanceIdentityService::class);

        $first = $svc->ensureIdentity();
        $serverId = $first->server_id;
        $publicKey = $first->public_key;
        $privateEnc = $first->private_key_encrypted;

        $this->assertNotNull($serverId, 'first ensureIdentity mints a server_id');
        $this->assertNotNull($publicKey, 'first ensureIdentity mints a public key');
        $this->assertNotNull($privateEnc, 'first ensureIdentity mints an encrypted private key');

        // A rerun (federation:init with no --rotate) must NOT re-mint.
        $second = $svc->ensureIdentity();

        $this->assertSame($serverId, $second->server_id, 'server_id unchanged on rerun');
        $this->assertSame($publicKey, $second->public_key, 'public key unchanged on rerun');
        $this->assertSame($privateEnc, $second->private_key_encrypted, 'encrypted private key unchanged on rerun');
    }

    public function test_rotate_changes_identity(): void
    {
        $svc = app(InstanceIdentityService::class);

        $before = $svc->ensureIdentity();
        $serverId = $before->server_id;
        $publicKey = $before->public_key;

        // rotate() is the ONLY path that changes identity (deploy --clone-rekey / -CloneRekey).
        $after = $svc->rotate();

        $this->assertNotSame($serverId, $after->server_id, 'rotate mints a fresh server_id');
        $this->assertNotSame($publicKey, $after->public_key, 'rotate mints a fresh keypair');
        $this->assertNotNull($after->server_id);
        $this->assertNotNull($after->public_key);
    }

    public function test_resume_join_with_no_membership_returns_distinct_code_and_consumes_no_key(): void
    {
        // The MirrorService must never be touched when there is nothing to resume: no /adopt,
        // no join-key consume, no sync. A fake that refuses every call proves it.
        $this->mock(MirrorService::class, function ($m) {
            $m->shouldNotReceive('joinHost');
            $m->shouldNotReceive('requestJoin');
            $m->shouldNotReceive('admitMirror');
            $m->shouldNotReceive('syncMembership');
        });

        $this->artisan('federation:resume-join')
            ->assertExitCode(FederationResumeJoinCommand::EXIT_NO_MEMBERSHIP);

        $this->assertSame(3, FederationResumeJoinCommand::EXIT_NO_MEMBERSHIP, 'the documented no-membership code is 3');
    }

    public function test_resume_join_with_departed_membership_returns_departed_code_not_no_membership(): void
    {
        // A box that HELD a mirror membership which is now DEPARTED/REJECTED must NOT be folded
        // into the "never a mirror" code (3): that would let deploy silently re-adopt with a
        // still-valid multi-use key. It gets the DISTINCT code 4 so the caller fails loud, and
        // the MirrorService is still never touched (no /adopt, no key consume, no sync).
        DB::table('cluster_memberships')->insert([
            'id' => (string) Str::uuid(),
            'peer_id' => null,
            'role' => ClusterMembership::ROLE_MIRROR,
            'state' => ClusterMembership::STATE_DEPARTED,
            'admission_method' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->mock(MirrorService::class, function ($m) {
            $m->shouldNotReceive('joinHost');
            $m->shouldNotReceive('requestJoin');
            $m->shouldNotReceive('admitMirror');
            $m->shouldNotReceive('syncMembership');
        });

        $this->artisan('federation:resume-join')
            ->assertExitCode(FederationResumeJoinCommand::EXIT_DEPARTED);

        $this->assertSame(4, FederationResumeJoinCommand::EXIT_DEPARTED, 'the documented departed code is 4');
        $this->assertNotSame(
            FederationResumeJoinCommand::EXIT_NO_MEMBERSHIP,
            FederationResumeJoinCommand::EXIT_DEPARTED,
            'departed must be distinct from no-membership so a departed node never silently re-adopts'
        );
    }
}
