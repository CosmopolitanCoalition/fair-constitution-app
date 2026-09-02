<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Two piles by class: a SHARE of the derived pool (operator 2026-09-02, the 96-core box). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE autoscale_runs ADD COLUMN IF NOT EXISTS leaf_lanes_pct smallint');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE autoscale_runs DROP COLUMN IF EXISTS leaf_lanes_pct');
    }
};
