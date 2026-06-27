<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A mirror membership (Phase G, G1). `role` is OUR role: `mirror` (we read-only-
 * replicate the peer host — authoritative for nothing) or `host` (we host the
 * peer as our mirror). At most one ACTIVE `mirror` membership may exist at a time
 * (the cluster_memberships_one_active_mirror partial-unique index).
 */
class ClusterMembership extends Model
{
    use HasUuids, SoftDeletes;

    public const ROLE_MIRROR = 'mirror';

    public const ROLE_HOST = 'host';

    public const STATE_REQUESTED = 'requested';

    public const STATE_ADMITTED = 'admitted';

    public const STATE_SYNCING = 'syncing';

    public const STATE_LIVE = 'live';

    public const STATE_SUSPENDED = 'suspended';

    public const STATE_DEPARTED = 'departed';

    public const STATE_REJECTED = 'rejected';

    public const ADMISSION_JOIN_KEY = 'join_key';

    public const ADMISSION_REQUEST = 'request';

    protected $fillable = [
        'id',
        'peer_id',
        'role',
        'state',
        'admission_method',
        'scope_jurisdiction_id',
        'backfill_cursor_seq',
        'backfill_target_seq',
        'backfilled_at',
        // Geodata-seed transfer (roles-campaign Phase 0b).
        'seed_dataset',
        'seed_version',
        'seed_sha256',
        'seed_total_bytes',
        'seed_cursor_bytes',
        'seeded_at',
    ];

    protected $casts = [
        'backfill_cursor_seq' => 'integer',
        'backfill_target_seq' => 'integer',
        'backfilled_at' => 'datetime',
        'seed_total_bytes' => 'integer',
        'seed_cursor_bytes' => 'integer',
        'seeded_at' => 'datetime',
    ];

    public function peer(): BelongsTo
    {
        return $this->belongsTo(FederationPeer::class, 'peer_id');
    }

    /** A membership is active while it has neither departed nor been rejected. */
    public function isActive(): bool
    {
        return ! in_array($this->state, [self::STATE_DEPARTED, self::STATE_REJECTED], true);
    }
}
