<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lane 3 — the live-row uniqueness the institution stubs never had.
 *
 * `InstitutionStubService` and `ActivationService` decide "does one already
 * exist?" by reading and then inserting. That is safe single-threaded and
 * unsafe the moment two provisioning workers claim neighbouring batches, and
 * the database backstop that should catch it was missing on three tables and
 * defective on a fourth:
 *
 *   judiciaries             — `judiciaries_pkey` only. No uniqueness at all.
 *   executives              — `UNIQUE (jurisdiction_id, deleted_at)`, which is
 *                             NULLS DISTINCT by default, so two LIVE rows
 *                             (deleted_at IS NULL) never conflict. It guards
 *                             the soft-delete history, not the live invariant.
 *   elections               — `elections_pkey` only.
 *   election_board_members  — the seated-member index carries
 *                             `user_id IS NOT NULL`, so the bootstrap board's
 *                             SYSTEM member (user_id NULL) is unconstrained.
 *
 * The audit chain's advisory lock does NOT cover this: it serializes chain
 * appends, not the reads that precede them. Both workers can read "no
 * judiciary here", queue for the lock, and both insert. Constraints are what
 * make provisioning re-entrant.
 *
 * Shape follows the patterns already proven in this schema —
 * `social_spaces_jur_type_unique` (partial WHERE deleted_at IS NULL) and
 * `election_boards_one_active`. Additive only; no existing index is dropped
 * (the executives one still guards history and is harmless).
 *
 * NOT added: an at-large race index. `election_races_one_at_large_per_kind`
 * already exists and is byte-identical in effect.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One live judiciary per jurisdiction (Art. IV §1 — a jurisdiction has
        // its court, not two).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS judiciaries_jurisdiction_live_uq
                ON judiciaries (jurisdiction_id)
             WHERE deleted_at IS NULL
        SQL);

        // One live executive per jurisdiction (Art. III).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS executives_jurisdiction_live_uq
                ON executives (jurisdiction_id)
             WHERE deleted_at IS NULL
        SQL);

        // One general election per jurisdiction that is not yet finished.
        // The predicate is deliberately NOT `status IN ('scheduled','approval_open')`:
        // the status CHECK also admits finalist_cutoff, ranked_open, voting_closed,
        // tabulating and audit_rerun, so the narrow form would permit a SECOND
        // live general election to be minted during an open ballot.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS elections_open_general_uq
                ON elections (jurisdiction_id)
             WHERE kind = 'general'
               AND deleted_at IS NULL
               AND status NOT IN ('certified', 'final', 'cancelled')
        SQL);

        // One SYSTEM member per bootstrap board (user_id NULL = the system
        // itself, B-2 schema). The existing seated-member index excludes these
        // rows via `user_id IS NOT NULL`.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS election_board_members_system_uq
                ON election_board_members (election_board_id)
             WHERE user_id IS NULL
               AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS election_board_members_system_uq');
        DB::statement('DROP INDEX IF EXISTS elections_open_general_uq');
        DB::statement('DROP INDEX IF EXISTS executives_jurisdiction_live_uq');
        DB::statement('DROP INDEX IF EXISTS judiciaries_jurisdiction_live_uq');
    }
};
