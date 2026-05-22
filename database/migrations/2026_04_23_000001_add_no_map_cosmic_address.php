<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds "No Map" as a disabled sibling of Observable Universe under the Multiverse
 * root. It appears in the "Universe" dropdown alongside Observable Universe so a
 * future geography-less instance mode slots in without UI redesign.
 *
 * Replaces the old standalone "Map Mode" radio group in Step 0 — with No Map
 * living here, Physical Earth / Elsewhere are just different world choices under
 * Observable Universe, and Multiverse is the implicit root.
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
            ->where('type', 'no_map')
            ->exists();

        if ($exists) {
            return;
        }

        $now = now();
        DB::table('cosmic_addresses')->insert([
            'id'         => (string) Str::uuid(),
            'parent_id'  => $multiverseId,
            'label'      => 'No Map',
            'slug'       => 'no-map',
            'type'       => 'no_map',
            'subtype'    => null,
            'enabled'    => false,
            'source'     => 'seed',
            'sort_order' => 10,
            'metadata'   => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('cosmic_addresses')
            ->where('type', 'no_map')
            ->where('slug', 'no-map')
            ->delete();
    }
};
