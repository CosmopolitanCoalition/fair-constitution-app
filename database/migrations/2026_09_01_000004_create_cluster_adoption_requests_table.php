<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G2) — the host's adoption queue + nonce ledger.
 *
 * Every `POST /api/federation/adopt` records a request row. The
 * `(applicant_server_id, nonce)` partial-unique index is the anti-replay
 * backstop: a captured valid adoption body replayed in-window collides on the
 * nonce and is refused (409) — the 300s replay window stops most replays, this
 * stops the rest. `status` walks pending → admitted | rejected | expired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cluster_adoption_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('applicant_server_id');
            $table->text('applicant_public_key');
            $table->string('nonce', 64);

            $table->string('admission_method', 12)->default('join_key'); // join_key | request
            $table->string('status', 12)->default('pending');            // pending | admitted | rejected | expired

            $table->string('join_key_handle', 16)->nullable();
            $table->uuid('cluster_membership_id')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('applicant_server_id');
            $table->index('status');
        });

        DB::statement('ALTER TABLE cluster_adoption_requests ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE cluster_adoption_requests ADD CONSTRAINT cluster_adoption_requests_method_check CHECK (admission_method IN ('join_key','request'))");
        DB::statement("ALTER TABLE cluster_adoption_requests ADD CONSTRAINT cluster_adoption_requests_status_check CHECK (status IN ('pending','admitted','rejected','expired'))");

        // Anti-replay: one request per (applicant, nonce).
        DB::statement(
            'CREATE UNIQUE INDEX cluster_adoption_requests_applicant_nonce_unique '
          .'ON cluster_adoption_requests (applicant_server_id, nonce) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_adoption_requests');
    }
};
