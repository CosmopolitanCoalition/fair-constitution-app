<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

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
        // Phase G (G-VER) — the peer's tracked versions (promoted out of metadata).
        'constitutional_version',
        'app_release',
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

    /**
     * The peer's Matrix homeserver server_name (K3-C / Phase 5) — for the Matrix federation whitelist. The
     * value the peer advertised at handshake (stored in metadata) is authoritative; absent that (a pre-K3-C
     * peer, or a non-DNS transport with no declared domain), fall back to the host of its federation url.
     */
    public function matrixServerName(): ?string
    {
        $declared = $this->metadata['matrix_server_name'] ?? null;
        if (! empty($declared)) {
            return strtolower(trim((string) $declared));
        }

        $host = parse_url((string) $this->url, PHP_URL_HOST);

        return ! empty($host) ? strtolower(trim((string) $host, '[]')) : null;
    }

    /**
     * Resolve a peer from a CLI "{peer}" argument that may be either a server_id
     * (uuid) or a URL. server_id is a uuid column, so comparing a URL against it
     * makes Postgres throw 22P02 ("invalid input syntax for type uuid") — the OR
     * branch the four federation commands used never saved it (the cast fails
     * before the OR is reached), so `federation:cold-sync <host-url>` fataled.
     * Branch on the needle's shape instead: a uuid matches server_id; anything
     * else matches url (trailing slash trimmed, matching how the rest of the
     * federation code stores urls).
     */
    public function scopeMatchingNeedle(Builder $query, ?string $needle): Builder
    {
        $needle = trim((string) $needle);

        return Str::isUuid($needle)
            ? $query->where('server_id', $needle)
            : $query->where('url', rtrim($needle, '/'));
    }
}
