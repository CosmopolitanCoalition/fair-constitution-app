<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\OperatorIdentityService;
use App\Services\Mirror\MirrorJoinKeyService;
use App\Services\Mirror\MirrorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Phase G (G3c) — the /federation console props. The console page is citizen-public
 * (Art. II §2), but the HOST props (invite keys, pending requests, read-write
 * petitions) are populated ONLY for an authenticated operator, and the invite-key
 * mapping must NEVER expose key_hash (the design's #1 leakage risk). Pins both: an
 * operator sees the host block (no key_hash); a citizen-only session does not.
 *
 * Live-pg posture.
 */
class FederationConsolePropsTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_console_props';

    public function test_an_operator_sees_host_props_and_no_key_hash_ever_leaks(): void
    {
        $this->onLivePg(function () {
            $citizen = User::query()->whereNull('deleted_at')->firstOrFail();
            $op = app(OperatorIdentityService::class)->register('consoleop_'.Str::lower(Str::random(8)), 'correct horse battery');

            // Populate the host surfaces so the assertions are meaningful.
            app(MirrorJoinKeyService::class)->mint(1, null);
            app(MirrorService::class)->requestAdoption((string) Str::uuid(), 'applicant-pk', 'http://applicant.example');

            // Authenticate BOTH planes; web stays the DEFAULT guard (as in the real
            // browser) so the global Inertia share resolves the citizen, not the operator.
            $resp = $this->be($op, 'operator')->be($citizen, 'web')->get('/federation');

            $resp->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Jurisdictions/Federation')
                    ->where('host.authed', true)
                    ->has('host.keys')
                    ->has('host.requests')
                    ->has('host.rw_requests')
                    ->has('roots'));

            // The single highest-risk leak: a minted key's hash must never ride a prop.
            $resp->assertDontSee('key_hash', false);
        });
    }

    public function test_a_citizen_without_an_operator_session_sees_no_host_props(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $citizen = User::query()->whereNull('deleted_at')->firstOrFail();

            $this->be($citizen, 'web')->get('/federation')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Jurisdictions/Federation')
                    ->where('host.authed', false)
                    ->missing('host.keys')
                    ->missing('host.requests'));
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
