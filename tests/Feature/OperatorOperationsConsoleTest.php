<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Identity\OperatorIdentityService;
use App\Services\Operator\OperatorInfraService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Operator Operations console (Phase 1, read-only) — the infra & identity inventory.
 * Pins: an operator sees the inventory; a citizen-only session sees NONE of it (the
 * gate, mirroring the federation host block); and — the #1 leakage risk — a SECRET'S
 * VALUE never rides the payload (only configured?/dev_default?). If an edit starts
 * leaking a key/secret/token into a prop, the dev-default-secret assertion catches it.
 *
 * Live-pg posture.
 */
class OperatorOperationsConsoleTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_operator_ops';

    public function test_an_operator_sees_the_infra_inventory(): void
    {
        $this->onLivePg(function () {
            $citizen = User::query()->whereNull('deleted_at')->firstOrFail();
            $op = app(OperatorIdentityService::class)->register('opsconsole_'.Str::lower(Str::random(8)), 'correct horse battery');

            $this->be($op, 'operator')->be($citizen, 'web')->get('/operator/operations')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Operator/Operations')
                    ->where('authed', true)
                    ->has('inventory.sections')
                    ->has('inventory.tiers'));
        });
    }

    public function test_a_citizen_without_an_operator_session_sees_no_inventory(): void
    {
        $this->onLivePg(function () {
            $citizen = User::query()->whereNull('deleted_at')->firstOrFail();

            $this->be($citizen, 'web')->get('/operator/operations')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Operator/Operations')
                    ->where('authed', false)
                    ->where('inventory', null));
        });
    }

    public function test_a_secret_value_never_rides_the_payload(): void
    {
        $this->onLivePg(function () {
            $citizen = User::query()->whereNull('deleted_at')->firstOrFail();
            $op = app(OperatorIdentityService::class)->register('opssec_'.Str::lower(Str::random(8)), 'correct horse battery');

            $resp = $this->be($op, 'operator')->be($citizen, 'web')->get('/operator/operations');
            $resp->assertOk();

            // The literal dev secret values must never appear in the rendered props.
            $resp->assertDontSee((string) config('matrix.livekit.api_secret'), false);
            $resp->assertDontSee((string) config('matrix.appservice.as_token'), false);
            $resp->assertDontSee((string) config('matrix.appservice.hs_token'), false);
            $resp->assertDontSee((string) config('matrix.oidc.client.secret'), false);
        });
    }

    public function test_the_inventory_flags_a_dev_default_secret_without_exposing_it(): void
    {
        $this->onLivePg(function () {
            $inventory = app(OperatorInfraService::class)->inventory();

            $items = collect($inventory['sections'])->flatMap(fn ($s) => $s['items'])->keyBy('key');
            $secret = $items->get('livekit_api_secret');

            $this->assertNotNull($secret);
            $this->assertTrue($secret['secret']);
            $this->assertNull($secret['value'], 'a secret value never leaves the service');
            $this->assertTrue($secret['configured']);
            $this->assertTrue($secret['dev_default'], 'the cga_dev_* placeholder is flagged for rotation');
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
