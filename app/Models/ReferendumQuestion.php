<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ESM-11 — referendum question (C-8, Art. II §6). Two origins:
 *
 *  - delegation: an F-LEG-023 supermajority RESOLUTION of the chamber
 *    (`delegating_vote_id` — a chamber_vote, not a statute; the law is
 *    what the referendum enacts);
 *  - petition: a validated F-IND-009 petition (`petition_id`).
 *
 * `threshold` is DERIVED from act_type (supermajority act type ⇔
 * population supermajority) — the engine computes it, no API ever accepts
 * it (DB CHECK is the backstop). Questions queue to the NEXT
 * jurisdiction-wide ballot (ReferendumService::attachQueued);
 * `eligible_population` snapshots the CIVIC population at tally; pass /
 * fail resolves at certification through the PROTECTED quorum() /
 * supermajority() functions over that denominator.
 */
class ReferendumQuestion extends Model
{
    use HasUuids, SoftDeletes;

    public const ORIGIN_DELEGATION = 'delegation';
    public const ORIGIN_PETITION   = 'petition';

    public const THRESHOLD_MAJORITY      = 'majority';
    public const THRESHOLD_SUPERMAJORITY = 'supermajority';

    public const STATUS_QUEUED      = 'queued';
    public const STATUS_SCHEDULED   = 'scheduled';
    public const STATUS_VOTED       = 'voted';
    public const STATUS_PASSED      = 'passed';
    public const STATUS_FAILED      = 'failed';
    public const STATUS_INVALIDATED = 'invalidated';

    public const ACT_TYPES = ['ordinary', 'setting_change', 'supermajority'];

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'origin',
        'delegating_vote_id',
        'petition_id',
        'question',
        'law_text',
        'act_type',
        'threshold',
        'targets_setting_key',
        'proposed_value',
        'election_id',
        'eligible_population',
        'yes_count',
        'no_count',
        'status',
        'resulting_law_id',
        'certified_at',
    ];

    protected $casts = [
        'proposed_value'      => 'array',
        'eligible_population' => 'integer',
        'yes_count'           => 'integer',
        'no_count'            => 'integer',
        'certified_at'        => 'datetime',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function delegatingVote(): BelongsTo
    {
        return $this->belongsTo(ChamberVote::class, 'delegating_vote_id');
    }

    public function petition(): BelongsTo
    {
        return $this->belongsTo(Petition::class, 'petition_id');
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_id');
    }

    public function resultingLaw(): BelongsTo
    {
        return $this->belongsTo(Law::class, 'resulting_law_id');
    }
}
