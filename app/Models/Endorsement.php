<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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
            self::ENDORSER_ORGANIZATION, 'organizations' => $this->endorserOrganization,
            self::ENDORSER_USER, 'users' => $this->endorserUser,
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

    /**
     * One logical endorsement across the application's and older simulator's
     * spellings. A canonical row supersedes its legacy counterpart even when
     * private or withdrawn: filtering visibility FIRST would resurrect it.
     */
    public function scopeLogicalType($query, string $type)
    {
        $type = self::canonicalType($type);

        return $query->whereRaw("CASE endorsements.endorser_type WHEN 'users' THEN 'user' WHEN 'organizations' THEN 'organization' ELSE endorsements.endorser_type END = ?", [$type])
            ->where(function ($q) use ($type) {
                $q->where('endorsements.endorser_type', $type)
                    ->orWhereNotExists(fn ($shadow) => $shadow->selectRaw('1')->from('endorsements as canonical')
                        ->whereColumn('canonical.election_id', 'endorsements.election_id')
                        ->whereColumn('canonical.candidate_id', 'endorsements.candidate_id')
                        ->whereColumn('canonical.endorser_id', 'endorsements.endorser_id')
                        ->where('canonical.endorser_type', $type));
            });
    }

    public static function canonicalType(string $type): string
    {
        return match ($type) {
            'user', 'users' => self::ENDORSER_USER,
            'organization', 'organizations' => self::ENDORSER_ORGANIZATION,
            default => throw new \InvalidArgumentException('Unknown endorsement type.'),
        };
    }

    /** Called only after the form's authority checks; never grants endorsement rights. */
    public static function recordFor(Candidacy $candidacy, string $type, string $endorserId, array $values): self
    {
        $type = self::canonicalType($type);

        return DB::transaction(function () use ($candidacy, $type, $endorserId, $values) {
            // An existing parent serializes the missing-row case as well as updates.
            // Both spellings use the same lock, including future individual controls.
            $parent = $type === self::ENDORSER_USER ? User::query() : Organization::query();
            $parent->whereKey($endorserId)->lockForUpdate()->firstOrFail();
            $endorsement = self::query()->logicalType($type)
                ->where('election_id', $candidacy->election_id)->where('candidate_id', $candidacy->id)
                ->where('endorser_id', $endorserId)->lockForUpdate()->first() ?? new self();
            $endorsement->fill(array_intersect_key($values, array_flip([
                'statement', 'endorsed_at', 'withdrawn_at', 'is_active', 'is_public',
            ])));
            $endorsement->fill(['election_id' => $candidacy->election_id, 'candidate_id' => $candidacy->id,
                'endorser_type' => $type, 'endorser_id' => $endorserId])->save();

            return $endorsement;
        });
    }
}
