<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A co-member cluster (Phase G, G·co-member). AUTHORITY (which cluster owns a
 * subtree) and LEADERSHIP (which node writes) are orthogonal: `leader_server_id`
 * is a data-tier axis written by exactly one method (ClusterMembershipService::
 * reconcileLeadership) and NEVER by any authority-path code.
 */
class Cluster extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_AUTHORITY = 'authority';

    public const KIND_MIRROR = 'mirror';

    protected $fillable = [
        'id',
        'name',
        'kind',
        'jurisdiction_id',
        'authority_claim_id',
        'is_self',
        'leader_server_id',
        'leader_epoch',
        'topology',
        'dcs_backend',
    ];

    protected $casts = [
        'is_self' => 'boolean',
        'leader_epoch' => 'integer',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(ClusterMember::class, 'cluster_id');
    }
}
