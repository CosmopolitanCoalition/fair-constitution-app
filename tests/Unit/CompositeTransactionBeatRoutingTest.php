<?php

namespace Tests\Unit;

use App\Services\DistrictingService;
use App\Support\AutoscaleContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * THE BEAT CONNECTION routing pins (2026-09-02, the composite transaction
 * split). publishMassProgress writes a lane's ledger scope row through the
 * named connection DistrictingService::BEAT_CONNECTION, never the default
 * connection, and only when the scope row carries the lane's claim token.
 *
 * Runs on the default sqlite:memory test database with no live Postgres.
 * The beat connection is re-pointed at a second in-memory sqlite database
 * that holds the scopes table. The default connection holds no such table,
 * so a beat routed to the default connection fails (the beat swallows it)
 * and leaves the row untouched. The lease UPDATE uses Postgres functions
 * and fails silently on sqlite; CompositeTransactionBeatVisibilityTest
 * covers it on live Postgres.
 */
class CompositeTransactionBeatRoutingTest extends TestCase
{
    private const OLD = '2000-01-01 00:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.DistrictingService::BEAT_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge(DistrictingService::BEAT_CONNECTION);
        Schema::connection(DistrictingService::BEAT_CONNECTION)
            ->create('apportionment_ledger_scopes', function ($t) {
                $t->string('id')->primary();
                $t->string('status');
                $t->string('claim_token')->nullable();
                $t->text('step_timings')->nullable();
                $t->string('updated_at');
            });
    }

    protected function tearDown(): void
    {
        AutoscaleContext::clear();
        DB::purge(DistrictingService::BEAT_CONNECTION);
        parent::tearDown();
    }

    public function test_shipped_beat_connection_mirrors_pgsql(): void
    {
        // The shipped file, not the container binding: setUp re-points the
        // binding at sqlite for the routing pins below.
        $shipped = require base_path('config/database.php');

        $this->assertArrayHasKey(DistrictingService::BEAT_CONNECTION, $shipped['connections']);
        $this->assertSame(
            $shipped['connections']['pgsql'],
            $shipped['connections'][DistrictingService::BEAT_CONNECTION],
            'pgsql_beat is a copy of pgsql: same driver, host, port, database, credentials, search_path'
        );
    }

    public function test_beat_connection_is_cached_per_name(): void
    {
        $this->assertSame(
            DB::connection(DistrictingService::BEAT_CONNECTION),
            DB::connection(DistrictingService::BEAT_CONNECTION),
            'Laravel hands back the same connection object per name: one beat session per process, never one per beat'
        );
    }

    public function test_scope_beat_routes_through_the_beat_connection_and_honors_the_claim_token(): void
    {
        $beat  = DB::connection(DistrictingService::BEAT_CONNECTION);
        $scope = (string) Str::uuid();
        $token = (string) Str::uuid();
        $beat->table('apportionment_ledger_scopes')->insert([
            'id' => $scope, 'status' => 'running', 'claim_token' => $token, 'updated_at' => self::OLD,
        ]);
        $this->assertFalse(
            Schema::hasTable('apportionment_ledger_scopes'),
            'the default connection holds no scopes table: a beat routed there cannot update the row'
        );

        $svc = app(DistrictingService::class);
        $updatedAt = fn () => $beat->table('apportionment_ledger_scopes')->where('id', $scope)->value('updated_at');

        // (b) A token other than the row's claim_token: the row is not updated.
        AutoscaleContext::enter((string) Str::uuid(), 'item', $scope, (string) Str::uuid());
        $svc->publishMassProgress('leg', ['phase' => 'binning', 'phase_label' => 'stranger']);
        $this->assertSame(self::OLD, $updatedAt(), 'a stranger token does not refresh the scope row');

        // The lane's own token: the row is updated, through the beat connection.
        AutoscaleContext::enter((string) Str::uuid(), 'item', $scope, $token);
        $svc->publishMassProgress('leg', ['phase' => 'binning', 'phase_label' => 'probe']);
        $this->assertNotSame(self::OLD, $updatedAt(), 'the lane\'s own token refreshes the scope row on the beat connection');

        // A context without a token (tests, interactive probes) beats unguarded.
        $beat->table('apportionment_ledger_scopes')->where('id', $scope)->update(['updated_at' => self::OLD]);
        AutoscaleContext::enter((string) Str::uuid(), 'item', $scope, null);
        $svc->publishMassProgress('leg', ['phase' => 'binning', 'phase_label' => 'no token']);
        $this->assertNotSame(self::OLD, $updatedAt(), 'without a token the scope row beats as before');

        // A row that is no longer running is never touched.
        $beat->table('apportionment_ledger_scopes')->where('id', $scope)
            ->update(['updated_at' => self::OLD, 'status' => 'done']);
        AutoscaleContext::enter((string) Str::uuid(), 'item', $scope, $token);
        $svc->publishMassProgress('leg', ['phase' => 'binning', 'phase_label' => 'late']);
        $this->assertSame(self::OLD, $updatedAt(), 'a done scope row is not beat-touched');
    }
}
