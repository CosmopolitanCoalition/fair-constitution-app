<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase G (G-OP) — the MESH-WIDE operator identity anchor. Replicable: a stable
 * UUID + a non-secret display handle + provenance, NO secret. A mesh operator is
 * *defined* by the device keys bound to its id in MeshOperatorKey; this row is
 * just the anchor each instance with a linked local operator holds a copy of.
 */
class MeshOperatorIdentity extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'display_handle',
        'genesis_server_id',
    ];

    public function keys(): HasMany
    {
        return $this->hasMany(MeshOperatorKey::class, 'mesh_operator_id');
    }
}
