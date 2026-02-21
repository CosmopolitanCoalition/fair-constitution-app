<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('constitutional_settings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Scoped per jurisdiction — every jurisdiction has its own settings
            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->cascadeOnDelete();

            // ── ELECTION SETTINGS ────────────────────────────────────────────────

            // Default: 5 years. Stored in months for flexibility.
            // Article II Sec 2: "elections occur every five (5) years"
            $table->unsignedSmallInteger('election_interval_months')->default(60);

            // Article II Sec 2: voting method
            // 'stv_droop' is the only constitutionally valid default
            // Can only be replaced with a MORE proportional method — never less
            $table->string('voting_method')->default('stv_droop')
                ->comment('stv_droop is constitutionally required default. Never replace with FPTP or plurality.');

            // Special election window after vacancy (Article II Sec 5)
            $table->unsignedSmallInteger('special_election_min_days')->default(90);
            $table->unsignedSmallInteger('special_election_max_days')->default(180);

            // ── LEGISLATURE SIZE SETTINGS ────────────────────────────────────────

            // Article II Sec 2: min 5, max 9 before mandatory split
            // THESE ARE CONSTITUTIONAL FLOORS/CEILINGS — amendments cannot go below 5 or above 9
            $table->unsignedTinyInteger('legislature_min_seats')->default(5);
            $table->unsignedTinyInteger('legislature_max_seats')->default(9)
                ->comment('Max before mandatory subdivision. Constitution caps this at 9.');

            // ── SUPERMAJORITY SETTINGS ───────────────────────────────────────────

            // Article VII: supermajority cannot be less than majority+1
            // Stored as a fraction numerator/denominator for precision
            // Default: 2/3
            $table->unsignedTinyInteger('supermajority_numerator')->default(2);
            $table->unsignedTinyInteger('supermajority_denominator')->default(3);

            // ── MEETING REQUIREMENTS ─────────────────────────────────────────────

            // Article II Sec 2: "at least once every Ninety (90) days"
            $table->unsignedSmallInteger('max_days_between_meetings')->default(90);

            // ── EMERGENCY POWERS ─────────────────────────────────────────────────

            // Article II Sec 7: max duration 90 days
            $table->unsignedSmallInteger('emergency_powers_max_days')->default(90);

            // ── TERM LENGTHS ─────────────────────────────────────────────────────

            // Article II Sec 9: civil appointments 10 years
            // Article IV Sec 1: judicial appointments 10 years
            // All kept in lockstep per constitution
            $table->unsignedSmallInteger('civil_appointment_years')->default(10);
            $table->unsignedSmallInteger('judicial_appointment_years')->default(10);

            // ── JUDICIARY SETTINGS ───────────────────────────────────────────────

            // Article IV Sec 1: min 5 judges in a race
            $table->unsignedTinyInteger('judiciary_min_judges_per_race')->default(5);

            // Article IV: default is appointed, not elected
            $table->boolean('judiciary_is_elected')->default(false);

            // ── WORK COUNCIL / CO-DETERMINATION ─────────────────────────────────

            // Article III Sec 6
            $table->unsignedSmallInteger('worker_rep_min_employees')->default(100)
                ->comment('Minimum employees before worker-elected rep required');
            $table->unsignedSmallInteger('worker_rep_parity_employees')->default(2000)
                ->comment('Employee count at which worker:shareholder seats are equal');

            // ── RESIDENCY VERIFICATION ───────────────────────────────────────────

            // How many days of GPS pings required to confirm residency
            $table->unsignedSmallInteger('residency_confirmation_days')->default(30);

            // ── REFERENDUM THRESHOLDS ────────────────────────────────────────────

            // Article II Sec 6: petition threshold as percentage of population
            $table->decimal('initiative_petition_threshold_pct', 5, 2)->default(5.00)
                ->comment('Percentage of jurisdiction population required for citizen initiative');

            // ── AUDIT TRAIL ──────────────────────────────────────────────────────

            // Track when settings were last amended and by which legislature act
            $table->uuid('last_amended_by_act_id')->nullable()
                ->comment('FK to legislative_acts table (created later)');
            $table->timestamp('last_amended_at')->nullable();

            $table->timestamps();

            // Only one settings record per jurisdiction
            $table->unique('jurisdiction_id');

            $table->index('jurisdiction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('constitutional_settings');
    }
};
