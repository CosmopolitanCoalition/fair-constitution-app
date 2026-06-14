<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A constitutional-restoration event (Art. VI §2–3). Judicially confirmed before
 * the three-tier cascade restores order.
 */
class RestorationEvent extends Model
{
    use HasUuids, SoftDeletes;

    public const CONDITION_COUNTERMANDED = 'countermanded';

    public const CONDITION_CAPTURED = 'captured';

    public const CONDITION_DESTROYED = 'destroyed';

    public const STATUS_DECLARED = 'declared';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_RESTORING = 'restoring';

    public const STATUS_RESTORED = 'restored';

    public const STATUS_ABANDONED = 'abandoned';

    protected $fillable = [
        'id', 'jurisdiction_id', 'condition', 'evidence', 'review_case_id',
        'judicially_confirmed', 'tier', 'tier_election_id', 'status',
    ];

    protected $casts = [
        'evidence' => 'array',
        'judicially_confirmed' => 'boolean',
        'tier' => 'integer',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }
}
