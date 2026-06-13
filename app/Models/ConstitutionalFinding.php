<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * F-JDG-004 — a constitutional finding (Art. IV §5.2 first half,
 * PHASE_E_DESIGN_challenge_law §A/§B.2). "If the Judiciary finds that any
 * legislation ... is contradictory to other law or The Constitution, it informs
 * The Legislature of what laws are in error and recommends a remedy." The
 * finding always records the determination; `finds_contradiction = false`
 * dismisses the challenge (no remedy, no clocks).
 *
 * The standard applied is Art. II §8 ("All other Judgements can be overturned
 * only by proven contradictions in law and errors found in the cases"); the
 * engine records the determination and enforces the consequences — it never
 * adjudicates whether the contradiction exists.
 */
class ConstitutionalFinding extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'challenge_id',
        'judiciary_id',
        'case_id',
        'full_court',
        'finds_contradiction',
        'contradiction_against',
        'superior_authority_law_id',
        'constitutional_citation',
        'offending_law_id',
        'offending_version_no',
        'opinion_text',
        'panel_snapshot',
        'record_id',
        'issued_at',
    ];

    protected $casts = [
        'full_court' => 'boolean',
        'finds_contradiction' => 'boolean',
        'offending_version_no' => 'integer',
        'panel_snapshot' => 'array',
        'issued_at' => 'datetime',
    ];

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(ConstitutionalChallenge::class, 'challenge_id');
    }

    public function offendingLaw(): BelongsTo
    {
        return $this->belongsTo(Law::class, 'offending_law_id');
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(RemedyRecommendation::class, 'finding_id', 'finding_id');
    }

    public function offendingLaws(): HasMany
    {
        return $this->hasMany(FindingOffendingLaw::class, 'finding_id');
    }
}
