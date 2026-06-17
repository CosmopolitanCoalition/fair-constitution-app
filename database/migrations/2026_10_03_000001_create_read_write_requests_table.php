<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G3c) — the read-write request intake: the GOVERNED front door, kept
 * OFF the mirror-admission path. A mirror that wants to become a read-write peer
 * for a jurisdiction submits one of these; it is NOT an adoption (that stays a
 * read-only mirror, authoritative for nothing). Granting is a constitutional act
 * decided by the jurisdiction's standing government (the Art. V §7 dual
 * supermajority via LocalAutonomyService, G6) or — when there is no standing
 * board — the de-facto operator board (G-VER). This table is the request + its
 * status; the actual flip rides the existing authority-flip + operational-bundle
 * machinery. It is a public record (Art. II §2 — a government receiving a
 * read-write petition).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('read_write_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('applicant_server_id');               // the requesting mirror's identity
            $table->text('applicant_public_key')->nullable();
            $table->uuid('root_jurisdiction_id');              // the subtree it wants read-write over
            $table->string('status', 24)->default('submitted'); // submitted|vote_opened|granted|denied|withdrawn
            $table->uuid('autonomy_process_id')->nullable();   // links the G6 local_autonomy_processes vote once opened
            $table->text('note')->nullable();                  // applicant justification
            $table->timestampTz('submitted_at');
            $table->timestampTz('resolved_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('applicant_server_id');
            $table->index('root_jurisdiction_id');
        });

        DB::statement('ALTER TABLE read_write_requests ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            'ALTER TABLE read_write_requests ADD CONSTRAINT read_write_requests_status_check '
          ."CHECK (status IN ('submitted','vote_opened','granted','denied','withdrawn'))"
        );
        // At most one OPEN petition per (applicant, jurisdiction) — anti-duplicate.
        DB::statement(
            'CREATE UNIQUE INDEX read_write_requests_open_unique '
          .'ON read_write_requests (applicant_server_id, root_jurisdiction_id) '
          ."WHERE deleted_at IS NULL AND status IN ('submitted','vote_opened')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('read_write_requests');
    }
};
