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

    public const TYPE_POLITICAL_PARTY = 'political_party';

    public const TYPE_BUSINESS = 'business';

    public const TYPE_NONPROFIT = 'nonprofit';

    public const TYPE_COMMON_GOOD_CORP = 'common_good_corp';

    public const TYPE_INFORMAL = 'informal';

    // ── Phase D (D-O1): ownership structures + ESM-18 status ───────────────
    public const STRUCTURE_STOCK = 'stock';

    public const STRUCTURE_PARTNERSHIP = 'partnership';

    public const STRUCTURE_EQUAL_PARTNERSHIP = 'equal_partnership';

    public const STRUCTURE_MEMBER_OWNED = 'member_owned';

    public const STRUCTURE_WORKER_OWNED = 'worker_owned';

    public const STRUCTURE_NONPROFIT = 'nonprofit';

    public const STRUCTURES = [
        self::STRUCTURE_STOCK, self::STRUCTURE_PARTNERSHIP, self::STRUCTURE_EQUAL_PARTNERSHIP,
        self::STRUCTURE_MEMBER_OWNED, self::STRUCTURE_WORKER_OWNED, self::STRUCTURE_NONPROFIT,
    ];

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_TRANSFER_PENDING = 'transfer_pending';

    public const STATUS_TRANSFERRED = 'transferred';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_DISSOLVED = 'dissolved';

    /** Engine map: which membership class an org's structure accepts. */
    public const STRUCTURE_MEMBERSHIP_KIND = [
        self::STRUCTURE_STOCK => OrgMembership::KIND_SHAREHOLDER,
        self::STRUCTURE_PARTNERSHIP => OrgMembership::KIND_PARTNER,
        self::STRUCTURE_EQUAL_PARTNERSHIP => OrgMembership::KIND_PARTNER,
        self::STRUCTURE_MEMBER_OWNED => OrgMembership::KIND_MEMBER,
        self::STRUCTURE_WORKER_OWNED => OrgMembership::KIND_MEMBER,
        self::STRUCTURE_NONPROFIT => OrgMembership::KIND_MEMBER,
    ];

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
        'worker_count',
        'ip_is_public_domain',
        'is_active',
        'is_registered',
        'registered_at',
        'dissolved_at',
        'dissolution_reason',
        'agent_user_id',
        // Phase D (D-O1)
        'structure',
        'status',
        'registered_by_user_id',
        'registered_via_form',
        'purpose',
        'created_by_law_id',
        'board_id',
        'registration_record_id',
    ];

    protected $casts = [
        'is_cgc' => 'boolean',
        'worker_count' => 'integer',
        'ip_is_public_domain' => 'boolean',
        'is_active' => 'boolean',
        'is_registered' => 'boolean',
        'registered_at' => 'datetime',
        'dissolved_at' => 'datetime',
    ];

    /**
     * CONSTITUTIONAL PIN — Art. III §5 (hard constraint): a CGC's IP flag
     * can NEVER flip false while the row is a CGC. The only write that may
     * carry ip_is_public_domain=false also flips is_cgc=false in the same
     * save (the F-LEG-027 sell branch — new works follow private rules;
     * the register's dedications stand regardless). Pinned by
     * CgcIpPublicDomainTest.
     */
    protected static function booted(): void
    {
        static::saving(function (self $org) {
            if ($org->is_cgc && $org->ip_is_public_domain === false) {
                throw new \RuntimeException(
                    'A Common Good Corporation\'s intellectual property is ALWAYS public domain — '
                    .'ip_is_public_domain cannot flip false on a CGC (Art. III §5).'
                );
            }
        });
    }

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

    // ── Phase D relations ───────────────────────────────────────────────────

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrgMembership::class, 'organization_id');
    }

    public function workers(): HasMany
    {
        return $this->hasMany(OrgWorker::class, 'employer_id', 'id')
            ->where('employer_type', OrgWorker::EMPLOYER_ORGANIZATIONS);
    }

    public function ownershipStakes(): HasMany
    {
        return $this->hasMany(OrgOwnershipStake::class, 'organization_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(OrgContract::class, 'organization_id');
    }

    public function documentPackages(): HasMany
    {
        return $this->hasMany(OrgDocumentPackage::class, 'organization_id');
    }

    /**
     * Append-only public-domain IP dedications (Art. III §5). READ path for
     * display surfaces — the register is written ONLY through
     * CgcIpRegisterService::dedicate(); reads ride this relation so no
     * surface touches the register model statically.
     */
    public function ipRegisterEntries(): HasMany
    {
        return $this->hasMany(CgcIpRegisterEntry::class, 'organization_id')->orderByDesc('seq');
    }

    /** The membership class this org's structure accepts (engine map). */
    public function membershipKind(): ?string
    {
        return $this->structure !== null
            ? (self::STRUCTURE_MEMBERSHIP_KIND[$this->structure] ?? null)
            : null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
