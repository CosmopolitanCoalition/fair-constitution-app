<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article IV §4 (F-JDG-003) — the panel's commentary on the law (NOT a change
 * to it). Multiple opinions per case (majority/concurrence/dissent). An
 * opinion link is COMMENTARY ONLY — it never mutates laws/law_versions;
 * editing a law's text is the Art. IV §5 process (F-JDG-006, sibling design).
 */
class Opinion extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_MAJORITY = 'majority';

    public const KIND_CONCURRENCE = 'concurrence';

    public const KIND_DISSENT = 'dissent';

    protected $fillable = [
        'id',
        'case_id',
        'panel_id',
        'authored_by_seat_id',
        'kind',
        'title',
        'body',
        'record_id',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(CourtCase::class, 'case_id');
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(Panel::class, 'panel_id');
    }

    public function authoredBySeat(): BelongsTo
    {
        return $this->belongsTo(JudicialSeat::class, 'authored_by_seat_id');
    }

    public function lawLinks(): HasMany
    {
        return $this->hasMany(OpinionLawLink::class, 'opinion_id');
    }
}
