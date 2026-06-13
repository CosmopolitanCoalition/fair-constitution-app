<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * F-LEG-022 removal/impeachment/censure/expulsion proceeding (F-SPK-007
 * presides — never over their own case: PROTECTED-validator rule
 * `removal.presider`, Art. II §3).
 *
 * ESM (minimal, §D.3): opened → presiding_designated → voted →
 * closed(outcome). `vote_id` soft-refs the supermajority chamber vote.
 * Removal parity: `kind` reserves judge_removal / executive_removal;
 * Phase C activates only legislature_members subjects.
 */
class RemovalProceeding extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_IMPEACHMENT       = 'impeachment';
    public const KIND_CENSURE           = 'censure';
    public const KIND_EXPULSION         = 'expulsion';
    public const KIND_JUDGE_REMOVAL     = 'judge_removal';
    public const KIND_EXECUTIVE_REMOVAL = 'executive_removal';

    /**
     * Kinds with seated subjects. Phase D activates executive_removal
     * (removal parity — identical supermajority threshold and machinery
     * as legislators, PHASE_D_DESIGN_executive §B.4); Phase E activates
     * judge_removal (Art. IV §4 — judges carry the SAME removal exposure
     * as legislators; PHASE_E_DESIGN_judiciary §B.6).
     */
    public const ACTIVE_KINDS = [
        self::KIND_IMPEACHMENT,
        self::KIND_CENSURE,
        self::KIND_EXPULSION,
        self::KIND_EXECUTIVE_REMOVAL,
        self::KIND_JUDGE_REMOVAL,
    ];

    public const STATUS_OPENED               = 'opened';
    public const STATUS_PRESIDING_DESIGNATED = 'presiding_designated';
    public const STATUS_VOTED                = 'voted';
    public const STATUS_CLOSED               = 'closed';

    public const OUTCOME_REMOVED  = 'removed';
    public const OUTCOME_CENSURED = 'censured';
    public const OUTCOME_EXPELLED = 'expelled';
    public const OUTCOME_RETAINED = 'retained';

    protected $fillable = [
        'id',
        'legislature_id',
        'kind',
        'subject_type',
        'subject_id',
        'source_investigation_id',
        'presided_by_member_id',
        'opened_via',
        'vote_id',
        'status',
        'outcome',
        'closed_at',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
    ];

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function presidedBy(): BelongsTo
    {
        return $this->belongsTo(LegislatureMember::class, 'presided_by_member_id');
    }

    public function sourceInvestigation(): BelongsTo
    {
        return $this->belongsTo(MisconductInvestigation::class, 'source_investigation_id');
    }
}
