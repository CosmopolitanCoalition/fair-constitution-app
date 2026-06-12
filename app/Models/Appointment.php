<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Generic civil-appointment pipeline (B-2): nominated → consented |
 * rejected → seated → ended. R-08 (election board members) in Phase B;
 * R-18/29/30 consumers arrive later.
 *
 * `appointable_type`/`appointable_id` is the polymorphic target (string
 * enum, app-layer validated). `consent_vote_id` has no FK — chamber_votes
 * is Phase C; NULL covers bootstrap.
 */
class Appointment extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_NOMINATED = 'nominated';
    public const STATUS_CONSENTED = 'consented';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_SEATED    = 'seated';
    public const STATUS_ENDED     = 'ended';

    protected $fillable = [
        'id',
        'appointable_type',
        'appointable_id',
        'nominee_user_id',
        'nominated_by',
        'nominated_via_form',
        'consent_vote_id',
        'status',
        'term_id',
    ];

    public function nominee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nominee_user_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'term_id');
    }
}
