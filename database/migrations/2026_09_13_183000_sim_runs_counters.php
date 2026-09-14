<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE SIM WORLD COUNTERS (G3 — bound the SimSnapshot world poll).
 *
 * SimSnapshot::computeWorld ran eight whole-table statements on every Step 5
 * poll, one of them a `WHERE email LIKE 'sim-%@demo.invalid'` scan over users
 * that grows to millions as the sim populates. The headline figures are now
 * O(1) counters the pump maintains per committed chunk (SimWorkerJob), so the
 * poll reads them off the run row instead of scanning:
 *
 *   people_founded       new users minted this run (replaces the email LIKE)
 *   residencies_founded  active residency_confirmations built this run
 *   cohorts              jurisdiction_cohorts modelled this run
 *   chambers_governed    legislatures this run seated (a certified seat_scope)
 *
 * They are maintained deltas, reconciled at nothing — a crashed chunk can
 * under- or over-count by one chunk; the scope figures that must stay exact
 * (chambers total, jurisdictions in scope, population/electorate sums) are
 * computed once per run phase change, not from these counters.
 *
 * Additive, REAL-dated after 2026_07_25 (sim_runs) and after the enum_cursor
 * sibling. hasColumn-guarded both ways. The baseline dump is not touched: a
 * fresh install loads the dump then applies this on top.
 */
return new class extends Migration
{
    /** @var array<int,string> */
    private array $columns = [
        'people_founded',
        'residencies_founded',
        'cohorts',
        'chambers_governed',
    ];

    public function up(): void
    {
        Schema::table('sim_runs', function (Blueprint $t) {
            foreach ($this->columns as $col) {
                if (! Schema::hasColumn('sim_runs', $col)) {
                    $t->unsignedBigInteger($col)->default(0);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('sim_runs', function (Blueprint $t) {
            foreach ($this->columns as $col) {
                if (Schema::hasColumn('sim_runs', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
