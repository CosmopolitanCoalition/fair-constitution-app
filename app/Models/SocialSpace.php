<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase K-1 — a per-jurisdiction social space: the public_square (open resident discourse,
 * uncensorable) or the halls of governance (deliberation tied to institutions, append-only).
 * Exactly one PUBLIC square + one PUBLIC halls per jurisdiction; private org/user spaces
 * (is_private=true) are unconstrained and self-moderate (Art. I private half).
 */
class SocialSpace extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPE_PUBLIC_SQUARE = 'public_square';
    public const TYPE_HALLS         = 'halls';
    public const TYPE_GROUP         = 'group'; // a user-owned private room (group / DM) — Art. I private half

    public const STATUS_OPEN     = 'open';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'space_type',
        'title',
        'slug',
        'status',
        'is_private',
        'owner_org_id',
        'owner_user_id',
    ];

    protected $casts = [
        'is_private' => 'boolean',
    ];

    public function subforums(): HasMany
    {
        return $this->hasMany(SocialSubforum::class, 'space_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(SocialMembership::class, 'space_id');
    }

    /** The user who owns a private room (null for jurisdiction/org spaces). */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
