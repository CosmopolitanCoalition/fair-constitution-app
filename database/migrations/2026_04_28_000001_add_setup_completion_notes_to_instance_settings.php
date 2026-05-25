<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture the data-quality review snapshot at the moment Setup is finished.
 *
 * The Setup wizard's Step 4 surfaces categorized issues (population gaps,
 * aggregation discrepancies, orphan jurisdictions, sovereign-territory
 * candidates). The operator can review and either remediate or accept and
 * finish setup. This column captures whatever the review state was at
 * setup-completion time so a future audit can see what was outstanding.
 *
 * Stores the top-level summary only (counts + severity) — NOT the full
 * row drill-downs which could grow to many MB on a world-scope run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->jsonb('setup_completion_notes')->nullable()->after('setup_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('setup_completion_notes');
        });
    }
};
