<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * WI-6 — the 21 constitutional clocks (canonical scheduler spec:
 * EXPLORE_jud_org_jur_sys.md §5.1 / EXPLORE_registry.md §D — "the
 * production scheduler implements exactly these clock records").
 *
 * Idempotent: upserts on the string PK, so re-running refreshes
 * definitions without duplicating rows or touching clock_timers.
 *
 * `default_value` carries the constitutional default plus, where the value
 * is amendable, the `setting_key` naming its constitutional_settings
 * column — ClockService resolves amendable values per jurisdiction at
 * EVALUATION time (own row → ancestor walk → registry default).
 * `amendable=false` covers hardened, structural, per-case, and
 * ops-setting clocks alike; `default_value.mode` keeps the distinction.
 */
class ClockRegistrySeeder extends Seeder
{
    public function run(): void
    {
        $rows = array_map(
            fn (array $clock) => [
                'id'             => $clock['id'],
                'name'           => $clock['name'],
                'type'           => $clock['type'],
                'default_value'  => json_encode($clock['default_value']),
                'amendable'      => $clock['amendable'],
                'fires_workflow' => $clock['fires_workflow'],
                'basis'          => $clock['basis'],
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            self::registry()
        );

        DB::table('clocks')->upsert(
            $rows,
            ['id'],
            ['name', 'type', 'default_value', 'amendable', 'fires_workflow', 'basis', 'updated_at']
        );

        $this->command?->info('Clock registry seeded: ' . count($rows) . ' clocks (CLK-01…CLK-21).');
    }

    /**
     * @return list<array{id:string, name:string, type:string, default_value:array,
     *               amendable:bool, fires_workflow:?string, basis:string}>
     */
    public static function registry(): array
    {
        return [
            [
                'id'             => 'CLK-01',
                'name'           => 'General Election Interval',
                'type'           => 'recurring',
                'default_value'  => ['value' => 60, 'unit' => 'months', 'setting_key' => 'election_interval_months', 'mode' => 'amendable'],
                'amendable'      => true,
                'fires_workflow' => 'WF-ELE-01 / WF-LEG-18',
                'basis'          => 'Art. II §2',
            ],
            [
                'id'             => 'CLK-02',
                'name'           => 'Legislature Meeting Deadline',
                'type'           => 'countdown',
                'default_value'  => ['value' => 90, 'unit' => 'days_since_last_session', 'setting_key' => 'max_days_between_meetings', 'mode' => 'amendable', 'semantics' => 'rolling_deadline'],
                'amendable'      => true,
                'fires_workflow' => 'WF-SYS-02 → WF-LEG-05',
                'basis'          => 'Art. II §2',
            ],
            [
                'id'             => 'CLK-03',
                'name'           => 'Emergency Powers Maximum Duration',
                'type'           => 'countdown',
                'default_value'  => ['value' => 90, 'unit' => 'days', 'setting_key' => 'emergency_powers_max_days', 'mode' => 'amendable'],
                'amendable'      => true,
                'fires_workflow' => 'auto-expiry in WF-LEG-11',
                'basis'          => 'Art. II §7',
            ],
            [
                'id'             => 'CLK-04',
                'name'           => 'Special Election Window',
                'type'           => 'window',
                'default_value'  => ['value' => ['min_days' => 90, 'max_days' => 180], 'unit' => 'days_after_vacancy', 'setting_keys' => ['special_election_min_days', 'special_election_max_days'], 'mode' => 'amendable'],
                'amendable'      => true,
                'fires_workflow' => 'WF-ELE-04',
                'basis'          => 'Art. II §5',
            ],
            [
                'id'             => 'CLK-05',
                'name'           => 'Residency Verification Threshold',
                'type'           => 'threshold',
                'default_value'  => ['value' => 30, 'unit' => 'qualifying_ping_days', 'setting_key' => 'residency_confirmation_days', 'mode' => 'amendable'],
                'amendable'      => true,
                'fires_workflow' => 'WF-CIV-02 / WF-CIV-03',
                'basis'          => 'Art. I; Art. V §1',
            ],
            [
                'id'             => 'CLK-06',
                'name'           => 'Critical Population Threshold',
                'type'           => 'threshold',
                'default_value'  => ['value' => null, 'unit' => 'verified_residents', 'setting_key' => 'critical_population_threshold', 'config_default' => 'cga.critical_population_default', 'mode' => 'amendable', 'semantics' => 'per_jurisdiction_tier'],
                'amendable'      => true,
                'fires_workflow' => 'WF-ELE-02 / WF-JUR-01',
                'basis'          => 'Art. II §1',
            ],
            [
                'id'             => 'CLK-07',
                'name'           => 'Legislature Maximum Size',
                'type'           => 'threshold',
                'default_value'  => ['value' => 9, 'unit' => 'members', 'setting_key' => 'legislature_max_seats', 'mode' => 'amendable'],
                'amendable'      => true,
                'fires_workflow' => 'WF-ELE-06 subdivision',
                'basis'          => 'Art. II §2, §8',
            ],
            [
                'id'             => 'CLK-08',
                'name'           => 'Legislature Minimum Size',
                'type'           => 'threshold',
                'default_value'  => ['value' => 5, 'unit' => 'members', 'setting_key' => 'legislature_min_seats', 'mode' => 'amendable', 'semantics' => 'structural_floor'],
                'amendable'      => true,
                'fires_workflow' => 'seat-count validation (Constitutional Engine)',
                'basis'          => 'Art. V §3',
            ],
            [
                'id'             => 'CLK-09',
                'name'           => 'Judicial / Civil Officer Term',
                'type'           => 'countdown',
                'default_value'  => ['value' => 10, 'unit' => 'years', 'setting_keys' => ['judicial_appointment_years', 'civil_appointment_years'], 'mode' => 'amendable', 'semantics' => 'lockstep_pair'],
                'amendable'      => true,
                'fires_workflow' => 'WF-JUD-07, WF-EXE-05 renewals',
                'basis'          => 'Art. IV §4; Art. III §4',
            ],
            [
                'id'             => 'CLK-10',
                'name'           => 'Term Lockstep',
                'type'           => 'derived',
                'default_value'  => ['value' => null, 'unit' => 'derived_schedule', 'mode' => 'structural'],
                'amendable'      => false,
                'fires_workflow' => 'WF-SYS-01 → WF-ELE-01/08/09',
                'basis'          => 'Art. III §3; Art. IV §3',
            ],
            [
                'id'             => 'CLK-11',
                'name'           => 'Judicial Veto Window',
                'type'           => 'window',
                'default_value'  => ['value' => null, 'unit' => 'set_by_judiciary_per_finding', 'mode' => 'per_case', 'override_slot' => 'clock_timers.override_value'],
                'amendable'      => false,
                'fires_workflow' => 'WF-JUD-05 override deadline',
                'basis'          => 'Art. IV §5',
            ],
            [
                'id'             => 'CLK-12',
                'name'           => 'Legislative Remedy Timeframe',
                'type'           => 'window',
                'default_value'  => ['value' => null, 'unit' => 'reasonable_timeframe_set_by_judiciary', 'mode' => 'per_case', 'override_slot' => 'clock_timers.override_value'],
                'amendable'      => false,
                'fires_workflow' => 'WF-JUD-05 auto-remedy trigger',
                'basis'          => 'Art. IV §5',
            ],
            [
                'id'             => 'CLK-13',
                'name'           => 'Co-determination Minimum',
                'type'           => 'threshold',
                'default_value'  => ['value' => 100, 'unit' => 'workers', 'setting_key' => 'worker_rep_min_employees', 'mode' => 'amendable'],
                'amendable'      => true,
                'fires_workflow' => 'WF-ORG-04 first worker seat',
                'basis'          => 'Art. III §6',
            ],
            [
                'id'             => 'CLK-14',
                'name'           => 'Co-determination Parity',
                'type'           => 'threshold',
                'default_value'  => ['value' => 2000, 'unit' => 'workers', 'setting_key' => 'worker_rep_parity_employees', 'mode' => 'amendable'],
                'amendable'      => true,
                'fires_workflow' => 'WF-ORG-04 board parity',
                'basis'          => 'Art. III §6',
            ],
            [
                'id'             => 'CLK-15',
                'name'           => 'Minimum Judges per Elected Race',
                'type'           => 'threshold',
                'default_value'  => ['value' => 5, 'unit' => 'judges', 'setting_key' => 'judiciary_min_judges_per_race', 'mode' => 'amendable', 'semantics' => 'structural_floor'],
                'amendable'      => true,
                'fires_workflow' => 'WF-ELE-09 ballot construction',
                'basis'          => 'Art. IV §4',
            ],
            [
                'id'             => 'CLK-16',
                'name'           => 'Case Panel Minimum',
                'type'           => 'threshold',
                'default_value'  => ['value' => 3, 'unit' => 'judges', 'odd' => true, 'severity_scaled' => true, 'mode' => 'hardened'],
                'amendable'      => false,
                'fires_workflow' => 'WF-JUD-03 panel assignment',
                'basis'          => 'Art. IV §4',
            ],
            [
                'id'             => 'CLK-17',
                'name'           => 'Petition Signature Threshold',
                'type'           => 'threshold',
                'default_value'  => ['value' => 5.00, 'unit' => 'pct_of_population', 'setting_key' => 'initiative_petition_threshold_pct', 'mode' => 'amendable', 'semantics' => 'per_jurisdiction'],
                'amendable'      => true,
                'fires_workflow' => 'WF-CIV-06 audit trigger',
                'basis'          => 'Art. II §6',
            ],
            [
                'id'             => 'CLK-18',
                'name'           => 'Approval Phase / Registration Window',
                'type'           => 'window',
                'default_value'  => ['value' => null, 'opens' => 'prior_certification', 'closes' => 'finalist_cutoff', 'mode' => 'structural'],
                'amendable'      => false,
                'fires_workflow' => 'WF-CIV-08 open/freeze; WF-CIV-05',
                'basis'          => 'Art. II §2; CGA open-ballot spec',
            ],
            [
                'id'             => 'CLK-19',
                'name'           => 'Referendum Act Protection',
                'type'           => 'flag',
                'default_value'  => ['value' => null, 'unit' => 'term_scoped_flag', 'semantics' => 'same_term_supermajority_shield_then_ordinary_law_after_general_election', 'mode' => 'hardened'],
                'amendable'      => false,
                'fires_workflow' => 'WF-LEG-19 gate',
                'basis'          => 'Art. II §6',
            ],
            [
                'id'             => 'CLK-20',
                'name'           => 'Federation Sync Heartbeat',
                'type'           => 'recurring',
                'default_value'  => ['value' => null, 'unit' => 'implementation_setting', 'mode' => 'ops'],
                'amendable'      => false,
                'fires_workflow' => 'WF-JUR-06',
                'basis'          => 'CGA federation model',
            ],
            [
                'id'             => 'CLK-21',
                'name'           => 'Finalist Count per Race',
                'type'           => 'derived',
                'default_value'  => ['value' => null, 'unit' => 'top_x_of_seats_in_race', 'semantics' => 'multiplier_x_seats', 'mode' => 'amendable'],
                'amendable'      => true,
                'fires_workflow' => 'finalist cutoff WF-ELE-01 / WF-CIV-08',
                'basis'          => 'CGA open-ballot spec; Art. II §2',
            ],
        ];
    }
}
