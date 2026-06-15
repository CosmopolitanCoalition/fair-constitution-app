<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A co-equal read/write member of a cluster (Phase G, G·co-member). Presents
 * `authoritative_server_id = NULL` for the cluster's subtree; leadership (who
 * writes) lives on the cluster, not here.
 */
class ClusterMember extends Model
{
    use HasUuids, SoftDeletes;

    public const STATE_FORMING = 'forming';

    public const STATE_ADMITTED = 'admitted';

    public const STATE_LIVE = 'live';

    public const STATE_SUSPENDED = 'suspended';

    public const STATE_DEPARTED = 'departed';

    protected $fillable = [
        'id',
        'cluster_id',
        'server_id',
        'is_self',
        'state',
        'role',
    ];

    protected $casts = [
        'is_self' => 'boolean',
    ];

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class, 'cluster_id');
    }
}
