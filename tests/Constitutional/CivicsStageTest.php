<?php

namespace Tests\Constitutional;

use App\Models\Organization;
use App\Services\Demo\Stages\CivicsStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the CIVICS stage (census-flavored orgs, operator ruling
 * 2026-08-08, rubric sim-org-bill-rates = B).
 *
 * This is a smoke of the stage's leaf path: a populated leaf mints sampled
 * businesses and nonprofits, and the heartbeat closure fires while it does.
 * The heartbeat is not decoration — a large leaf's org loop is a long item, and
 * without the beat the lease would expire and the item be double-dispatched
 * (W7 item 1). The beat was wired into `mintOrgs` but for a time never received
 * the closure, so the loop referenced an undefined variable and threw at
 * runtime; this pins that it is threaded end to end.
 */
class CivicsStageTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_civicsstage';

    /** A populated leaf mints sampled orgs, and the heartbeat fires as it does. */
    public function test_a_populated_leaf_mints_orgs_and_fires_the_heartbeat(): void
    {
        $this->onLivePg(function () {
            $jid = $this->leaf(500_000);

            $beats = 0;
            $result = CivicsStage::run($jid, null, 1, function () use (&$beats) {
                $beats++;
            });

            // The leaf path mints sampled businesses and nonprofits (people live
            // at leaves; true counts ride the metrics, sampled rows land).
            $this->assertGreaterThan(0, $result['businesses']['minted'] ?? 0, 'a populated leaf mints businesses');
            $this->assertGreaterThan(
                0,
                DB::table('organizations')
                    ->where('jurisdiction_id', $jid)
                    ->where('type', Organization::TYPE_BUSINESS)
                    ->count(),
                'the sampled business rows landed'
            );

            $this->assertGreaterThan(0, $beats, 'the heartbeat closure fired during the org loop');
        });
    }

    /** Re-running mints no duplicates — the sampled count is a ceiling, not an addend. */
    public function test_re_running_does_not_double_mint(): void
    {
        $this->onLivePg(function () {
            $jid = $this->leaf(500_000);

            CivicsStage::run($jid, null, 1);
            $after1 = DB::table('organizations')->where('jurisdiction_id', $jid)->count();

            CivicsStage::run($jid, null, 1);
            $after2 = DB::table('organizations')->where('jurisdiction_id', $jid)->count();

            $this->assertSame($after1, $after2, 'the second pass tops up to the same ceiling, never doubles');
        });
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /** A childless jurisdiction with a population — the leaf org path's precondition. */
    private function leaf(int $population): string
    {
        $id = (string) Str::uuid();

        DB::table('jurisdictions')->insert([
            'id' => $id,
            'name' => 'Civics Pin',
            'slug' => 'civics-pin-'.Str::lower(Str::random(10)),
            'adm_level' => 4,
            'population' => $population,
            'source' => 'user_defined',
            'official_languages' => '["en"]',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
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
