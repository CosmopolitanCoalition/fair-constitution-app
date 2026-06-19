<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase K-1 — a discussion thread. `published_record_id` is THE back-pointer: when a hall
 * post is filed as testimony (F-SOC-002) it points at the sealed public_records.id (the
 * cross-instance uuid, NOT seq). Status has NO 'removed' value — the public square is
 * uncensorable (Art. I); the only removals are the four office-gated carve-outs, each logged.
 */
class SocialThread extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_OPEN     = 'open';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'id',
        'subforum_id',
        'author_user_id',
        'author_display',
        'title',
        'status',
        'published_record_id',
    ];

    public function subforum(): BelongsTo
    {
        return $this->belongsTo(SocialSubforum::class, 'subforum_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class, 'thread_id');
    }
}
