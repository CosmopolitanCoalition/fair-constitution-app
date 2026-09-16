<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * W-0449 — an uploaded film. The primary key is the catalog id (v-<slug>), the
 * same id space the generated registry uses, so a DB row with the same id wins
 * over the registry record in MediaMeta::all(). Not a HasUuids model: the id is
 * a meaningful catalog id, assigned by the upload controller, never a uuid.
 */
class MediaVideo extends Model
{
    use SoftDeletes;

    public const SOURCE = 'upload';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'subject',
        'slug',
        'master',
        'title',
        'summary',
        'poster',
        'seconds',
        'source',
        'created_by',
    ];

    protected $casts = [
        'seconds' => 'decimal:1',
    ];

    public function tracks(): HasMany
    {
        return $this->hasMany(MediaVideoTrack::class, 'video_id');
    }
}
