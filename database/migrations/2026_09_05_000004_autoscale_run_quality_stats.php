<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MAP QUALITY STATISTICS ON THE RUN (operator order 2026-09-05): the
 * planet-wide quality aggregates of a finished run — legality, contiguity,
 * population equality, compactness, community integrity for the Type A
 * district maps; legality, contiguity, uniform political diversity for the
 * Type B panel maps — computed ONCE when the run flips done (or by
 * `autoscale:quality-stats`) and cached on the run row, so the Step 3 poll
 * path never pays the aggregates. Additive, real-dated.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE autoscale_runs ADD COLUMN IF NOT EXISTS quality_stats jsonb');
        DB::statement('ALTER TABLE autoscale_runs ADD COLUMN IF NOT EXISTS quality_computed_at timestamptz');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE autoscale_runs DROP COLUMN IF EXISTS quality_computed_at');
        DB::statement('ALTER TABLE autoscale_runs DROP COLUMN IF EXISTS quality_stats');
    }
};
