<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Phase G (G-OP) — the LOCAL operator account: the infrastructure / governance-of-
 * the-instance login. A plane DELIBERATELY SEPARATE from the citizen `users`
 * plane: no foreign key, no shared identity. A human may be both, but the app
 * stores them as unrelated rows so that `RoleService` never reads operator state
 * (the plane wall — pinned by OperatorPlaneSeparationTest).
 *
 * `password` authenticates LOCALLY only and is `$hidden`; it NEVER federates.
 * Cross-mesh recognition is by device-key POSSESSION (OperatorDevice +
 * MeshOperatorKey), never by replaying this credential.
 */
class OperatorAccount extends Authenticatable
{
    use HasUuids, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'server_id',
        'username',
        'password',
        'mesh_operator_id',
        'status',
        'last_login_at',
    ];

    /** Never serialize the credential — and the wire payloads never include it. */
    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'password'      => 'hashed',
        ];
    }

    public function devices(): HasMany
    {
        return $this->hasMany(OperatorDevice::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->deleted_at === null;
    }
}
