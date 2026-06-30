<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person-to-person invite (the growth primitive). The inviter's minted secret a
 * friend presents (via /i/{token}) to land on a destination and continue after signup.
 * Only the Argon2id `token_hash` is stored (hidden); the plaintext `handle.secret` is
 * shown to the inviter once and never persisted. See InviteService for mint/resolve/
 * consume, and `cluster_join_keys` for the (separate) node-adoption analogue.
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
        'token_hash',
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
        'expires_at'  => 'datetime',
        'revoked_at'  => 'datetime',
    ];

    /** The Argon2id hash must never serialize into a prop, export, or log line. */
    protected $hidden = [
        'token_hash',
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
