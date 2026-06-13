<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Article III §3 — member of an executive.
 *
 * For type='committee' executives: rank=0 only, all members are
 * principals with equal voting weight (multiple rank-0 rows are legal).
 * For type='individual': rank=0 is the elected primary; rank=1..4 are
 * the auto-seated runners-up acting as advisors. The one-seated-principal
 * rule for individual executives is an ENGINE rule (type lives on the
 * parent row), not a DB index.
 *
 * `selection` is provenance: delegated_proportional (F-LEG-014 —
 * legislature_member_id set, term_id NULL: the member is ex officio and
 * their term IS their legislative seat's), elected_stv / elected_rcv
 * (certification), advisor_derivation (deriveAdvisors ranks 1–4), or
 * succession (an advisor flipped principal — same term, never extended).
 */
class ExecutiveMember extends Model
{
    use SoftDeletes;

    public const ROLE_PRINCIPAL = 'principal';
    public const ROLE_ADVISOR   = 'advisor';

    public const SELECTION_DELEGATED_PROPORTIONAL = 'delegated_proportional';
    public const SELECTION_ELECTED_STV            = 'elected_stv';
    public const SELECTION_ELECTED_RCV            = 'elected_rcv';
    public const SELECTION_ADVISOR_DERIVATION     = 'advisor_derivation';
    public const SELECTION_SUCCESSION             = 'succession';

    public const STATUS_SEATED     = 'seated';
    public const STATUS_LEFT       = 'left';
    public const STATUS_REMOVED    = 'removed';
    public const STATUS_SUCCEEDED  = 'succeeded';
    public const STATUS_TERM_ENDED = 'term_ended';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'executive_id',
        'user_id',
        'role',
        'rank',
        'joined_at',
        'left_at',
        'legislature_member_id',
        'elected_in_race_id',
        'term_id',
        'selection',
        'status',
    ];

    protected $casts = [
        'rank'      => 'integer',
        'joined_at' => 'date',
        'left_at'   => 'date',
    ];

    public function executive(): BelongsTo
    {
        return $this->belongsTo(Executive::class, 'executive_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function legislatureMember(): BelongsTo
    {
        return $this->belongsTo(LegislatureMember::class, 'legislature_member_id');
    }

    public function electedInRace(): BelongsTo
    {
        return $this->belongsTo(ElectionRace::class, 'elected_in_race_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'term_id');
    }

    public function scopeSeated($query)
    {
        return $query->where('status', self::STATUS_SEATED);
    }
}
