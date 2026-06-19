<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase K-1 — a reaction on a post. LOCAL-ONLY: never federates, never reaches the public
 * register or the audit chain (its subject type is in FORBIDDEN_SUBJECT_TYPES as a tripwire,
 * but the real boundary is that reactions are plain inserts that never call publish()).
 * 'flag' is a BEHAVIORAL anti-spam signal (M-4), never a viewpoint takedown.
 */
class SocialReaction extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_UP         = 'up';
    public const KIND_HEART      = 'heart';
    public const KIND_INSIGHTFUL = 'insightful';
    public const KIND_FLAG       = 'flag';

    protected $fillable = [
        'id',
        'post_id',
        'user_id',
        'kind',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'post_id');
    }
}
