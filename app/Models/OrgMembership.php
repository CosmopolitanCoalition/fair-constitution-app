<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * R-24 substrate (F-IND-013, WF-ORG-03): the individual applies, the org
 * accepts per bylaws. `kind` is the ownership class; which kinds an org
 * accepts derives from its `structure` (Organization::membershipKind()).
 */
class OrgMembership extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_MEMBER      = 'member';
    public const KIND_SHAREHOLDER = 'shareholder';
    public const KIND_PARTNER     = 'partner';

    public const STATUS_APPLIED  = 'applied';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_ENDED    = 'ended';
    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'id',
        'organization_id',
        'user_id',
        'kind',
        'status',
        'applied_at',
        'accepted_at',
        'ended_at',
        'accepted_by_user_id',
        'end_reason',
    ];

    protected $casts = [
        'applied_at'  => 'datetime',
        'accepted_at' => 'datetime',
        'ended_at'    => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
