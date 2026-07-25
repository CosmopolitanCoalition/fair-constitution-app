<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index the SELF-REFERENCING foreign keys on tables that reach planet scale.
 *
 * PostgreSQL does not index foreign keys automatically. For an ordinary FK
 * that is usually a mild query cost; for a SELF-referencing FK on a large
 * table it is quadratic, because every DELETE must prove no sibling row
 * still points at the row being removed — with no index that is one full
 * table scan PER DELETED ROW.
 *
 * Measured, not theorised: deleting 50,000 rows from `executives` (a table
 * that holds one row per jurisdiction, so ~955,130 at planet scale) ran
 * CPU-bound for over seven minutes without removing a single row, because
 * `executives.parent_executive_id` had no index — 50,000 × 50,000 comparisons.
 * The same shape applies to a real revert, a jurisdiction merge, or any
 * dissolution path.
 *
 * A schema-wide audit found 212 single-column FKs with no plain index. This
 * migration deliberately fixes only the SIX self-referencing ones, and only on
 * tables that carry roughly one row per jurisdiction or more — indexing all
 * 212 would cost write throughput and disk for no measured benefit. The
 * remaining 206 are recorded in docs/plans/scaling/INSTITUTION_SCALING_PLAN.md
 * for a lane that can measure them.
 *
 * `organizations.parent_organization_id` is included because lane 14's
 * Coalition work uses exactly that column to express the
 * Foundation → Coalition parent link, and it is the walk a future org tree
 * traverses.
 */
return new class extends Migration
{
    /** table => self-referencing FK column */
    private const TARGETS = [
        'executives'    => 'parent_executive_id',
        'judiciaries'   => 'parent_judiciary_id',
        'elections'     => 'prior_election_id',
        'organizations' => 'parent_organization_id',
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => $column) {
            $name = "{$table}_{$column}_index";
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$column})");
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as $table => $column) {
            DB::statement("DROP INDEX IF EXISTS {$table}_{$column}_index");
        }
    }
};
