<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase K-3 (K3-I.1) — the M-5 legal-compliance floor (physical-law illegal-content removal), DISTINCT
 * from the four viewpoint carve-outs. Additive only. Extends the matrix_carveout_log vocabulary with
 * m5_legal + the byte-DESTROYING `purge` action (quarantine/redaction keep bytes — wrong for CSAM); adds
 * the public_records kinds (moderation_flip = the legitimacy-flip log; legal_compliance_removal = M-5,
 * COUNTED SEPARATELY from judicial 'violation'); and creates the immutable legal_compliance_removals
 * trail. GUARDRAIL: matrix_server_acls_carve_out_check is LEFT at m1/m4 only — M-5 may NEVER server-ACL
 * a whole jurisdiction (a country's illegal content can't silence its residents, Art. I).
 */
return new class extends Migration
{
    public function up(): void
    {
        // matrix_carveout_log — add m5_legal + the purge action (drop-and-re-add CHECK technique).
        DB::statement('ALTER TABLE matrix_carveout_log DROP CONSTRAINT matrix_carveout_log_carve_out_check');
        DB::statement(
            "ALTER TABLE matrix_carveout_log ADD CONSTRAINT matrix_carveout_log_carve_out_check "
          ."CHECK (carve_out IN ('m1_judicial','m2_rights','m4_antispam','m5_legal'))"
        );
        DB::statement('ALTER TABLE matrix_carveout_log DROP CONSTRAINT matrix_carveout_log_action_check');
        DB::statement(
            "ALTER TABLE matrix_carveout_log ADD CONSTRAINT matrix_carveout_log_action_check "
          ."CHECK (action IN ('soft_fail','hard_redact','server_acl','purge'))"
        );

        // public_records — add the two new kinds.
        DB::statement('ALTER TABLE public_records DROP CONSTRAINT public_records_kind_check');
        DB::statement(
            "ALTER TABLE public_records ADD CONSTRAINT public_records_kind_check "
          ."CHECK (kind IN ('registration','residency','participation','statement','vote','bill',"
          ."'act','minutes','opinion','certification','testimony','violation','correction','other',"
          ."'moderation_flip','legal_compliance_removal'))"
        );

        // The immutable legal-compliance trail (append-only physical-law evidence; no soft-deletes).
        Schema::create('legal_compliance_removals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('matrix_event_id', 255)->nullable();
            $table->string('matrix_room_id', 255)->nullable();
            $table->uuid('operator_account_id');                    // who signed (operator plane, key-possession)
            $table->string('legal_basis', 24);                      // csam_hashmatch | court_order_specific | true_threat
            $table->string('action', 16);                           // purge | soft_fail | hard_redact
            $table->text('statutory_citation')->nullable();         // cited order/statute (discretionary kinds)
            $table->string('matched_list_source', 120)->nullable(); // hash-list source (csam) — NEVER the hash itself
            $table->uuid('public_records_id')->nullable();          // the legal_compliance_removal record
            $table->uuid('jurisdiction_id')->nullable();
            $table->boolean('is_seated_at_time');
            $table->uuid('referral_record_id')->nullable();         // disclosure referral to seated constitutional actors
            $table->timestampsTz();
            $table->index('operator_account_id');
            $table->index('jurisdiction_id');
        });

        DB::statement('ALTER TABLE legal_compliance_removals ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE legal_compliance_removals ADD CONSTRAINT legal_compliance_removals_basis_check "
          ."CHECK (legal_basis IN ('csam_hashmatch','court_order_specific','true_threat'))"
        );
        DB::statement(
            "ALTER TABLE legal_compliance_removals ADD CONSTRAINT legal_compliance_removals_action_check "
          ."CHECK (action IN ('purge','soft_fail','hard_redact'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_compliance_removals');

        DB::statement('ALTER TABLE public_records DROP CONSTRAINT IF EXISTS public_records_kind_check');
        DB::statement(
            "ALTER TABLE public_records ADD CONSTRAINT public_records_kind_check "
          ."CHECK (kind IN ('registration','residency','participation','statement','vote','bill',"
          ."'act','minutes','opinion','certification','testimony','violation','correction','other'))"
        );

        DB::statement('ALTER TABLE matrix_carveout_log DROP CONSTRAINT IF EXISTS matrix_carveout_log_carve_out_check');
        DB::statement("ALTER TABLE matrix_carveout_log ADD CONSTRAINT matrix_carveout_log_carve_out_check CHECK (carve_out IN ('m1_judicial','m2_rights','m4_antispam'))");
        DB::statement('ALTER TABLE matrix_carveout_log DROP CONSTRAINT IF EXISTS matrix_carveout_log_action_check');
        DB::statement("ALTER TABLE matrix_carveout_log ADD CONSTRAINT matrix_carveout_log_action_check CHECK (action IN ('soft_fail','hard_redact','server_acl'))");
    }
};
