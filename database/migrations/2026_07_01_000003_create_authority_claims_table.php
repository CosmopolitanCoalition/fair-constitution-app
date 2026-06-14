<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F (WF-JUR-08) — who is authoritative for a jurisdiction.
 *
 * Mirrors `jurisdictions.authoritative_server_id` (NULL = us) at the process
 * layer, with the authority-flip provenance: `claimed_by_peer_id` NULL means
 * THIS instance is authoritative; a peer id means that peer is. The partial
 * unique index pins exactly one recognized/uncontested authority per
 * jurisdiction — a jurisdiction cannot be doubly claimed without entering the
 * `negotiating` resolution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authority_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            // NULL = us. A peer id = that peer is authoritative for the subtree.
            $table->uuid('claimed_by_peer_id')->nullable();
            $table->foreign('claimed_by_peer_id')->references('id')->on('federation_peers')->nullOnDelete();

            $table->string('resolution', 16)->default('uncontested');

            $table->timestampTz('authority_flipped_at')->nullable();

            // The bundle that effected the flip (set during F3 authority flip).
            $table->uuid('partition_export_id')->nullable();

            $table->text('notes')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('jurisdiction_id');
            $table->index('claimed_by_peer_id');
        });

        DB::statement('ALTER TABLE authority_claims ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement("
            ALTER TABLE authority_claims ADD CONSTRAINT authority_claims_resolution_check
            CHECK (resolution IN ('uncontested','recognized','negotiating','mirrored'))
        ");

        // One active recognized/uncontested authority per jurisdiction.
        DB::statement(
            "CREATE UNIQUE INDEX authority_claims_one_authority_per_jurisdiction "
          . "ON authority_claims (jurisdiction_id) "
          . "WHERE deleted_at IS NULL AND resolution IN ('uncontested','recognized')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('authority_claims');
    }
};
