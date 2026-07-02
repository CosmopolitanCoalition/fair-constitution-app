<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * mockups-v3-wiring Phase 3c — the JOURNEYS ENGINE (MASTER_PLAN Phase 3):
 * durable per-user journey completion + the profile achievements ledger.
 *
 * TWO tables, two very different postures:
 *
 * `achievements` — the EARNED ledger. Mesh-replicated SHAPE
 * (source_server_id + audit_seq + append-only) per the public_records
 * conventions; registration in FederationSyncService is deliberately
 * deferred to Phase 4 (mesh code frozen this campaign) — see MASTER_PLAN
 * Phase 4. Append-only like public_records: a BEFORE UPDATE OR DELETE row
 * trigger blocks mutation, a statement trigger blocks TRUNCATE. The
 * `deleted_at` column exists ONLY for schema-parity with the
 * partial-unique pattern used across the codebase — because Laravel's
 * soft delete is an UPDATE, the trigger means it can never actually fire,
 * which is CORRECT for a ledger (an earned medal is never un-earned).
 * `user_id` is a soft reference (NO FK — immutability over cascade, the
 * audit_log/public_records posture). `title` denormalizes the journey
 * title at earn time so renames never rewrite history. `audit_seq` seals
 * each earn to the hash chain (the 'journeys'/'achievement/earned' entry
 * appended in the same transaction by JourneyService).
 *
 * `journey_progress` — NODE-LOCAL lesson state (which steps you've ticked
 * off). Mutable, never replicated, no trigger; a plain FK to users with
 * cascade (progress is worthless without its person).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── achievements — the append-only earned ledger ────────────────────
        Schema::create('achievements', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Soft ref, NO FK — immutability over cascade (see docblock).
            $table->uuid('user_id');
            $table->string('journey_id', 64);

            // Denormalized journey title at earn time.
            $table->string('title');

            // Replication origin marker (public_records conventions):
            // NULL = earned locally on this node.
            $table->uuid('source_server_id')->nullable();

            // Seals the earn into the audit chain (same-transaction entry).
            $table->unsignedBigInteger('audit_seq')->nullable();

            $table->timestampTz('earned_at');
            $table->timestampsTz();

            // Schema-parity with the partial-unique pattern only — the
            // append-only trigger below means soft delete can never fire.
            $table->softDeletesTz();

            $table->index('user_id');
            $table->index('journey_id');
        });

        DB::statement('ALTER TABLE achievements ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // One earn per person per journey (partial-unique pattern).
        DB::statement('
            CREATE UNIQUE INDEX achievements_user_journey_unique
            ON achievements (user_id, journey_id) WHERE deleted_at IS NULL
        ');

        // ── Append-only enforcement (the audit_log/public_records pattern) ──
        DB::statement("
            CREATE OR REPLACE FUNCTION achievements_block_mutation()
            RETURNS TRIGGER AS \$\$
            BEGIN
                RAISE EXCEPTION 'achievements is append-only: % is not permitted', TG_OP;
            END;
            \$\$ LANGUAGE plpgsql;
        ");
        DB::statement('
            CREATE TRIGGER achievements_immutable
            BEFORE UPDATE OR DELETE ON achievements
            FOR EACH ROW EXECUTE FUNCTION achievements_block_mutation();
        ');
        DB::statement('
            CREATE TRIGGER achievements_no_truncate
            BEFORE TRUNCATE ON achievements
            FOR EACH STATEMENT EXECUTE FUNCTION achievements_block_mutation();
        ');

        // ── journey_progress — node-local, mutable lesson state ─────────────
        Schema::create('journey_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('journey_id', 64);

            // Array of 0-based step indexes the user has marked done.
            $table->jsonb('steps_done')->default('[]');

            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'journey_id']);
        });

        DB::statement('ALTER TABLE journey_progress ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        // Dev-only escape hatch: rolling back destroys the earned ledger.
        DB::statement('DROP TRIGGER IF EXISTS achievements_immutable ON achievements');
        DB::statement('DROP TRIGGER IF EXISTS achievements_no_truncate ON achievements');
        Schema::dropIfExists('journey_progress');
        Schema::dropIfExists('achievements');
        DB::statement('DROP FUNCTION IF EXISTS achievements_block_mutation');
    }
};
