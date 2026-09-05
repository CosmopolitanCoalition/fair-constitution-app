<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TYPE B PANELS AS THE LAST SCOPE OF EVERY COMPOSITE MAP (operator order
 * 2026-09-05). Every legislature with constituents gets a Type B panel map,
 * drawn by the same lanes as the Type A scopes: one extra scope row per
 * composite header, kind 'type_b', keyed on the ROOT jurisdiction, walked
 * LAST (after the Type A root scope). The scope table's key grows a kind so
 * the Type A root scope and the Type B panel scope of one map coexist.
 *
 *  - apportionment_ledger_scopes.scope_kind: 'type_a' (default, every existing
 *    row) | 'type_b'. Primary key becomes (legislature, scope, kind).
 *  - autoscale_runs.type_b_seeded_at: the pump's latch for the one-time
 *    materialization pass that adds the missing Type B scopes to a run that
 *    predates this change (cleared by every resume so an upgraded box heals).
 *
 * Additive, real-dated. The key swap rebuilds one index over ~560k rows
 * (seconds); the run is parked while it lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE apportionment_ledger_scopes ADD COLUMN IF NOT EXISTS scope_kind varchar(8) NOT NULL DEFAULT 'type_a'");
        DB::statement('ALTER TABLE apportionment_ledger_scopes DROP CONSTRAINT IF EXISTS apportionment_ledger_scopes_pkey');
        DB::statement('ALTER TABLE apportionment_ledger_scopes ADD CONSTRAINT apportionment_ledger_scopes_pkey PRIMARY KEY (legislature_id, scope_jurisdiction_id, scope_kind)');
        DB::statement("CREATE INDEX IF NOT EXISTS als_kind_status_idx ON apportionment_ledger_scopes (scope_kind, status) WHERE scope_kind = 'type_b'");
        DB::statement('ALTER TABLE autoscale_runs ADD COLUMN IF NOT EXISTS type_b_seeded_at timestamptz');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE autoscale_runs DROP COLUMN IF EXISTS type_b_seeded_at');
        DB::statement('DROP INDEX IF EXISTS als_kind_status_idx');
        DB::statement("DELETE FROM apportionment_ledger_scopes WHERE scope_kind = 'type_b'");
        DB::statement('ALTER TABLE apportionment_ledger_scopes DROP CONSTRAINT IF EXISTS apportionment_ledger_scopes_pkey');
        DB::statement('ALTER TABLE apportionment_ledger_scopes ADD CONSTRAINT apportionment_ledger_scopes_pkey PRIMARY KEY (legislature_id, scope_jurisdiction_id)');
        DB::statement('ALTER TABLE apportionment_ledger_scopes DROP COLUMN IF EXISTS scope_kind');
    }
};
