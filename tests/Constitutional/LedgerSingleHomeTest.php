<?php

namespace Tests\Constitutional;

use App\Support\AutoscaleEnumeration;
use App\Support\WorldBuildVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * THE LEDGER SINGLE HOME pins (operator plan 2026-08-31): facts written
 * once, work state beside them, no copies. Runs on live pg inside a
 * rolled-back transaction; every assertion is scoped to fixture rows.
 */
class LedgerSingleHomeTest extends TestCase
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

    private function mkHeader(array $over = []): string
    {
        $legId = (string) Str::uuid();
        $jid   = (string) Str::uuid();
        DB::table('jurisdictions')->insert([
            'id' => $jid, 'name' => 'Pin '.substr($legId, 0, 8), 'slug' => 'pin-'.substr($legId, 0, 8),
            'adm_level' => 6, 'population' => $over['population'] ?? 50000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('apportionment_ledger')->insert(array_merge([
            'legislature_id' => $legId, 'jurisdiction_id' => $jid,
            'population' => $over['population'] ?? 50000, 'head_seats' => 37,
            'scope_count' => 0, 'compute_status' => 'done', 'computed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], array_diff_key($over, ['population' => 1])));

        return $legId;
    }

    public function test_write_ledger_preserves_scope_work_state(): void
    {
        $this->onLivePg(function () {
            $legId = $this->mkHeader();
            $jid = (string) DB::table('apportionment_ledger')->where('legislature_id', $legId)->value('jurisdiction_id');

            $computed = ['steps' => [[$jid, 0, null, 37]], 'gate_reason' => null];
            AutoscaleEnumeration::writeLedger($legId, $jid, 37, $computed);

            // Work state lands on the row…
            DB::table('apportionment_ledger_scopes')
                ->where('legislature_id', $legId)->where('scope_jurisdiction_id', $jid)
                ->update(['status' => 'done', 'retry_count' => 2, 'reason' => 'drawn']);

            // …and a fact recompute rewrites facts WITHOUT touching it.
            $computed2 = ['steps' => [[$jid, 0, null, 38]], 'gate_reason' => null];
            AutoscaleEnumeration::writeLedger($legId, $jid, 38, $computed2);

            $row = DB::table('apportionment_ledger_scopes')
                ->where('legislature_id', $legId)->where('scope_jurisdiction_id', $jid)->first();
            $this->assertSame(38, (int) $row->seat_budget, 'fact column rewritten');
            $this->assertSame('done', $row->status, 'work status preserved through the fact upsert');
            $this->assertSame(2, (int) $row->retry_count, 'retry count preserved');
            $this->assertSame('drawn', $row->reason, 'reason preserved');
        });
    }

    public function test_refused_walk_deletes_stale_scope_rows(): void
    {
        $this->onLivePg(function () {
            $legId = $this->mkHeader();
            $jid = (string) DB::table('apportionment_ledger')->where('legislature_id', $legId)->value('jurisdiction_id');
            AutoscaleEnumeration::writeLedger($legId, $jid, 37, ['steps' => [[$jid, 0, null, 37]], 'gate_reason' => null]);
            AutoscaleEnumeration::writeLedger($legId, $jid, 37, ['steps' => [], 'gate_reason' => 'Pre-draw apportionment gate: pinned refusal']);

            $this->assertSame(0, (int) DB::table('apportionment_ledger_scopes')->where('legislature_id', $legId)->count(),
                'a refused map holds no lawful tree');
        });
    }

    public function test_gate_refusals_are_born_on_the_review_list(): void
    {
        $this->onLivePg(function () {
            $legId = $this->mkHeader(['gate_reason' => 'Pre-draw apportionment gate: pinned refusal']);
            AutoscaleEnumeration::stampGateRefusals();
            $row = DB::table('apportionment_ledger')->where('legislature_id', $legId)->first();
            $this->assertSame('review', $row->map_status, 'refused verdicts never claim');
            $this->assertSame('Pre-draw apportionment gate: pinned refusal', $row->reason);
        });
    }

    public function test_sweep_leaf_owns_exactly_one_self_scope(): void
    {
        $this->onLivePg(function () {
            $legId = $this->mkHeader(['kind' => 'sweep', 'child_count' => 0, 'head_seats' => 23, 'area_tier' => 2]);
            $jid = (string) DB::table('apportionment_ledger')->where('legislature_id', $legId)->value('jurisdiction_id');
            AutoscaleEnumeration::seedSweepLeafSelfScopes();
            $rows = DB::table('apportionment_ledger_scopes')->where('legislature_id', $legId)->get();
            $this->assertCount(1, $rows);
            $this->assertSame($jid, $rows[0]->scope_jurisdiction_id, 'the self scope IS the leaf');
            $this->assertSame(23, (int) $rows[0]->seat_budget, 'budget = the head');
            $this->assertSame(0, (int) $rows[0]->walk_position);
            // Idempotent.
            AutoscaleEnumeration::seedSweepLeafSelfScopes();
            $this->assertSame(1, (int) DB::table('apportionment_ledger_scopes')->where('legislature_id', $legId)->count());
        });
    }

    public function test_verifier_reports_every_completeness_piece(): void
    {
        $this->onLivePg(function () {
            $report = WorldBuildVerifier::report();
            foreach (['complete', 'legislatures', 'apportionment', 'adjacency', 'maps', 'block_keys_missing', 'board'] as $key) {
                $this->assertArrayHasKey($key, $report);
            }
            // A mapless fixture header must hold the gate open.
            $this->mkHeader();
            $after = WorldBuildVerifier::report();
            $this->assertFalse((bool) $after['complete'], 'an unstamped header blocks acceptance');
            $this->assertGreaterThan(0, (int) $after['maps']['unstamped']);
        });
    }
}
