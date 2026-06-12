<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Universal organization entity (no faction layer): political parties,
 * businesses, nonprofits, CGCs, informal groups — all one table
 * discriminated by `type`.
 *
 * Phase B adds `agent_user_id` — the minimal R-23 substrate gating
 * F-ORG-002 endorsement grants (the full org module is Phase D).
 * CGC IP is permanently public domain (Art. III §5 — hard constraint).
 */
class Organization extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPE_POLITICAL_PARTY  = 'political_party';
    public const TYPE_BUSINESS         = 'business';
    public const TYPE_NONPROFIT        = 'nonprofit';
    public const TYPE_COMMON_GOOD_CORP = 'common_good_corp';
    public const TYPE_INFORMAL         = 'informal';

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'type',
        'name',
        'slug',
        'abbreviation',
        'color',
        'description',
        'website_url',
        'parent_organization_id',
        'is_cgc',
        'created_by_legislature_id',
        'overseen_by_executive_id',
        'ownership_type',
        'employee_count',
        'ip_is_public_domain',
        'is_active',
        'is_registered',
        'registered_at',
        'dissolved_at',
        'dissolution_reason',
        'agent_user_id',
    ];

    protected $casts = [
        'is_cgc'              => 'boolean',
        'employee_count'      => 'integer',
        'ip_is_public_domain' => 'boolean',
        'is_active'           => 'boolean',
        'is_registered'       => 'boolean',
        'registered_at'       => 'datetime',
        'dissolved_at'        => 'datetime',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_organization_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_organization_id');
    }

    /** R-23: the user empowered to act for this org (F-ORG-002 gate). */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function endorsementRequests(): HasMany
    {
        return $this->hasMany(EndorsementRequest::class, 'organization_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
