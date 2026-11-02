<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person-to-person invite (the growth primitive). `handle` is the short code a friend
 * opens (via /i/{code}) to land on a destination and continue after signup: 8 random
 * URL-safe characters, or a custom name when `is_custom`. The code is the whole credential,
 * so it is never written to the audit chain. See InviteService for mint/resolve/consume,
 * and `cluster_join_keys` for the (separate) node-adoption analogue.
 */
class Invite extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_CALL = 'call';
    public const KIND_COMMONS = 'commons';
    public const KIND_PROCEEDING = 'proceeding';
    public const KIND_SPACE = 'space';

    protected $fillable = [
        'id',
        'handle',
        'is_custom',
        'inviter_user_id',
        'kind',
        'destination',
        'label',
        'max_uses',
        'uses',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'destination' => 'array',
        'max_uses'    => 'integer',
        'uses'        => 'integer',
        'is_custom'   => 'boolean',
        'expires_at'  => 'datetime',
        'revoked_at'  => 'datetime',
    ];

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }

    /** Live = not revoked, not expired, and (reusable OR not yet exhausted). */
    public function isLive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_uses === null || (int) $this->uses < (int) $this->max_uses);
    }

    /** The server-built, same-origin destination path the holder lands on. */
    public function path(): string
    {
        return (string) ($this->destination['path'] ?? '/civic');
    }
}
