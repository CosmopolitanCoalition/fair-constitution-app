<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase K-1 — one pseudonymous social profile per user. `display_name` is a pseudonym;
 * `name`/email NEVER appear here. `visibility` is a personal preference that NEVER gates a
 * right (Art. I — participation is residency-only).
 */
class SocialProfile extends Model
{
    use HasUuids, SoftDeletes;

    public const VISIBILITY_PUBLIC       = 'public';
    public const VISIBILITY_JURISDICTION = 'jurisdiction';
    public const VISIBILITY_PRIVATE      = 'private';

    protected $fillable = [
        'id',
        'user_id',
        'handle',
        'display_name',
        'bio',
        'visibility',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
