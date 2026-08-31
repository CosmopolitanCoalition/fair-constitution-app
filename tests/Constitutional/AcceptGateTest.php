<?php

namespace Tests\Constitutional;

use App\Http\Controllers\JurisdictionController;
use App\Http\Controllers\SetupController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PHASE 3 = VERIFY + FLIP (operator plan 2026-08-31): acceptance refuses
 * with the live world-build report while phase 2 is incomplete, and the
 * two ingestion-surface holes stay closed (operator gate on pull-start,
 * the legacy seeder path refuses archive/folder).
 */
class AcceptGateTest extends TestCase
{
    private function onLivePg(callable $fn): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('live pg only');
        }
        DB::beginTransaction();
        try {
            $fn();
        } finally {
            DB::rollBack();
        }
    }

    private function operatorRequest(array $body, bool $operator = true): Request
    {
        $user = new User();
        $user->id = (string) Str::uuid();
        $user->is_operator = $operator;
        $req = Request::create('/api/jurisdictions/accept-maps', 'POST', $body);
        $req->setUserResolver(fn () => $user);

        return $req;
    }

    public function test_eager_acceptance_refuses_while_the_world_build_is_incomplete(): void
    {
        $this->onLivePg(function () {
            // One mapless fixture header holds the gate open.
            $legId = (string) Str::uuid();
            $jid   = (string) Str::uuid();
            DB::table('jurisdictions')->insert([
                'id' => $jid, 'name' => 'Gate Pin', 'slug' => 'gate-pin-'.substr($jid, 0, 8),
                'adm_level' => 6, 'population' => 1000, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('apportionment_ledger')->insert([
                'legislature_id' => $legId, 'jurisdiction_id' => $jid, 'population' => 1000,
                'head_seats' => 10, 'scope_count' => 0, 'compute_status' => 'done',
                'computed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            $res = app(JurisdictionController::class)->acceptMaps(
                $this->operatorRequest(['scale_mode' => 'eager'])
            );
            $this->assertSame(422, $res->getStatusCode());
            $payload = $res->getData(true);
            $this->assertTrue((bool) $payload['world_build_incomplete']);
            $this->assertGreaterThan(0, (int) $payload['progress']['maps']['unstamped'],
                'the 422 carries the live progress report');
        });
    }

    public function test_pull_start_requires_the_operator(): void
    {
        $this->onLivePg(function () {
            $req = Request::create('/api/setup/wizard/step2/pull-start', 'POST', []);
            $user = new User();
            $user->id = (string) Str::uuid();
            $user->is_operator = false;
            $req->setUserResolver(fn () => $user);

            try {
                app(SetupController::class)->startGeodataPull($req);
                $this->fail('non-operator pull-start must abort');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        });
    }

    public function test_legacy_start_refuses_archive_and_folder(): void
    {
        $this->onLivePg(function () {
            $req = Request::create('/api/setup/wizard/step2/start', 'POST', ['source' => 'archive']);
            $user = new User();
            $user->id = (string) Str::uuid();
            $user->is_operator = true;
            $req->setUserResolver(fn () => $user);

            $res = app(SetupController::class)->startMapData($req);
            $this->assertSame(422, $res->getStatusCode());
            $this->assertStringContainsString('pull engine', (string) $res->getData(true)['error']);
        });
    }
}
