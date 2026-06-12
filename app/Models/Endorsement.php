<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Polymorphic endorsement (B-5 evolutions on the 2026_01 skeleton): ANY
 * organization or ANY individual user can endorse a candidacy.
 * `endorser_type` is the string enum 'organization' | 'user' (app-layer
 * validated, not a class-name morph).
 *
 * `is_public`: individual endorsers disclose by choice (my-record
 * contract); org endorsements are forced true by the F-ORG-002 handler.
 */
class Endorsement extends Model
{
    use HasUuids;

    public const ENDORSER_ORGANIZATION = 'organization';
    public const ENDORSER_USER         = 'user';

    protected $fillable = [
        'id',
        'election_id',
        'candidate_id',
        'endorser_type',
        'endorser_id',
        'statement',
        'endorsed_at',
        'withdrawn_at',
        'is_active',
        'is_public',
    ];

    protected $casts = [
        'endorsed_at'  => 'datetime',
        'withdrawn_at' => 'datetime',
        'is_active'    => 'boolean',
        'is_public'    => 'boolean',
    ];

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_id');
    }

    public function candidacy(): BelongsTo
    {
        return $this->belongsTo(Candidacy::class, 'candidate_id');
    }

    public function endorserOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'endorser_id');
    }

    public function endorserUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'endorser_id');
    }

    /** Resolve the endorser via the string-enum type column. */
    public function endorser(): Organization|User|null
    {
        return match ($this->endorser_type) {
            self::ENDORSER_ORGANIZATION => $this->endorserOrganization,
            self::ENDORSER_USER         => $this->endorserUser,
            default                     => null,
        };
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->whereNull('withdrawn_at');
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }
}
