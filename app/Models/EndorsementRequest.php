<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The F-CAN-002 → F-ORG-002 endorsement handshake (B-5): a candidate asks
 * an organization; the org's agent (R-23) grants or declines. A grant
 * creates the `endorsements` row (forced public) and links it here.
 */
class EndorsementRequest extends Model
{
    use HasUuids;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_GRANTED  = 'granted';
    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'id',
        'candidacy_id',
        'organization_id',
        'message',
        'status',
        'requested_at',
        'decided_at',
        'endorsement_id',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'decided_at'   => 'datetime',
    ];

    public function candidacy(): BelongsTo
    {
        return $this->belongsTo(Candidacy::class, 'candidacy_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function endorsement(): BelongsTo
    {
        return $this->belongsTo(Endorsement::class, 'endorsement_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
