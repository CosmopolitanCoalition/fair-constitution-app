<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * W-0299 part 1 — allow the budget_enactment chamber-vote proposal kind.
 *
 * The budget act rides the existing chamber-vote proposal rail. A budget is
 * drafted by a member, then moved to enactment through a floor vote of kind
 * budget_enactment; on adoption the lines become appropriations under the
 * enacting act. The proposal_kind CHECK enumerates the lawful kinds, so this
 * extends it to admit budget_enactment.
 *
 * Postgres only, following the judicial-nomination precedent: preserve the
 * installed expression, re-add it OR'd with the new kind, NOT VALID (existing
 * rows already passed). Sqlite has no such CHECK, so this is a no-op there.
 * Rerunnable: the extension is skipped when the kind is already admitted.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const CONSTRAINT = 'chamber_vote_proposals_kind_check';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET lock_timeout = '5s'");

        try {
            $old = DB::selectOne(
                "SELECT pg_get_expr(conbin, conrelid) AS expression
                   FROM pg_constraint
                  WHERE conrelid = 'chamber_vote_proposals'::regclass
                    AND conname = ?",
                [self::CONSTRAINT]
            );

            if ($old !== null && ! str_contains($old->expression, 'budget_enactment')) {
                DB::statement('ALTER TABLE chamber_vote_proposals DROP CONSTRAINT '.self::CONSTRAINT);
                DB::statement(
                    'ALTER TABLE chamber_vote_proposals ADD CONSTRAINT '.self::CONSTRAINT
                    ." CHECK (({$old->expression}) OR proposal_kind IN ('budget_enactment')) NOT VALID"
                );
            }
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    public function down(): void
    {
        // Recorded budget acts survive a code rollback; the constraint stays widened.
    }
};
