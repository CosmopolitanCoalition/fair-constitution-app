<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE SCOPE CLASS STAMP (operator order 2026-09-02, the segmented layer
 * bars): every drawn scope is one of two classes — a LINE-SPLIT (a childless
 * leaf giant, cut by the box / line templates) or a COMPOSITE (a scope with
 * children, drawn by the composite planner). The class is a fact of the
 * jurisdiction tree, stamped once at enumeration, so the Step-3 panel groups
 * by it in the same scan it already makes — never by a per-poll join over
 * the planet's headers (measured 11.7 s under 13 lanes).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE apportionment_ledger_scopes ADD COLUMN IF NOT EXISTS is_leaf boolean');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE apportionment_ledger_scopes DROP COLUMN IF EXISTS is_leaf');
    }
};
