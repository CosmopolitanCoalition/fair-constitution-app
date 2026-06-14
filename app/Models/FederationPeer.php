<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A peer instance in the federation mesh (Phase F, WF-JUR-06). Status walks
 * ESM-20: discovered → handshake → trust_established → syncing/…/departed.
 */
class FederationPeer extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_DISCOVERED = 'discovered';

    public const STATUS_HANDSHAKE = 'handshake';

    public const STATUS_TRUST_ESTABLISHED = 'trust_established';

    public const STATUS_SYNCING = 'syncing';

    public const STATUS_CONFLICT_RESOLUTION = 'conflict_resolution';

    public const STATUS_BORDER_SETTLED = 'border_settled';

    public const STATUS_MERGED = 'merged';

    public const STATUS_DEPARTED = 'departed';

    public const STATUSES = [
        self::STATUS_DISCOVERED, self::STATUS_HANDSHAKE, self::STATUS_TRUST_ESTABLISHED,
        self::STATUS_SYNCING, self::STATUS_CONFLICT_RESOLUTION, self::STATUS_BORDER_SETTLED,
        self::STATUS_MERGED, self::STATUS_DEPARTED,
    ];

    /** Phase G: the peer's role to us (orthogonal to ESM-20 status). */
    public const RELATION_SOVEREIGN = 'sovereign';

    public const RELATION_HOST = 'host';

    public const RELATION_MIRROR = 'mirror';

    public const RELATIONS = [self::RELATION_SOVEREIGN, self::RELATION_HOST, self::RELATION_MIRROR];

    protected $fillable = [
        'id',
        'server_id',
        'name',
        'url',
        'public_key',
        'status',
        'relation',
        'metadata',
        'last_heartbeat_at',
        'trust_established_at',
        'last_synced_seq',
        'peer_head_seq',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_heartbeat_at' => 'datetime',
        'trust_established_at' => 'datetime',
        'last_synced_seq' => 'integer',
        'peer_head_seq' => 'integer',
    ];

    public function authorityClaims(): HasMany
    {
        return $this->hasMany(AuthorityClaim::class, 'claimed_by_peer_id');
    }

    public function partitionExports(): HasMany
    {
        return $this->hasMany(PartitionExport::class, 'peer_id');
    }

    public function clusterMemberships(): HasMany
    {
        return $this->hasMany(ClusterMembership::class, 'peer_id');
    }

    public function isSovereign(): bool
    {
        return ($this->relation ?? self::RELATION_SOVEREIGN) === self::RELATION_SOVEREIGN;
    }

    public function isTrusted(): bool
    {
        return in_array($this->status, [
            self::STATUS_TRUST_ESTABLISHED, self::STATUS_SYNCING,
            self::STATUS_CONFLICT_RESOLUTION, self::STATUS_BORDER_SETTLED,
        ], true);
    }
}
