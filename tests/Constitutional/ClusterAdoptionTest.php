<?php

namespace Tests\Constitutional;

use App\Models\ClusterAdoptionRequest;
use App\Models\ClusterMembership;
use App\Models\FederationPeer;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Mirror\AdoptionRejected;
use App\Services\Mirror\MirrorJoinKeyService;
use App\Services\Mirror\MirrorService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G2) join-key adoption (host side). A host admits a
 * would-be mirror that presents a valid join key: the applicant is pinned as a
 * `mirror` peer (relation=mirror — authoritative for nothing) and a `host`
 * membership opens; the key is consumed once. An invalid/exhausted key is refused
 * (without consuming); a replayed (applicant, nonce) is refused by the anti-replay
 * unique index; a self-adopt is refused.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class ClusterAdoptionTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_adoption';

    public function test_a_valid_key_admits_a_mirror_and_consumes_the_key(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $mirror = app(MirrorService::class);
            [$plaintext, $key] = app(MirrorJoinKeyService::class)->mint();

            $applicantId = (string) Str::uuid();
            $membership = $mirror->admitMirror($applicantId, 'applicant-pk', bin2hex(random_bytes(8)), $plaintext, 'http://applicant:9000');

            // The applicant is OUR mirror (we host it); relation=mirror, role=host.
            $peer = FederationPeer::query()->where('server_id', $applicantId)->firstOrFail();
            $this->assertSame(FederationPeer::RELATION_MIRROR, $peer->relation, 'the applicant is pinned as a mirror');
            $this->assertSame(ClusterMembership::ROLE_HOST, $membership->role, 'we host the mirror');
            $this->assertSame(ClusterMembership::STATE_LIVE, $membership->state);

            // The key was consumed once; the request is admitted.
            $this->assertSame(1, (int) $key->refresh()->uses);
            $req = ClusterAdoptionRequest::query()->where('applicant_server_id', $applicantId)->firstOrFail();
            $this->assertSame(ClusterAdoptionRequest::STATUS_ADMITTED, $req->status);
            $this->assertSame($key->handle, $req->join_key_handle);
        });
    }

    public function test_an_invalid_key_is_refused_and_does_not_consume(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $mirror = app(MirrorService::class);
            [, $key] = app(MirrorJoinKeyService::class)->mint();

            $threw = false;
            try {
                $mirror->admitMirror((string) Str::uuid(), 'pk', bin2hex(random_bytes(8)), $key->handle.'.wrongsecret');
            } catch (AdoptionRejected $e) {
                $threw = true;
                $this->assertSame(403, $e->status);
            }

            $this->assertTrue($threw, 'a wrong key is refused');
            $this->assertSame(0, (int) $key->refresh()->uses, 'a refused key is not consumed');
        });
    }

    public function test_a_replayed_nonce_is_refused(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $mirror = app(MirrorService::class);

            // A two-use key so the SECOND admit is blocked by the NONCE, not the key.
            [$plaintext] = app(MirrorJoinKeyService::class)->mint(maxUses: 2);
            $applicantId = (string) Str::uuid();
            $nonce = bin2hex(random_bytes(8));

            $mirror->admitMirror($applicantId, 'pk', $nonce, $plaintext);

            $threw = false;
            try {
                $mirror->admitMirror($applicantId, 'pk', $nonce, $plaintext);
            } catch (QueryException $e) {
                $threw = true;
                $this->assertStringContainsStringIgnoringCase('applicant_nonce', $e->getMessage());
            }

            $this->assertTrue($threw, 'a replayed (applicant, nonce) is refused');
        });
    }

    public function test_self_adoption_is_refused(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            [$plaintext] = app(MirrorJoinKeyService::class)->mint();

            $this->expectException(AdoptionRejected::class);
            app(MirrorService::class)->admitMirror($identity->serverId(), 'pk', bin2hex(random_bytes(8)), $plaintext);
        });
    }

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
