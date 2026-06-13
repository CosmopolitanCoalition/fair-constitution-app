<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * F-JDG-007 — an emergency-powers judicial review (Art. II §7 "Emergency Powers
 * are subject to Judicial review", PHASE_E_DESIGN_challenge_law §C.1). Records
 * the review ACT and its disposition; the emergency_powers row holds only the
 * current state (under_review | struck | narrowed | active).
 *
 * The civic-process-disruption basis is the judicial counterpart to the
 * engine's pre-issuance EMERGENCY_PROTECTED_FORMS floor: the engine blocks the
 * typed attempt, F-JDG-007 strikes the semantic evasion the engine cannot catch.
 */
class EmergencyPowerReview extends Model
{
    use HasUuids, SoftDeletes;

    public const BASIS_DURATION = 'duration';

    public const BASIS_AREA = 'area';

    public const BASIS_METHODS = 'methods';

    public const BASIS_CIVIC_PROCESS_DISRUPTION = 'civic_process_disruption';

    public const BASIS_CAUSE = 'cause';

    public const OUTCOME_UPHELD = 'upheld';

    public const OUTCOME_NARROWED = 'narrowed';

    public const OUTCOME_STRUCK = 'struck';

    protected $fillable = [
        'id',
        'emergency_power_id',
        'judiciary_id',
        'case_id',
        'challenge_id',
        'review_basis',
        'outcome',
        'narrowed_area_jurisdiction_id',
        'narrowed_methods',
        'opinion_text',
        'record_id',
        'issued_at',
    ];

    protected $casts = [
        'narrowed_methods' => 'array',
        'issued_at' => 'datetime',
    ];

    public function emergencyPower(): BelongsTo
    {
        return $this->belongsTo(EmergencyPower::class, 'emergency_power_id');
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(ConstitutionalChallenge::class, 'challenge_id');
    }
}
