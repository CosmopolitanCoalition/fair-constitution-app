<?php

use Database\Seeders\ClockRegistrySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the 21 constitutional clocks as part of MIGRATION (2026-08-08).
 *
 * THE FRESH-INSTALL DEFECT this closes: nothing in the install path ever
 * seeded them — not get-started.ps1/.sh, not UpdateWoS.ps1, and
 * DatabaseSeeder calls no seeders. So a virgin world came up with an EMPTY
 * `clocks` table, and the first jurisdiction activation died with
 * "Unknown clock [CLK-18] — registry seeded?" the moment it scheduled the
 * bootstrap election (operator-caught 2026-08-08, HTTP 500 on the UK row).
 *
 * The registry is DEFINITION data, not world data — the same 21 rows on
 * every instance, changed only by shipping code — so a migration is its
 * right home: every box (fresh clone, existing world, cloud template) gets
 * it from `php artisan migrate`, which both install scripts already run.
 *
 * ONE definition, not two: this calls the seeder's own registry, which
 * upserts on the string PK. Idempotent — re-running refreshes definitions
 * without duplicating rows or touching any armed `clock_timers`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = array_map(
            fn (array $clock) => [
                'id'             => $clock['id'],
                'name'           => $clock['name'],
                'type'           => $clock['type'],
                'default_value'  => json_encode($clock['default_value']),
                'amendable'      => $clock['amendable'],
                'fires_workflow' => $clock['fires_workflow'],
                'basis'          => $clock['basis'],
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            ClockRegistrySeeder::registry(),
        );

        DB::table('clocks')->upsert(
            $rows,
            ['id'],
            ['name', 'type', 'default_value', 'amendable', 'fires_workflow', 'basis', 'updated_at'],
        );
    }

    public function down(): void
    {
        // Definitions only — armed timers reference them, and dropping the
        // registry would strand every timer. Deliberately irreversible.
    }
};
