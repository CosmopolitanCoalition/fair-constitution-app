<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W-0490 — which node kits this box runs, and the scope of each.
 * hosted_node_types: JSON list of kit ids (individual is always present).
 * node_scopes: JSON map kit id -> {scope: free text, mode?: simulation|real}.
 * Existing boxes keep today's behavior: individual and organization on, community
 * on when setup is complete, household off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function ($t) {
            $t->jsonb('hosted_node_types')->nullable();
            $t->jsonb('node_scopes')->nullable();
        });

        DB::table('instance_settings')->whereNull('hosted_node_types')->update([
            'hosted_node_types' => json_encode(['individual', 'organization']),
            'node_scopes' => json_encode((object) []),
        ]);
        DB::table('instance_settings')->whereNotNull('setup_completed_at')->update([
            'hosted_node_types' => json_encode(['individual', 'organization', 'community']),
        ]);
    }

    public function down(): void
    {
        Schema::table('instance_settings', function ($t) {
            $t->dropColumn(['hosted_node_types', 'node_scopes']);
        });
    }
};
