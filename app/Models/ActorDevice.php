<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person's enrolled device signing key (Phase G, G-ID). The device's PUBLIC key
 * only; the secret never leaves the device (no escrow).
 */
class ActorDevice extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'user_id',
        'device_public_key',
        'label',
        'enrolled_at',
        'revoked_at',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
