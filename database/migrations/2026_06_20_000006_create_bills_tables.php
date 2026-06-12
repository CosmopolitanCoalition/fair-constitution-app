<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-6 (PHASE_C_DESIGN_votes_laws §A) — `bills` + `bill_versions`, ESM-07.
 *
 * `act_type` FIXES the floor-vote vote_type/basis at introduction
 * (F-LEG-003); `scale` (jurisdiction ids bound) is validated ⊆ the
 * legislature's subtree at introduction and fixed there (Art. V §4).
 * `committee_id` stays a plain uuid — the sibling committees migration
 * adds its FK (ALTER ... ADD CONSTRAINT). `enacted_law_id` gains its FK
 * in C-7 (cycle break). Also retro-adds the `motions.bill_id` FK promised
 * by C-4.
 *
 * `bill_versions` is append-only by convention: full text per version,
 * unique (bill_id, version_no).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('legislature_id');
            $table->foreign('legislature_id')->references('id')->on('legislatures')->restrictOnDelete();

            // Denormalized legislature anchor.
            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            $table->uuid('sponsor_member_id');
            $table->foreign('sponsor_member_id')->references('id')->on('legislature_members')->restrictOnDelete();

            $table->string('title');

            $table->string('act_type', 20);

            // Jurisdiction ids bound; default [own jurisdiction]; ⊆ subtree.
            $table->jsonb('scale');

            // Which judiciary hears disputes (stub rows exist since setup
            // Step 4).
            $table->uuid('scope_judiciary_id')->nullable();
            $table->foreign('scope_judiciary_id')->references('id')->on('judiciaries')->nullOnDelete();

            // F-LEG-031 path.
            $table->string('targets_setting_key')->nullable();
            $table->jsonb('proposed_value')->nullable();

            // null = effective at enactment.
            $table->timestampTz('effective_at')->nullable();

            $table->string('status', 16)->default('introduced');

            // Plain uuid; FK added by the sibling committees migration.
            $table->uuid('committee_id')->nullable();

            $table->smallInteger('current_version_no')->default(1);

            $table->timestampTz('introduced_at')->nullable();
            $table->timestampTz('passed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('enacted_at')->nullable();

            // FK added in C-7 (cycle break).
            $table->uuid('enacted_law_id')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['legislature_id', 'status']);
            $table->index('targets_setting_key');
        });

        DB::statement('ALTER TABLE bills ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("
            ALTER TABLE bills ADD CONSTRAINT bills_act_type_check
            CHECK (act_type IN ('ordinary','setting_change','supermajority','dual_supermajority'))
        ");
        DB::statement("
            ALTER TABLE bills ADD CONSTRAINT bills_status_check
            CHECK (status IN ('introduced','referred','in_committee','reported','tabled','on_floor','passed','failed','enacted','withdrawn'))
        ");
        DB::statement("
            ALTER TABLE bills ADD CONSTRAINT bills_setting_pairing_check
            CHECK ((act_type = 'setting_change') = (targets_setting_key IS NOT NULL))
        ");

        Schema::create('bill_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('bill_id');
            $table->foreign('bill_id')->references('id')->on('bills')->cascadeOnDelete();

            $table->smallInteger('version_no');
            $table->text('law_text');

            $table->uuid('changed_by_member_id')->nullable();
            $table->foreign('changed_by_member_id')->references('id')->on('legislature_members')->nullOnDelete();

            $table->string('change_kind', 24);

            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['bill_id', 'version_no']);
        });

        DB::statement('ALTER TABLE bill_versions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("
            ALTER TABLE bill_versions ADD CONSTRAINT bill_versions_change_kind_check
            CHECK (change_kind IN ('introduction','committee_amendment','floor_amendment'))
        ");

        // C-4 promised FK, now that bills exists.
        DB::statement('
            ALTER TABLE motions ADD CONSTRAINT motions_bill_id_foreign
            FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE SET NULL
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE motions DROP CONSTRAINT IF EXISTS motions_bill_id_foreign');
        Schema::dropIfExists('bill_versions');
        Schema::dropIfExists('bills');
    }
};
