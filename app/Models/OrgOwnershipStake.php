<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The share system (owner ruling #12, D-O4). Stakes define WHO stands on
 * the owner side + the economics — NEVER vote weight (org design §C.1:
 * owner-track elections are one-member-one-vote within the class).
 *
 * No soft deletes — closure via ended_at; the current cap table is
 * `ended_at IS NULL`, history preserved.
 */
class OrgOwnershipStake extends Model
{
    use HasUuids;

    public const HOLDER_USERS         = 'users';
    public const HOLDER_ORGANIZATIONS = 'organizations';
    public const HOLDER_JURISDICTIONS = 'jurisdictions';

    public const VIA_FOUNDING   = 'founding';
    public const VIA_ISSUE      = 'issue';
    public const VIA_TRANSFER   = 'transfer';
    public const VIA_CONVERSION = 'conversion';

    protected $fillable = [
        'id',
        'organization_id',
        'holder_type',
        'holder_id',
        'units',
        'pct',
        'acquired_via',
        'source_transfer_id',
        'as_of',
        'ended_at',
    ];

    protected $casts = [
        'units'    => 'decimal:6',
        'pct'      => 'decimal:4',
        'as_of'    => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('ended_at');
    }
}
