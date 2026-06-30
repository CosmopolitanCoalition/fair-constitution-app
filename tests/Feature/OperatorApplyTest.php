<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Identity\OperatorIdentityService;
use App\Services\Operator\OperatorApplyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Operator Operations console (Phase 3) — restart-tier host-apply protocol. The app
 * stages a validated desired-state control file the host supervisor consumes. Pins:
 *  1. a valid request writes request.json with the validated change + recreate target;
 *  2. unknown keys and invalid values are refused (nothing written);
 *  3. SECRETS are not applyable through this path (credential-pass-gated);
 *  4. the apply route is operator-gated.
 *
 * Live-pg posture (requestApply writes an audit row).
 */
class OperatorApplyTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_operator_apply';

    private function isolateControlDir(): string
    {
        $dir = sys_get_temp_dir().'/ops_apply_'.uniqid('', true);
        config(['cga.ops_control_path' => $dir]);

        return $dir;
    }

    public function test_a_valid_request_stages_a_control_file(): void
    {
        $this->onLivePg(function () {
            $dir = $this->isolateControlDir();
            $apply = app(OperatorApplyService::class);

            $payload = $apply->requestApply(['LIVEKIT_NODE_IP' => '192.168.1.50']);

            $this->assertSame('192.168.1.50', $payload['changes']['LIVEKIT_NODE_IP']);
            $this->assertSame(['livekit'], $payload['recreate']);
            $this->assertFileExists($dir.'/request.json');

            $status = $apply->status();
            $this->assertSame('pending', $status['lifecycle']);
        });
    }

    public function test_unknown_keys_and_invalid_values_are_refused(): void
    {
        $this->onLivePg(function () {
            $dir = $this->isolateControlDir();
            $apply = app(OperatorApplyService::class);

            foreach ([
                ['EVIL_KEY' => 'x'],
                ['LIVEKIT_NODE_IP' => 'not-an-ip'],
                ['LIVEKIT_PUBLIC_URL' => 'http://plain-not-ws.example'],
                ['LIVEKIT_NODE_IP' => ''],
            ] as $bad) {
                $threw = false;
                try {
                    $apply->requestApply($bad);
                } catch (\InvalidArgumentException) {
                    $threw = true;
                }
                $this->assertTrue($threw, 'bad apply request must be refused: '.json_encode($bad));
            }

            $this->assertFileDoesNotExist($dir.'/request.json', 'a refused request writes nothing');
        });
    }

    public function test_secrets_are_not_applyable(): void
    {
        $apply = app(OperatorApplyService::class);

        $this->assertFalse($apply->isApplyable('LIVEKIT_API_SECRET'));
        $this->assertFalse($apply->isApplyable('MATRIX_AS_TOKEN'));
        $this->assertTrue($apply->isApplyable('LIVEKIT_NODE_IP'));
    }

    public function test_an_operator_can_stage_an_apply(): void
    {
        $this->onLivePg(function () {
            $this->isolateControlDir();
            $citizen = User::query()->whereNull('deleted_at')->firstOrFail();
            $op = app(OperatorIdentityService::class)->register('opapply_'.Str::lower(Str::random(8)), 'correct horse battery');

            $this->be($op, 'operator')->be($citizen, 'web')
                ->withSession(['_token' => 'pin'])
                ->post('/operator/operations/apply', ['changes' => ['LIVEKIT_PUBLIC_URL' => 'wss://box.example:7443']], ['X-CSRF-TOKEN' => 'pin'])
                ->assertRedirect();

            $this->assertSame('pending', app(OperatorApplyService::class)->status()['lifecycle']);
        });
    }

    public function test_a_citizen_cannot_stage_an_apply(): void
    {
        $this->onLivePg(function () {
            $dir = $this->isolateControlDir();
            $citizen = User::query()->whereNull('deleted_at')->firstOrFail();

            $this->be($citizen, 'web')
                ->withSession(['_token' => 'pin'])
                ->post('/operator/operations/apply', ['changes' => ['LIVEKIT_NODE_IP' => '10.0.0.9']], ['X-CSRF-TOKEN' => 'pin'])
                ->assertStatus(302);

            $this->assertFileDoesNotExist($dir.'/request.json', 'a citizen cannot stage a host-apply');
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
