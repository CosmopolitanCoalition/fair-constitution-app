<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * W-0201: register CLK-22 (Civic Stipend Period) so the standalone civic
 * stipend has a clock to arm and fire from.
 *
 * The clock registry is DEFINITION data — the same rows on every instance,
 * changed only by shipping code — so a migration is its home, exactly like
 * 2026_08_08_220000_seed_clock_registry. Additive and idempotent: it upserts
 * the single new row on the string PK, touching no armed clock_timers and no
 * existing definitions.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('clocks')->upsert(
            [[
                'id'             => 'CLK-22',
                'name'           => 'Civic Stipend Period',
                'type'           => 'recurring',
                'default_value'  => json_encode([
                    'value'       => 30,
                    'unit'        => 'days',
                    'setting_key' => 'stipend_period_days',
                    'mode'        => 'amendable',
                ]),
                'amendable'      => true,
                'fires_workflow' => 'F-TRE-004 civic stipend run',
                'basis'          => 'Art. II §9 · [POLICY]',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]],
            ['id'],
            ['name', 'type', 'default_value', 'amendable', 'fires_workflow', 'basis', 'updated_at']
        );
    }

    public function down(): void
    {
        DB::table('clocks')->where('id', 'CLK-22')->delete();
    }
};
