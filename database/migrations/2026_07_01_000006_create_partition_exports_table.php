<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F (WF-JUR-08) — a signed partition bundle for an authority flip.
 *
 * "Export bundle = seed": PartitionExportService dumps one jurisdiction subtree
 * (root + descendants + their institutions/laws/orgs) keyed to an
 * `audit_checkpoint`, checksums + signs it. The receiving instance ingests it
 * and both sides transfer `authoritative_server_id` for the subtree. The
 * `status` walks the two-phase flip:
 *   prepared → signed → transmitted → ingested → flip_committed
 *                    (failed / reverted on a no-ACK peer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partition_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('jurisdiction_id'); // subtree root
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            $table->string('direction', 8); // outbound | inbound

            $table->uuid('peer_id')->nullable();
            $table->foreign('peer_id')->references('id')->on('federation_peers')->nullOnDelete();

            // {root_jurisdiction_id, included_tables, table_counts, descendant_ids,
            //  checkpoint_audit_seq, schema_version, exported_at}
            $table->jsonb('manifest')->default('{}');

            // sha256 of the bundle archive bytes.
            $table->char('checksum', 64)->nullable();

            // The audit_checkpoints.audit_seq the bundle reflects.
            $table->bigInteger('checkpoint_audit_seq')->nullable();

            // The exporting instance's server_id + its signature over
            // checksum || checkpoint_audit_seq || root jurisdiction_id.
            $table->uuid('signed_by')->nullable();
            $table->text('signature')->nullable();

            $table->string('status', 20)->default('prepared');

            $table->timestampTz('authority_flipped_at')->nullable();
            $table->text('error')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('jurisdiction_id');
            $table->index('peer_id');
            $table->index('status');
        });

        DB::statement('ALTER TABLE partition_exports ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement("ALTER TABLE partition_exports ADD CONSTRAINT partition_exports_direction_check CHECK (direction IN ('outbound','inbound'))");
        DB::statement("
            ALTER TABLE partition_exports ADD CONSTRAINT partition_exports_status_check
            CHECK (status IN ('prepared','signed','transmitted','ingested','flip_committed','failed','reverted'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('partition_exports');
    }
};
