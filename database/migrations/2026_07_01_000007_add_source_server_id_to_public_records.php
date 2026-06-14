<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F (WF-JUR-06) — provenance for Full-Faith-&-Credit mirrored records.
 *
 * `public_records` is the citizen-readable register (append-only). When a peer
 * ships its public Acts/Records/Judicial proceedings (Art. V §2 FF&C), we mirror
 * the rows we are NOT authoritative for, tagged with the origin peer's
 * server_id. NULL = this instance is the origin (a locally-published record).
 *
 * The unique `id` (cross-instance uuid) already lets a re-synced record dedupe;
 * this column lets the UI/queries distinguish "recognized" peer records from our
 * own, and lets the sync ingester enforce authoritative-instance-wins.
 *
 * ADD COLUMN is DDL — the table's BEFORE UPDATE/DELETE immutability trigger does
 * not fire, and existing rows keep NULL (local origin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_records', function (Blueprint $table) {
            $table->uuid('source_server_id')->nullable()
                ->comment('Phase F FF&C: origin peer server_id; NULL = locally published');
            $table->index('source_server_id');
        });
    }

    public function down(): void
    {
        Schema::table('public_records', function (Blueprint $table) {
            $table->dropIndex(['source_server_id']);
            $table->dropColumn('source_server_id');
        });
    }
};
