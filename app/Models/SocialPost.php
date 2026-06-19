<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase K-1 — a post in a thread. `is_official` + `acting_seat` tag a verified seat-holder
 * speaking in office; that claim is validated against LIVE derived roles at file time —
 * authority is never stored. `author_display` is a pseudonym snapshot. No 'removed' status:
 * public-square posts are uncensorable (the four carve-outs are the only removals, each logged).
 */
class SocialPost extends Model
{
    use HasUuids, SoftDeletes;

    public const SEAT_LEGISLATURE_MEMBER = 'legislature_member';
    public const SEAT_COMMITTEE          = 'committee_seat';
    public const SEAT_EXEC               = 'exec_seat';
    public const SEAT_JUDICIAL           = 'judicial_seat';

    protected $fillable = [
        'id',
        'thread_id',
        'author_user_id',
        'author_display',
        'body',
        'is_official',
        'acting_seat',
    ];

    protected $casts = [
        'is_official' => 'boolean',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(SocialThread::class, 'thread_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(SocialReaction::class, 'post_id');
    }
}
