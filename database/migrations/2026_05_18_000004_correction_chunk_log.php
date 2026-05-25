<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase T.8 — Per-chunk resume log for within-iso correction.
 *
 * Why this exists.
 *   The chunked correction (Steps 0-3 in pixel_attribution_correction)
 *   commits per pair and per tile. But per-(iso, level) checkpoint in
 *   progress.json only marks "done" at the END of a level. If the
 *   operator halts mid-level (after some pairs/tiles committed but
 *   before the level finishes), the resume path re-enters the level
 *   from scratch:
 *     - Step 0 reset to baseline UNDOES the committed pair/tile work.
 *     - Step 1/3 re-process every pair and tile from the beginning.
 *
 *   Wasted minutes-to-hours per halt on continent-scale ISOs like CAN
 *   L=2 (~5 min per pair × 14 pairs).
 *
 *   This table tracks per-pair and per-tile completion atomically with
 *   the corresponding UPDATE on jurisdictions. Each row records:
 *     - iso + adm_level: which level the chunk belongs to
 *     - chunk_type: 'pair' or 'tile'
 *     - chunk_key: 'id1:id2' for pairs (lexicographic), 'tx:ty' for tiles
 *
 *   Atomicity: Python inserts into this table in the SAME transaction
 *   as the chunked apply call. They commit together or roll back
 *   together. On halt mid-flow, either both succeeded (chunk safely
 *   recorded as done) or both rolled back (chunk will be retried).
 *
 *   Resume behavior:
 *     - On entry to _run_chunked_correction_for_level for (iso, level):
 *       read the set of done pair/tile keys.
 *     - Step 0 reset-to-baseline is SKIPPED if any chunks are recorded
 *       — resetting would undo committed corrections.
 *     - Per-pair / per-tile loops skip any key already in the done set.
 *
 *   Wiping:
 *     - Per-iso fresh: DELETE WHERE iso = $iso (Python or CLI helper).
 *     - Global fresh: TRUNCATE.
 *
 * Indexes.
 *   Primary key on (iso, adm_level, chunk_type, chunk_key) for O(1)
 *   already-done lookup. Index on (iso, adm_level, chunk_type) for
 *   the per-level done-set fetch.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE correction_chunk_log (
                iso         VARCHAR(3)  NOT NULL,
                adm_level   INT         NOT NULL,
                chunk_type  VARCHAR(8)  NOT NULL CHECK (chunk_type IN ('pair', 'tile')),
                chunk_key   TEXT        NOT NULL,
                summary     JSONB       NOT NULL DEFAULT '{}'::jsonb,
                applied_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (iso, adm_level, chunk_type, chunk_key)
            );
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX correction_chunk_log_level_idx
                ON correction_chunk_log (iso, adm_level, chunk_type);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS correction_chunk_log');
    }
};
