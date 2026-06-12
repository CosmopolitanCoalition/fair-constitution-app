<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One complete-text version of a law (C-7) — append-only by convention,
 * NEVER deltas (design decision: full text makes Art. IV §5 judicial
 * edits and Phase F merge incorporation trivially correct). `text_hash`
 * (sha256) pins the version into the audit chain via the enactment /
 * amendment audit payload.
 */
class LawVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    public const SOURCE_ENACTMENT               = 'enactment';
    public const SOURCE_LEGISLATIVE_AMENDMENT   = 'legislative_amendment';
    public const SOURCE_JUDICIAL_REMEDY         = 'judicial_remedy';
    public const SOURCE_REFERENDUM_MODIFICATION = 'referendum_modification';
    public const SOURCE_MERGE_INCORPORATION     = 'merge_incorporation';

    protected $fillable = [
        'id',
        'law_id',
        'version_no',
        'text',
        'text_hash',
        'source',
        'source_ref_type',
        'source_ref_id',
        'created_at',
    ];

    protected $casts = [
        'version_no' => 'integer',
        'created_at' => 'datetime',
    ];

    public function law(): BelongsTo
    {
        return $this->belongsTo(Law::class, 'law_id');
    }
}
