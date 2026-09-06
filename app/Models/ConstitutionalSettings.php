<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConstitutionalSettings extends Model
{
    protected $table = 'constitutional_settings';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'election_interval_months',
        'voting_method',
        'special_election_min_days',
        'special_election_max_days',
        'legislature_min_seats',
        'legislature_max_seats',
        'legislature_sizing_law',
        // Mixed-autoseed default line-split method (Setup Option 2026-07-17):
        // shortest | vertical_strips | horizontal_strips | community_cells.
        'districting_autoseed_template',
        'supermajority_numerator',
        'supermajority_denominator',
        'max_days_between_meetings',
        'emergency_powers_max_days',
        'civil_appointment_years',
        'judicial_appointment_years',
        'judiciary_min_judges_per_race',
        'judiciary_is_elected',
        'worker_rep_min_employees',
        'worker_rep_parity_employees',
        'residency_confirmation_days',
        'initiative_petition_threshold_pct',
        // Economy defaults (setup-wizard v2). The currency's existence is
        // Art. V §5 root-reserved; these founding defaults are amendable.
        'currency_name',
        'currency_code',
        'currency_symbol',
        'civic_stipend_floor',
        'stipend_bump_cap',
        'pay_node_operator',
        'pay_social_moderator',
        'pay_office_holder',
        'stipend_interval',
        // Phase L (slice L-1) — the monetary levers. Nullable by design so
        // SettingsResolver can actually inherit them up the jurisdiction
        // chain; defaults live in ConstitutionalDefaults, not the DDL.
        'stipend_enabled',
        'stipend_funding_source',
        'stipend_period_days',
        'issuance_rate_bps',
        'inflation_target_bps',
        'type_b_seats_per_child',
        // Wave 6 (item 7) — the activation curve + CLK-06 threshold, amendable.
        'critical_population_threshold',
        'activation_tier_enabled',
        'activation_tier_k',
        'activation_tier_exponent',
        'activation_tier_floor',
        'activation_tier_cap',
        'last_amended_by_act_id',
        'last_amended_at',
        // Phase E (PHASE_E_DESIGN_challenge_law §E.5) — the two-door amendment
        // provenance: which route last amended a setting + the non-legislative
        // process row (NULL for the ordinary F-LEG-031 legislative path).
        'last_amendment_route',
        'last_amendment_process_id',
    ];

    protected $casts = [
        'election_interval_months' => 'integer',
        'legislature_min_seats' => 'integer',
        'legislature_max_seats' => 'integer',
        'supermajority_numerator' => 'integer',
        'supermajority_denominator' => 'integer',
        'max_days_between_meetings' => 'integer',
        'emergency_powers_max_days' => 'integer',
        'civil_appointment_years' => 'integer',
        'judicial_appointment_years' => 'integer',
        'judiciary_min_judges_per_race' => 'integer',
        'judiciary_is_elected' => 'boolean',
        'worker_rep_min_employees' => 'integer',
        'worker_rep_parity_employees' => 'integer',
        'residency_confirmation_days' => 'integer',
        'initiative_petition_threshold_pct' => 'float',
        'civic_stipend_floor' => 'integer',
        'stipend_bump_cap' => 'integer',
        'pay_node_operator' => 'integer',
        'pay_social_moderator' => 'integer',
        'pay_office_holder' => 'integer',
        'stipend_enabled' => 'boolean',
        'stipend_period_days' => 'integer',
        'issuance_rate_bps' => 'integer',
        'inflation_target_bps' => 'integer',
        'type_b_seats_per_child' => 'integer',
        'critical_population_threshold' => 'integer',
        'activation_tier_enabled' => 'integer',
        'activation_tier_k' => 'integer',
        'activation_tier_exponent' => 'integer',
        'activation_tier_floor' => 'integer',
        'activation_tier_cap' => 'integer',
        'last_amended_at' => 'datetime',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }
}
