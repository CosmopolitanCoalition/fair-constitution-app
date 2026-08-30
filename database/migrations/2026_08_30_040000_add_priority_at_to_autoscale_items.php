<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DEMAND PRIORITY (operator order 2026-08-30, world-entry-early): viewing a
 * legislature whose founding map is still on the pile stamps priority_at on
 * its item; the claim ladder serves stamped items first, so whatever people
 * actually look at draws next. Additive, real-dated (post-flatten law).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE autoscale_items ADD COLUMN IF NOT EXISTS priority_at timestamptz');
        DB::statement('CREATE INDEX IF NOT EXISTS autoscale_items_priority_idx
                           ON autoscale_items (priority_at)
                        WHERE priority_at IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS autoscale_items_priority_idx');
        DB::statement('ALTER TABLE autoscale_items DROP COLUMN IF EXISTS priority_at');
    }
};
