<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * geodata_runs.review_pass — the REVIEW-CLEARING PASS (operator, 2026-08-03).
 *
 * Mainline work leaves review items behind: an OOM-killed pair, a country
 * whose file hiccuped. Historically those simply sat there — "review and
 * failed count as settled" — so a run could reach `done` with 48 pairs
 * unattributed and the operator had to notice and requeue by hand.
 *
 * A review pass is an automatic second bite: when a stage's mainline work
 * has no open items left but DOES have review/failed ones, the pump
 * requeues them and the supervisor drops to HALF LANES for the retry. Half,
 * because the commonest cause of an ingest/attribution review is memory
 * co-residency — retrying the same items at the same width would reproduce
 * the same kill. A thinner field is the fix that already works by hand.
 *
 * The column holds the stage whose pass is active ('ingest' | 'derive'),
 * NULL when none. It is also the marker that a stage has HAD its pass:
 * the pump records completion in phase_timestamps so a stage never loops —
 * one retry, then residual review is accepted and the run moves on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geodata_runs', function (Blueprint $table) {
            $table->string('review_pass', 16)->nullable()->after('phase');
        });
    }

    public function down(): void
    {
        Schema::table('geodata_runs', function (Blueprint $table) {
            $table->dropColumn('review_pass');
        });
    }
};
