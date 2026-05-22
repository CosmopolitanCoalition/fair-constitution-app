<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds "Custom Universe" as a disabled sibling of Observable Universe under the
 * Multiverse root. Slot for future user-authored / fantasy / TTRPG universes
 * — the same seam that future real-astronomy universes would plug into.
 *
 * Sort order places it between Observable Universe (0) and No Map (10).
 */
return new class extends Migration
{
    public function up(): void
    {
        $multiverseId = DB::table('cosmic_addresses')
            ->where('type', 'multiverse')
            ->whereNull('parent_id')
            ->value('id');

        if (!$multiverseId) {
            return;
        }

        $exists = DB::table('cosmic_addresses')
            ->where('parent_id', $multiverseId)
            ->where('type', 'custom_universe')
            ->exists();

        if ($exists) {
            return;
        }

        $now = now();
        DB::table('cosmic_addresses')->insert([
            'id'         => (string) Str::uuid(),
            'parent_id'  => $multiverseId,
            'label'      => 'Custom Universe',
            'slug'       => 'custom-universe',
            'type'       => 'custom_universe',
            'subtype'    => null,
            'enabled'    => false,
            'source'     => 'seed',
            'sort_order' => 5,
            'metadata'   => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('cosmic_addresses')
            ->where('type', 'custom_universe')
            ->where('slug', 'custom-universe')
            ->delete();
    }
};
