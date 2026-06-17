<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G3c — decision N3) — the GEODATA_ORIGIN signed-dataset MANIFEST channel.
 * Geospatial datasets (WorldPop rasters, geoBoundaries) are large + license-bound,
 * so they ride a SEPARATE, optional, signed channel — never the Full-Faith-&-Credit
 * audit tail a mirror pulls. Each manifest is signed by its ORIGIN instance (like a
 * DirectoryService entry / a mesh-operator key binding — verified against the named
 * server's pinned key, never the relayer's). This table is the manifest LEDGER:
 * which dataset+version exists, its digest + license + size, who signed it, and when
 * THIS instance fetched it (null = self-published). The raster BYTES transport lands
 * with Phase H — the first runtime raster consumer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geodata_dataset_manifests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('dataset', 64);      // worldpop-2023-100m, geoboundaries-adm, ...
            $table->string('version', 32);      // 2023.06.28
            $table->char('sha256', 64);         // the dataset artifact digest
            $table->string('license', 32);      // CC-BY-4.0, ODbL, ...
            $table->bigInteger('size_bytes');   // artifact size
            $table->uuid('origin_server_id');   // the instance that published + signed this manifest
            $table->text('signature');          // Ed25519 sig by origin_server_id over the canonical manifest
            $table->timestampTz('fetched_at')->nullable(); // when THIS instance pulled it (null = self-published)

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('dataset');
        });

        DB::statement('ALTER TABLE geodata_dataset_manifests ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        // One manifest per (dataset, version) per origin — re-publishing the same
        // version is idempotent; distinct origins may each attest the same dataset.
        DB::statement(
            'CREATE UNIQUE INDEX geodata_dataset_manifests_unique '
          .'ON geodata_dataset_manifests (dataset, version, origin_server_id) '
          .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('geodata_dataset_manifests');
    }
};
