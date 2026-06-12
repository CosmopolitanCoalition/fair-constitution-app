<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ESM-06 Candidacy (B-5).
 *
 *   registered → validated → in_pool → finalist | non_finalist →
 *   elected | defeated;   rejected / withdrawn are terminal public record.
 *
 * Art. I: the ONLY permissible rejection ground is
 * 'no_residency_association' — enforced by the database CHECK, not just
 * the engine. `non_finalist` candidacies remain WRITE-IN ELIGIBLE (right
 * to stand preserved); withdrawal is engine-blocked after
 * `finalist_cutoff_at` (ballot lock).
 *
 * Deliberately NO `approvals` relation here: individual approvals are
 * constitutionally secret (owner-scoped on the Approval model); aggregates
 * only ever leave through `approval_standings`.
 */
class Candidacy extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_REGISTERED   = 'registered';
    public const STATUS_VALIDATED    = 'validated';
    public const STATUS_REJECTED     = 'rejected';
    public const STATUS_IN_POOL      = 'in_pool';
    public const STATUS_FINALIST     = 'finalist';
    public const STATUS_NON_FINALIST = 'non_finalist';
    public const STATUS_WITHDRAWN    = 'withdrawn';
    public const STATUS_ELECTED      = 'elected';
    public const STATUS_DEFEATED     = 'defeated';

    /** The only ground the database will store (Art. I). */
    public const REJECTION_NO_RESIDENCY = 'no_residency_association';

    protected $fillable = [
        'id',
        'election_id',
        'race_id',
        'user_id',
        'status',
        'platform_statement',
        'position_tags',
        'residency_attested_at',
        'validated_at',
        'validated_by_member_id',
        'rejection_reason',
        'withdrawn_at',
    ];

    protected $casts = [
        'position_tags'         => 'array',
        'residency_attested_at' => 'datetime',
        'validated_at'          => 'datetime',
        'withdrawn_at'          => 'datetime',
    ];

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_id');
    }

    public function race(): BelongsTo
    {
        return $this->belongsTo(ElectionRace::class, 'race_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(ElectionBoardMember::class, 'validated_by_member_id');
    }

    public function endorsements(): HasMany
    {
        return $this->hasMany(Endorsement::class, 'candidate_id');
    }

    public function endorsementRequests(): HasMany
    {
        return $this->hasMany(EndorsementRequest::class, 'candidacy_id');
    }

    public function standings(): HasMany
    {
        return $this->hasMany(ApprovalStanding::class, 'candidacy_id');
    }

    /** R-06 source statuses (registered..finalist, per RoleService WI-B4). */
    public function scopeStanding($query)
    {
        return $query->whereIn('status', [
            self::STATUS_REGISTERED,
            self::STATUS_VALIDATED,
            self::STATUS_IN_POOL,
            self::STATUS_FINALIST,
        ]);
    }
}
