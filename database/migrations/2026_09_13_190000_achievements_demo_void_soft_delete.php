<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AC-1: let a demo-session void retire the achievement rows it created.
 *
 * The `achievements` ledger is append-only: the `achievements_immutable`
 * trigger runs `achievements_block_mutation()` BEFORE every UPDATE/DELETE and
 * raises unconditionally. That is correct for civic awards, but it also blocks
 * the demo purge: `achievements` is NOT in DemoMode::CAPTURE_EXCLUDED (per the
 * operator ruling, demo awards ARE captured), so a demo award is recorded by
 * the `cga_demo_capture` trigger, and at void `DemoSessionService::reverse`
 * soft-deletes the captured INSERT with `UPDATE ... SET deleted_at = now()`.
 * The unconditional raise turned that soft delete into a caught 'skipped'
 * write, so a demo medal persisted permanently — the ruling's "voided like any
 * other demo write" half was unmet.
 *
 * This redefines `achievements_block_mutation()` to permit EXACTLY ONE case:
 * an UPDATE that stamps `deleted_at` (NULL -> value) with every other column
 * unchanged, while the transaction-local demo-void GUC (DemoMode::VOID_GUC =
 * 'cga.demo_void') is '1'. DemoSessionService::reverse sets that GUC around the
 * soft delete only. Every other UPDATE, every DELETE, and TRUNCATE stay blocked
 * — the ledger remains append-only for all non-demo mutation.
 *
 * Additive: CREATE OR REPLACE of a single trigger function. No table change.
 * The down() restores the original unconditional-raise body.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('achievements')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.achievements_block_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
        BEGIN
            -- Demo-void soft delete: a demo session's reversal may retire the
            -- rows it created. Permit ONLY a deleted_at stamp (NULL -> value),
            -- every other column unchanged, while the demo-void GUC is set by
            -- DemoSessionService::reverse. Everything else stays append-only.
            IF TG_OP = 'UPDATE'
               AND coalesce(current_setting('cga.demo_void', true), '') = '1'
               AND OLD.deleted_at IS NULL
               AND NEW.deleted_at IS NOT NULL
               AND (to_jsonb(NEW) - 'deleted_at') = (to_jsonb(OLD) - 'deleted_at')
            THEN
                RETURN NEW;
            END IF;
            RAISE EXCEPTION 'achievements is append-only: % is not permitted', TG_OP;
        END;
        $$;
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('achievements')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.achievements_block_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RAISE EXCEPTION 'achievements is append-only: % is not permitted', TG_OP;
            END;
            $$;
SQL);
    }
};
