<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The setup wizard now collects constitutional defaults BEFORE loading map data,
 * so ADM0 may not exist yet when the user submits the Constants step. Stash the
 * payload here as JSON; activateMapData applies it to constitutional_settings
 * for ADM0 as soon as the root jurisdiction is present. Cleared on apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE instance_settings ADD COLUMN IF NOT EXISTS pending_constitutional_defaults jsonb');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE instance_settings DROP COLUMN IF EXISTS pending_constitutional_defaults');
    }
};
