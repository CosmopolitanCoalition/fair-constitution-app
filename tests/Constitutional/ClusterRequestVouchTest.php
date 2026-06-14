<?php

namespace Tests\Constitutional;

use App\Models\ClusterAdoptionRequest;
use App\Models\ClusterMembership;
use App\Models\FederationPeer;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Mirror\AdoptionRejected;
use App\Services\Mirror\MirrorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G3) keyless request/vouch adoption. A would-be
 * mirror requests adoption with NO key; the host operator vouches it in. The pins:
 *  - a request is idempotent (repeated polls return the same pending row);
 *  - approval pins the applicant as a `mirror` peer (authoritative for nothing) +
 *    opens a `host` membership, and is idempotent (double-approve = same membership);
 *  - a rejected request can never be approved;
 *  - the mirror side stays queued (202 → null) until approved.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class ClusterRequestVouchTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_vouch';

    public function test_request_is_idempotent_and_approval_admits_the_mirror(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $mirror = app(MirrorService::class);
            $applicantId = (string) Str::uuid();

            $r1 = $mirror->requestAdoption($applicantId, 'applicant-pk', 'http://applicant');
            $r2 = $mirror->requestAdoption($applicantId, 'applicant-pk', 'http://applicant');
            $this->assertSame($r1->id, $r2->id, 'repeated requests are idempotent');
            $this->assertSame(ClusterAdoptionRequest::STATUS_PENDING, $r1->status);
            $this->assertCount(1, $mirror->pendingRequests());

            $membership = $mirror->approveRequest($r1->id);

            $peer = FederationPeer::query()->where('server_id', $applicantId)->firstOrFail();
            $this->assertSame(FederationPeer::RELATION_MIRROR, $peer->relation, 'the vouched applicant is our mirror');
            $this->assertSame(ClusterMembership::ROLE_HOST, $membership->role, 'we host it');
            $this->assertSame(ClusterAdoptionRequest::STATUS_ADMITTED, $r1->refresh()->status);
            $this->assertCount(0, $mirror->pendingRequests(), 'the queue is drained');

            // Double-approve is idempotent (same membership).
            $this->assertSame($membership->id, $mirror->approveRequest($r1->id)->id);
        });
    }

    public function test_a_rejected_request_cannot_be_approved(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $mirror = app(MirrorService::class);

            $r = $mirror->requestAdoption((string) Str::uuid(), 'pk');
            $mirror->rejectRequest($r->id);
            $this->assertSame(ClusterAdoptionRequest::STATUS_REJECTED, $r->refresh()->status);

            $this->expectException(AdoptionRejected::class);
            $mirror->approveRequest($r->id);
        });
    }

    public function test_a_mirror_side_request_is_queued_until_approval(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();

            Http::fake(['*/api/federation/adopt' => Http::response(
                ['status' => 'pending', 'request_id' => (string) Str::uuid()], 202
            )]);

            $this->assertNull(
                app(MirrorService::class)->requestJoin('http://host.docker.internal:9990'),
                'a queued (202) request returns null — the mirror waits for approval'
            );
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
