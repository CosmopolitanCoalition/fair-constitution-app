<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-1 (PHASE_C_DESIGN_votes_laws §A) — `public_records`, the WF-SYS-03
 * curated public register. Distinct from `audit_log` (the raw chain):
 * records are the citizen-readable publication layer; every published
 * transition writes one row here via PublicRecordService INSIDE the
 * caller's transaction, sealed to the chain by `audit_seq`.
 *
 * CONVENTION EXCEPTIONS (deliberate — append-only table, same posture as
 * audit_log): no soft deletes, no updated_at; a BEFORE UPDATE OR DELETE
 * trigger blocks mutation and a statement trigger blocks TRUNCATE.
 * Corrections APPEND a new row pointing back via `supersedes_record_id`.
 *
 * `kind` is the votes-laws C-1 enum plus the chamber-ops additions
 * (`testimony`, `violation`) — one table serves both Phase C scopes.
 * `actor_user_id` carries NO FK (immutability over cascade);
 * `actor_display` snapshots the name at publication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_records', function (Blueprint $table) {
            // `seq` (publication order, bigint identity PK) added below —
            // Blueprint has no identity-PK helper alongside a uuid column.
            $table->uuid('id')->unique();

            $table->string('kind', 24);

            $table->string('title');
            $table->text('body')->nullable();

            // No FKs — immutability over cascade (see docblock).
            $table->uuid('actor_user_id')->nullable();
            $table->string('actor_display')->nullable();
            $table->uuid('jurisdiction_id')->nullable();
            $table->uuid('legislature_id')->nullable();

            // Canonical registry provenance (F-xxx / WF-xxx / CLK-xx).
            $table->string('via_form', 16)->nullable();
            $table->string('via_workflow', 16)->nullable();
            $table->string('via_clock', 8)->nullable();

            $table->string('subject_type', 40)->nullable();
            $table->uuid('subject_id')->nullable();

            // Seals the record into the audit chain (the 'records/published'
            // entry appended in the same transaction by PublicRecordService).
            $table->unsignedBigInteger('audit_seq')->nullable();

            // locale => {text, quality} — pipeline itself is Phase F; the
            // column renders honestly as "original only" until then.
            $table->jsonb('translations')->default('{}');

            // Corrections append, never edit.
            $table->uuid('supersedes_record_id')->nullable();

            $table->timestampTz('published_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('kind');
            $table->index('jurisdiction_id');
            $table->index('legislature_id');
            $table->index(['subject_type', 'subject_id']);
            $table->index('actor_user_id');
        });

        DB::statement('ALTER TABLE public_records ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE public_records ADD COLUMN seq BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY');

        DB::statement("
            ALTER TABLE public_records ADD CONSTRAINT public_records_kind_check
            CHECK (kind IN ('registration','residency','participation','statement','vote','bill',
                            'act','minutes','opinion','certification','testimony','violation',
                            'correction','other'))
        ");

        // ── Append-only enforcement (same pattern as audit_log) ─────────────
        DB::statement("
            CREATE OR REPLACE FUNCTION public_records_block_mutation()
            RETURNS TRIGGER AS \$\$
            BEGIN
                RAISE EXCEPTION 'public_records is append-only: % is not permitted', TG_OP;
            END;
            \$\$ LANGUAGE plpgsql;
        ");
        DB::statement('
            CREATE TRIGGER public_records_immutable
            BEFORE UPDATE OR DELETE ON public_records
            FOR EACH ROW EXECUTE FUNCTION public_records_block_mutation();
        ');
        DB::statement('
            CREATE TRIGGER public_records_no_truncate
            BEFORE TRUNCATE ON public_records
            FOR EACH STATEMENT EXECUTE FUNCTION public_records_block_mutation();
        ');
    }

    public function down(): void
    {
        // Dev-only escape hatch: rolling back destroys the public register.
        DB::statement('DROP TRIGGER IF EXISTS public_records_immutable ON public_records');
        DB::statement('DROP TRIGGER IF EXISTS public_records_no_truncate ON public_records');
        Schema::dropIfExists('public_records');
        DB::statement('DROP FUNCTION IF EXISTS public_records_block_mutation');
    }
};
