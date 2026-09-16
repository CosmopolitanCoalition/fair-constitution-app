<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W-0448 — one file inside a media pull: a master, an audio track, or a caption
 * track. `dest` is relative to the library root; `source` is the website URL or
 * the folder-relative path the transfer reads from. `status` is the claim state
 * (pending | running | done | failed | skipped) the job walks.
 */
class MediaPullItem extends Model
{
    use HasUuids;

    public const KINDS = ['master', 'audio', 'captions'];

    public const STATUSES = ['pending', 'running', 'done', 'failed', 'skipped'];

    protected $fillable = [
        'id',
        'pull_id',
        'subject',
        'kind',
        'track_name',
        'source',
        'dest',
        'bytes_expected',
        'bytes_done',
        'status',
        'attempts',
        'error',
    ];

    protected $casts = [
        'bytes_expected' => 'integer',
        'bytes_done'     => 'integer',
        'attempts'       => 'integer',
    ];

    public function pull(): BelongsTo
    {
        return $this->belongsTo(MediaPull::class, 'pull_id');
    }
}
