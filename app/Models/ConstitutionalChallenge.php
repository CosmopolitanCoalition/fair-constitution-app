<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ESM-CC — a constitutional challenge (Art. IV §5, PHASE_E_DESIGN_challenge_law
 * §A). THE Art. IV §5 machine: any inhabitant (R-03) may claim a law unjustly
 * impedes their rights; the court hears it, finds (or not) a contradiction,
 * recommends a remedy, and one of three paths resolves it — the legislature
 * amends (Path 1), overrides by supermajority (Path 2), or, when both windows
 * expire, the judiciary applies its own remedy directly (Path 3, the exit
 * criterion).
 *
 * The challenge is its own durable entity, distinct from the `cases` row it is
 * heard in: the CLK-11/CLK-12 windows run for weeks-to-months AFTER the hearing
 * closes, gated on legislature action — they cannot live on a closed case.
 * ConstitutionalChallengeService is the only writer of `status`.
 */
class ConstitutionalChallenge extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_FILED = 'filed';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_FINDING_ISSUED = 'finding_issued';

    public const STATUS_REMEDY_RECOMMENDED = 'remedy_recommended';

    public const STATUS_LEGISLATIVE_WINDOW_OPEN = 'legislative_window_open';

    public const STATUS_AMENDED_BY_LEGISLATURE = 'amended_by_legislature';

    public const STATUS_OVERRIDDEN = 'overridden';

    public const STATUS_JUDICIAL_REMEDY_APPLIED = 'judicial_remedy_applied';

    public const STATUS_CLOSED = 'closed';

    public const BASIS_CONSTITUTION = 'constitution';

    public const BASIS_OTHER_LAW = 'other_law';

    public const PATH_LEGISLATIVE_AMENDMENT = 'legislative_amendment';

    public const PATH_LEGISLATURE_OVERRIDE = 'legislature_override';

    public const PATH_JUDICIAL_REMEDY = 'judicial_remedy';

    public const PATH_DISMISSED = 'dismissed';

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'judiciary_id',
        'challenged_law_id',
        'challenged_version_no',
        'filed_by_user_id',
        'claim_text',
        'claimed_basis',
        'cited_authority_law_id',
        'constitutional_citation',
        'case_id',
        'status',
        'finding_id',
        'remedy_id',
        'resolution_path',
        'resolution_ref_type',
        'resolution_ref_id',
        'filed_at',
        'heard_at',
        'finding_at',
        'closed_at',
        'record_id',
    ];

    protected $casts = [
        'challenged_version_no' => 'integer',
        'filed_at' => 'datetime',
        'heard_at' => 'datetime',
        'finding_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function judiciary(): BelongsTo
    {
        return $this->belongsTo(Judiciary::class, 'judiciary_id');
    }

    public function challengedLaw(): BelongsTo
    {
        return $this->belongsTo(Law::class, 'challenged_law_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(CourtCase::class, 'case_id');
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(ConstitutionalFinding::class, 'finding_id');
    }

    public function remedy(): BelongsTo
    {
        return $this->belongsTo(RemedyRecommendation::class, 'remedy_id');
    }
}
