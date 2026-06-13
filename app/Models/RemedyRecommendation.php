<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * F-JDG-005 — a remedy recommendation (Art. IV §5.2 second half + §5.3/§5.4
 * window-setting, PHASE_E_DESIGN_challenge_law §A/§B.3). This is where the judge
 * SETS both windows; the engine arms CLK-11/CLK-12.
 *
 *  - remedy_timeframe_days — §5.3 "reasonable timeframe as outlined by the
 *    Judiciary" — the judge-set CLK-12 value (Path 1's window to act).
 *  - veto_window_days — §5.4 "a set Judicial veto window" — the judge-set
 *    CLK-11 value (Path 2's window to override).
 *
 * The constitution caps NEITHER (floors both at > 0); the value is a published,
 * audit-chained, reviewable judicial act.
 */
class RemedyRecommendation extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_MODIFY = 'modify';

    public const KIND_REMOVE = 'remove';

    protected $fillable = [
        'id',
        'finding_id',
        'challenge_id',
        'judiciary_id',
        'remedy_kind',
        'recommended_text',
        'rationale_text',
        'remedy_timeframe_days',
        'veto_window_days',
        'remedy_due_at',
        'veto_closes_at',
        'clk11_timer_id',
        'clk12_timer_id',
        'record_id',
        'issued_at',
    ];

    protected $casts = [
        'remedy_timeframe_days' => 'integer',
        'veto_window_days' => 'integer',
        'remedy_due_at' => 'datetime',
        'veto_closes_at' => 'datetime',
        'issued_at' => 'datetime',
    ];

    public function finding(): BelongsTo
    {
        return $this->belongsTo(ConstitutionalFinding::class, 'finding_id');
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(ConstitutionalChallenge::class, 'challenge_id');
    }
}
