<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;
    private const INDEXES = [
        'work_applications_owner_page_idx' => 'ON work_applications (applicant_account_id, id)',
        'work_applications_posting_page_idx' => 'ON work_applications (posting_id, id)',
        'work_postings_employer_page_idx' => 'ON work_postings (organization_id, id) WHERE deleted_at IS NULL',
        'organizations_hiring_agent_page_idx' => "ON organizations (agent_user_id, id) WHERE status = 'active' AND deleted_at IS NULL",
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('work_applications', 'offered_at')) Schema::table('work_applications', fn (Blueprint $table) => $table->timestampTz('offered_at')->nullable());
        if (! Schema::hasColumn('work_applications', 'offer_terms')) Schema::table('work_applications', fn (Blueprint $table) => $table->text('offer_terms')->nullable());
        // Keep the existing status constraint and org_contract_id; no old rows are rewritten.
        if (DB::getDriverName() !== 'pgsql') return;
        foreach (self::INDEXES as $name => $definition) {
            $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
            if ($index !== null && ! $index->indisvalid) DB::statement('DROP INDEX CONCURRENTLY '.$name);
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' '.$definition);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') foreach (array_reverse(array_keys(self::INDEXES)) as $name) DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        Schema::table('work_applications', fn (Blueprint $table) => $table->dropColumn(['offered_at', 'offer_terms']));
    }
};
