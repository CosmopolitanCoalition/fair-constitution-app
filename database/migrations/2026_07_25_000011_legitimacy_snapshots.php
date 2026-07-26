<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase I — REACH snapshots. The canonical enrolment metric, built once.
 *
 *     reach = verified residents ÷ population estimate
 *
 * The roadmap is explicit that this table is built once and CONSUMED: Phase O
 * (the demo) and Phase K (the achievement gauge) read it, never recompute it.
 *
 * ⚑ REACH IS NOT THE ART. VI §3 LEGITIMACY VERDICT. That is a wartime
 * allegiance test tied to restoration (`RestorationService`, conditions
 * countermanded | captured | destroyed, judicially confirmed). This is a
 * headcount ratio and nothing more. The names in this layer ("legitimacy_*")
 * are historical and actively invite that conflation, so the disclaimer is
 * carried in the table comment, the service docblock, the surface citation and
 * the page copy — and a pin asserts no Art. VI path ever reads this table.
 *
 * ── The four honest states (mockups/v3/social/legitimacy.html) ────────────
 *   unmeasurable — no population estimate. NEVER rendered as 0%.
 *   activating   — count below the k-anonymity floor, or suppressed by the
 *                  complementary rule. No number, no curve.
 *   measured     — a real ratio.
 *   capped       — ratio > 1 (the estimate lags the place). The bar shows
 *                  100% and the raw figure is disclosed in a footnote.
 *
 * ── ratio_micro ──────────────────────────────────────────────────────────
 * Millionths, integer. A float column would drift across instances and break
 * cross-instance hash comparison; the ratio is a published fact, so it must
 * be exact and identical everywhere.
 *
 * ── CI-6: only the AUTHORITATIVE instance writes a snapshot ───────────────
 * Authority is per-jurisdiction (`jurisdictions.authoritative_server_id IS
 * NULL`), NOT cluster leadership. A mirror runs its own scheduler and wins its
 * own leader probe, so the scheduler-leader gate alone would let every mirror
 * write a snapshot for a jurisdiction it does not own. The writer therefore
 * carries BOTH gates, and they are orthogonal:
 *   onOneServer + LeaderProbe → "run once per cluster, on a writable node";
 *   authoritative_server_id IS NULL → "for the jurisdictions we actually own".
 *
 * `source_server_id` records WHICH instance wrote the row (NULL = here, the
 * same convention as `jurisdictions.authoritative_server_id`). Snapshots DO
 * federate — reach is a public fact about a PLACE, not about a person, and a
 * mirror must be able to display it without recomputing from residency data it
 * may not hold. Attribution is what makes CI-6 auditable after the fact: a row
 * whose source disagrees with the jurisdiction's authority is a violation, and
 * the unique key below makes the collision impossible to hide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legitimacy_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('jurisdiction_id')->constrained('jurisdictions')->cascadeOnDelete();
            $table->date('as_of_date');

            // NULL whenever suppressed — a sub-k count must never reach storage.
            $table->integer('verified_residents')->nullable();
            $table->bigInteger('population_estimate')->nullable();
            $table->integer('ratio_micro')->nullable();

            $table->string('state', 16);
            $table->boolean('suppressed')->default(false);

            $table->string('population_provenance', 32)->nullable();
            $table->smallInteger('population_year')->nullable();

            $table->uuid('source_server_id')->nullable();
            $table->timestampTz('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));

            // One snapshot per place per day, globally. Only the authority
            // writes, so a collision IS a CI-6 violation rather than a race.
            $table->unique(['jurisdiction_id', 'as_of_date'], 'legitimacy_snapshots_jur_date_uq');
            $table->index('as_of_date');
            $table->index(['jurisdiction_id', 'as_of_date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE legitimacy_snapshots
              ADD CONSTRAINT legitimacy_snapshots_state_check
              CHECK (state IN ('unmeasurable', 'activating', 'measured', 'capped'))
        SQL);

        // A suppressed row must carry NO count. This is the k-anonymity rail
        // expressed as a database invariant, not merely as service logic —
        // suppression that depends on every caller remembering is not suppression.
        DB::statement(<<<'SQL'
            ALTER TABLE legitimacy_snapshots
              ADD CONSTRAINT legitimacy_snapshots_suppressed_has_no_count_check
              CHECK (NOT suppressed OR (verified_residents IS NULL AND ratio_micro IS NULL))
        SQL);

        DB::statement("COMMENT ON TABLE legitimacy_snapshots IS "
            ."'Phase I REACH: verified residents / population estimate, per place per day. "
            ."A GAUGE, NEVER A LEVER - changes no vote, no seat, no right (CI-1). NOT the "
            ."Art. VI §3 legitimacy verdict (that is the wartime allegiance/restoration test - "
            ."see RestorationService). Written only by the AUTHORITATIVE instance for each "
            ."jurisdiction (CI-6, authoritative_server_id IS NULL - authority, not leadership).'");

        DB::statement("COMMENT ON COLUMN legitimacy_snapshots.ratio_micro IS "
            ."'Reach in millionths (integer, never float - the ratio is a published fact and must "
            ."be byte-identical across instances). NULL when unmeasurable or suppressed.'");

        DB::statement("COMMENT ON COLUMN legitimacy_snapshots.source_server_id IS "
            ."'Instance that wrote this row. NULL = written here (same convention as "
            ."jurisdictions.authoritative_server_id). Snapshots federate; attribution is what "
            ."makes CI-6 auditable.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('legitimacy_snapshots');
    }
};
