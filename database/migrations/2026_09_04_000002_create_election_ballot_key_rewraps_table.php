<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G5a ballot re-wrap) — the LEDGER of ballot-key re-wraps performed on
 * an autonomy flip. When authority for a subtree moves to a gaining cluster, each
 * historical election's per-election data key k_e is re-wrapped under the gaining
 * cluster's KEK so its ballots stay re-countable (the encrypted k_e itself rides
 * the G5 operational bundle, never the routine sync tail).
 *
 * This table stores ONLY EVIDENCE — never key material, never a wrapped blob, never
 * a ballot. Fingerprints are sha256 of the (already-ciphertext) wrapped blobs; the
 * count digest is over PUBLIC certified record_hashes. It is the audit trail that a
 * re-wrap happened AND was proven to reproduce the certified counts before commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('election_ballot_key_rewraps', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('election_id');
            $table->uuid('jurisdiction_id')->nullable();

            // The relinquishing / gaining clusters (soft refs; either may be null).
            $table->uuid('from_cluster_id')->nullable();
            $table->uuid('to_cluster_id')->nullable();

            // EVIDENCE ONLY — sha256 fingerprints of the wrapped blobs (ciphertext),
            // never the blobs themselves, never k_e.
            $table->string('prior_wrap_fingerprint', 128)->nullable();
            $table->string('new_wrap_fingerprint', 128);

            // How many races reproduced their certified record_hash, and a digest
            // over those PUBLIC certified hashes (the fail-closed proof artifact).
            $table->integer('races_verified')->default(0);
            $table->string('count_record_digest', 128)->nullable();

            $table->timestampTz('verified_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('election_id');
            $table->index('to_cluster_id');
        });

        DB::statement('ALTER TABLE election_ballot_key_rewraps ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('election_ballot_key_rewraps');
    }
};
