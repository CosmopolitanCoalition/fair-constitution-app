<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W-0449 — one audio or caption track of an uploaded film. `code` is the BCP-47
 * track code (the <track srclang>); `name` is the language English name (the
 * media filename token, e.g. "Norwegian Bokmal"). One track per (video, kind,
 * code).
 */
class MediaVideoTrack extends Model
{
    use HasUuids;

    public const KINDS = ['audio', 'captions'];

    protected $fillable = [
        'id',
        'video_id',
        'kind',
        'code',
        'name',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(MediaVideo::class, 'video_id');
    }
}
