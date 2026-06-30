<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Identity\OperatorIdentityService;
use App\Services\Operator\OperatorSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Operator Operations console (Phase 2) — instant-tier editable knobs. The override
 * store overlays onto config() at boot. Pins:
 *  1. NO override leaves config byte-identical (federation behaviour unchanged unless
 *     the operator explicitly opts in — the load-bearing safety property);
 *  2. an override overlays onto config (applies on the next request, no restart);
 *  3. out-of-bounds / unknown keys are refused;
 *  4. clear reverts to the default;
 *  5. the tuning route is operator-gated (a citizen cannot move a knob).
 *
 * Live-pg posture.
 */
class OperatorTuningTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_operator_tuning';

    public function test_no_override_leaves_config_at_its_default(): void
    {
        $this->onLivePg(function () {
            $before = config('cga.federation_heartbeat_minutes');
            $svc = app(OperatorSettingsService::class);

            $this->assertSame([], $svc->all(), 'a fresh box carries no overrides');
            $svc->overlay();

            $this->assertSame($before, config('cga.federation_heartbeat_minutes'), 'overlay with no overrides changes nothing');
        });
    }

    public function test_an_override_overlays_onto_config(): void
    {
        $this->onLivePg(function () {
            $svc = app(OperatorSettingsService::class);
            $svc->set('heartbeat_minutes', 30);
            $svc->overlay();

            $this->assertSame(30, (int) config('cga.federation_heartbeat_minutes'));
            $this->assertTrue($svc->isOverridden('heartbeat_minutes'));
        });
    }

    public function test_out_of_bounds_and_unknown_keys_are_refused(): void
    {
        $this->onLivePg(function () {
            $svc = app(OperatorSettingsService::class);

            foreach ([['heartbeat_minutes', 0], ['heartbeat_minutes', 99999], ['sync_page_size', 1], ['nope', 'x']] as [$key, $val]) {
                $threw = false;
                try {
                    $svc->set($key, $val);
                } catch (\InvalidArgumentException) {
                    $threw = true;
                }
                $this->assertTrue($threw, "set({$key}, {$val}) must be refused");
            }

            $this->assertSame([], $svc->all(), 'no refused value was stored');
        });
    }

    public function test_clear_reverts_to_default(): void
    {
        $this->onLivePg(function () {
            $svc = app(OperatorSettingsService::class);
            $svc->set('sync_page_size', 250);
            $this->assertTrue($svc->isOverridden('sync_page_size'));

            $svc->clear('sync_page_size');
            $this->assertFalse($svc->isOverridden('sync_page_size'));
            $this->assertArrayNotHasKey('sync_page_size', $svc->all());
        });
    }

    public function test_an_operator_can_set_and_reset_a_tuning_override(): void
    {
        $this->onLivePg(function () {
            $citizen = User::query()->whereNull('deleted_at')->firstOrFail();
            $op = app(OperatorIdentityService::class)->register('optune_'.Str::lower(Str::random(8)), 'correct horse battery');

            $this->be($op, 'operator')->be($citizen, 'web')
                ->withSession(['_token' => 'pin'])
                ->post('/operator/operations/tuning', ['key' => 'http_timeout', 'value' => 45], ['X-CSRF-TOKEN' => 'pin'])
                ->assertRedirect();
            $this->assertSame(45, (int) app(OperatorSettingsService::class)->all()['http_timeout']);

            $this->withSession(['_token' => 'pin'])
                ->post('/operator/operations/tuning/reset', ['key' => 'http_timeout'], ['X-CSRF-TOKEN' => 'pin'])
                ->assertRedirect();
            $this->assertFalse(app(OperatorSettingsService::class)->isOverridden('http_timeout'));
        });
    }

    public function test_a_citizen_cannot_move_an_operator_knob(): void
    {
        $this->onLivePg(function () {
            // No operator session — only a citizen. auth:operator must refuse the POST.
            $citizen = User::query()->whereNull('deleted_at')->firstOrFail();

            $this->be($citizen, 'web')
                ->withSession(['_token' => 'pin'])
                ->post('/operator/operations/tuning', ['key' => 'http_timeout', 'value' => 600], ['X-CSRF-TOKEN' => 'pin'])
                ->assertStatus(302);

            $this->assertArrayNotHasKey('http_timeout', app(OperatorSettingsService::class)->all(), 'a citizen cannot move an operator knob');
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
