<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G3c) — carry the join-wizard NEGOTIATION on the adoption request, and
 * record the instance's geodata posture. All additive + nullable (no protected
 * migration touched). `requested_relation` is ADVISORY at adoption — 'co_member'
 * never auto-grants read-write; it only tells the host operator the applicant also
 * intends to pursue the governed Art. V §7 flip. Admission still produces a plain
 * read-only mirror (authoritative for nothing). `geodata_posture` is the instance's
 * recorded choice from the wizard's "what to pull" step (the signed GEODATA_ORIGIN
 * channel, G3c N3); the raster bytes land with Phase H.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cluster_adoption_requests', function (Blueprint $table) {
            $table->string('requested_relation', 16)->nullable();        // mirror | co_member (advisory)
            $table->uuid('requested_scope_jurisdiction_id')->nullable(); // null = whole corpus
            $table->string('applicant_name')->nullable();                // self-declared label for the review queue
            $table->string('applicant_url')->nullable();                 // persist the callback URL
            $table->text('note')->nullable();                            // free-text from the applicant
        });

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('geodata_posture', 24)->nullable();           // already_have | pull_from_origin | skip
        });

        DB::statement(
            'ALTER TABLE cluster_adoption_requests ADD CONSTRAINT cluster_adoption_requests_relation_check '
          ."CHECK (requested_relation IS NULL OR requested_relation IN ('mirror','co_member'))"
        );
        DB::statement(
            'ALTER TABLE instance_settings ADD CONSTRAINT instance_settings_geodata_posture_check '
          ."CHECK (geodata_posture IS NULL OR geodata_posture IN ('already_have','pull_from_origin','skip'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cluster_adoption_requests DROP CONSTRAINT IF EXISTS cluster_adoption_requests_relation_check');
        DB::statement('ALTER TABLE instance_settings DROP CONSTRAINT IF EXISTS instance_settings_geodata_posture_check');

        Schema::table('cluster_adoption_requests', function (Blueprint $table) {
            $table->dropColumn([
                'requested_relation',
                'requested_scope_jurisdiction_id',
                'applicant_name',
                'applicant_url',
                'note',
            ]);
        });

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('geodata_posture');
        });
    }
};
