<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W-0449 — a per-surface film assignment for the Learning Drawer. The surface
 * id is the key: one film per surface. `video_id` is a catalog id (registry or
 * uploaded). MediaMeta::surfaceOverride() reads this so the Learn flyout and
 * the lesson page prefer an assigned film over the registry default.
 */
class MediaSurfaceVideo extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'surface_id';

    protected $fillable = [
        'surface_id',
        'video_id',
        'assigned_by',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(MediaVideo::class, 'video_id');
    }
}
