<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * W-0448 — one media ingestion run (website download or local-folder copy).
 * Mirrors the sim/autoscale run shape: denormalised counters the Step-2
 * dashboard polls, a status the control endpoint flips (running | halted |
 * done | failed), and a per-item child list on MediaPullItem.
 */
class MediaPull extends Model
{
    use HasUuids;

    public const SOURCES = ['web', 'folder'];

    public const STATUSES = ['running', 'halted', 'done', 'failed'];

    protected $fillable = [
        'id',
        'source',
        'source_ref',
        'status',
        'options',
        'items_total',
        'items_done',
        'items_failed',
        'bytes_total',
        'bytes_done',
        'error',
        'initiator_user_id',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'options'      => 'array',
        'items_total'  => 'integer',
        'items_done'   => 'integer',
        'items_failed' => 'integer',
        'bytes_total'  => 'integer',
        'bytes_done'   => 'integer',
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(MediaPullItem::class, 'pull_id');
    }
}
