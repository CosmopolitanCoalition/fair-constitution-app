<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Public↔private conversion (WF-ORG-07/08/09; F-ORG-006, F-LEG-026,
 * F-LEG-027). Both directions are legislature-only — `authorizing_law_id`
 * is engine-required before status may pass `voted`. The fair-market
 * floor is recorded BEFORE compensation; compensation < floor is
 * engine-blocked (hardened — Art. III §5).
 */
class OrgConversion extends Model
{
    use HasUuids, SoftDeletes;

    public const DIRECTION_PRIVATE_TO_CGC = 'private_to_cgc';
    public const DIRECTION_CGC_TO_PRIVATE = 'cgc_to_private';

    public const VIA_MUTUAL               = 'mutual';
    public const VIA_MONOPOLY_ACQUISITION = 'monopoly_acquisition';
    public const VIA_CGC_SALE             = 'cgc_sale';

    public const STATUS_PROPOSED             = 'proposed';
    public const STATUS_VOTED                = 'voted';
    public const STATUS_COMPENSATION_PENDING = 'compensation_pending';
    public const STATUS_CONVERTING           = 'converting';
    public const STATUS_COMPLETED            = 'completed';
    public const STATUS_ABANDONED            = 'abandoned';

    protected $fillable = [
        'id',
        'organization_id',
        'direction',
        'via',
        'proposal_id',
        'authorizing_vote_id',
        'authorizing_law_id',
        'fair_market_floor',
        'fair_market_basis',
        'compensation',
        'compensation_record_id',
        'board_transition',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'fair_market_floor' => 'decimal:2',
        'compensation'      => 'decimal:2',
        'board_transition'  => 'array',
        'completed_at'      => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
