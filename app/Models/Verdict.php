<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article IV §4 — the decided outcome (panel ruling and/or jury verdict).
 * Criminal guilt is the jury's; the panel rules on law/civil/administrative
 * outcomes. `double_jeopardy_flag` is set true ⇔ the case is criminal — set
 * atomically with cases.double_jeopardy_locked by CaseService (Art. II §8).
 */
class Verdict extends Model
{
    use HasUuids, SoftDeletes;

    public const BY_PANEL = 'panel';

    public const BY_JURY = 'jury';

    public const OUTCOME_GUILTY = 'guilty';

    public const OUTCOME_NOT_GUILTY = 'not_guilty';

    public const OUTCOME_LIABLE = 'liable';

    public const OUTCOME_NOT_LIABLE = 'not_liable';

    public const OUTCOME_DISMISSED = 'dismissed';

    public const OUTCOME_FOR_PETITIONER = 'for_petitioner';

    public const OUTCOME_FOR_RESPONDENT = 'for_respondent';

    protected $fillable = [
        'id',
        'case_id',
        'decided_by',
        'outcome',
        'panel_vote_for',
        'panel_vote_against',
        'jury_unanimous',
        'summary',
        'double_jeopardy_flag',
        'record_id',
        'decided_at',
    ];

    protected $casts = [
        'panel_vote_for' => 'integer',
        'panel_vote_against' => 'integer',
        'jury_unanimous' => 'boolean',
        'double_jeopardy_flag' => 'boolean',
        'decided_at' => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(CourtCase::class, 'case_id');
    }
}
