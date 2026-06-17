<?php

namespace Tests\Feature;

use App\Models\ClusterAdoptionRequest;
use App\Models\ClusterJoinKey;
use App\Models\ReadWriteRequest;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\ReadWriteRequestService;
use App\Services\Identity\OperatorIdentityService;
use App\Services\Mirror\MirrorJoinKeyService;
use App\Services\Mirror\MirrorService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Phase G (G3c) — the HOST CONSOLE actions over HTTP, gated by auth:operator. The
 * services (approveRequest/rejectRequest/deny) are pinned elsewhere at the service
 * layer; this pins the ROUTES the operator actually clicks during a run — and that
 * they are refused without an operator session. These are the buttons exercised in
 * the fresh-run adoption + read-write flow.
 *
 * Live-pg posture; an operator account stands in for the logged-in host operator.
 */
class FederationHostConsoleActionsTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_host_console';

    public function test_operator_approves_and_rejects_adoption_requests(): void
    {
        $this->onLivePg(function () {
            $op = $this->operator();
            $mirror = app(MirrorService::class);

            $approve = $mirror->requestAdoption((string) Str::uuid(), 'applicant-pk-a', 'http://a.example');
            $reject = $mirror->requestAdoption((string) Str::uuid(), 'applicant-pk-b', 'http://b.example');

            $this->actingAs($op, 'operator')
                ->post("/federation/host/requests/{$approve->id}/approve")
                ->assertRedirect();
            $this->assertSame(ClusterAdoptionRequest::STATUS_ADMITTED, $approve->fresh()->status, 'approve admits the mirror');

            $this->actingAs($op, 'operator')
                ->post("/federation/host/requests/{$reject->id}/reject")
                ->assertRedirect();
            $this->assertSame(ClusterAdoptionRequest::STATUS_REJECTED, $reject->fresh()->status, 'reject persists');
        });
    }

    public function test_operator_revokes_a_key_and_denies_a_read_write_petition(): void
    {
        $this->onLivePg(function () {
            $op = $this->operator();

            [, $key] = app(MirrorJoinKeyService::class)->mint(1, null);
            $this->assertTrue($key->isLive());

            $this->actingAs($op, 'operator')
                ->post('/federation/host/keys/revoke', ['handle' => $key->handle])
                ->assertRedirect();
            $this->assertNotNull(ClusterJoinKey::query()->where('handle', $key->handle)->first()->revoked_at, 'the key is revoked');

            $jur = (string) (DB::table('jurisdictions')->whereNull('deleted_at')->value('id') ?? Str::uuid());
            $rw = app(ReadWriteRequestService::class)->submit((string) Str::uuid(), null, $jur, 'we run a vetted node');

            $this->actingAs($op, 'operator')
                ->post("/federation/host/rw/{$rw->id}/deny")
                ->assertRedirect();
            $this->assertSame(ReadWriteRequest::STATUS_DENIED, $rw->fresh()->status, 'the petition is denied');
        });
    }

    public function test_host_actions_are_refused_without_an_operator_session(): void
    {
        $this->onLivePg(function () {
            $req = app(MirrorService::class)->requestAdoption((string) Str::uuid(), 'applicant-pk-c', 'http://c.example');

            // No operator session — the auth:operator guard must block the action.
            $this->post("/federation/host/requests/{$req->id}/approve");

            $this->assertGuest('operator');
            $this->assertSame(
                ClusterAdoptionRequest::STATUS_PENDING,
                $req->fresh()->status,
                'an unauthenticated POST performs nothing — the request stays pending',
            );
        });
    }

    private function operator(): \App\Models\OperatorAccount
    {
        app(InstanceIdentityService::class)->ensureIdentity();

        return app(OperatorIdentityService::class)->register('hostop_'.Str::lower(Str::random(8)), 'correct horse battery');
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();
        $this->withoutMiddleware(ValidateCsrfToken::class);

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
