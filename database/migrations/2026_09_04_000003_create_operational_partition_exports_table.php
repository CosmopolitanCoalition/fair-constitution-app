<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G5 operational seed) — the LEDGER of operational-bundle transfers on an
 * autonomy flip. The operational bundle carries the PRIVATE rows that cannot ride
 * the routine public-records sync tail — first and foremost each election's raw
 * per-election key k_e, which the gaining cluster needs to re-wrap (G5a) so its
 * historical ballots stay re-countable. The bundle travels SEALED (libsodium
 * sealed box) to the gaining cluster's key; only that cluster can open it.
 *
 * This table is EVIDENCE ONLY: who sealed/applied a bundle for which subtree, how
 * many election keys it carried, how many verified-and-applied, and a fingerprint
 * of the (opaque) sealed blob. It holds NO key material and NOT the sealed blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_partition_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('root_jurisdiction_id')->nullable();
            $table->string('direction', 8); // outbound | inbound
            $table->uuid('peer_server_id')->nullable();

            $table->integer('election_count')->default(0);
            $table->integer('applied_count')->default(0);

            // sha256 of the sealed blob (already ciphertext) — never the blob itself.
            $table->string('sealed_fingerprint', 128)->nullable();

            // sealed | applied | partial | failed
            $table->string('status', 12)->default('sealed');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('root_jurisdiction_id');
            $table->index('peer_server_id');
        });

        DB::statement('ALTER TABLE operational_partition_exports ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE operational_partition_exports ADD CONSTRAINT operational_partition_exports_direction_check "
          ."CHECK (direction IN ('outbound','inbound'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_partition_exports');
    }
};
