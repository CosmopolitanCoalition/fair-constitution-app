<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase K-1 — space membership. LOCAL-ONLY: never federates, never audited. `role='owner'` is
 * the in-handler self-moderation check for PRIVATE spaces ONLY — it is NOT a public-square
 * "moderator" bit and NOT a derived office role. `block_user_id` is the M-3 per-user block:
 * client-side feed curation, content stays up for everyone else, never federates/audits.
 */
class SocialMembership extends Model
{
    use HasUuids, SoftDeletes;

    public const ROLE_MEMBER = 'member';
    public const ROLE_OWNER  = 'owner';

    protected $fillable = [
        'id',
        'space_id',
        'user_id',
        'role',
        'block_user_id',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(SocialSpace::class, 'space_id');
    }
}
