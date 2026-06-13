<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ESM-03 Election (B-3). Two-phase open ballot:
 *
 *   scheduled → approval_open → finalist_cutoff → ranked_open →
 *   voting_closed → tabulating → certified → final
 *                              ↘ audit_rerun → final     (cancelled)
 *
 * All phase transitions flow through ElectionLifecycleService (WI-B3) —
 * the single ESM-03 authority, every move audited. Elections fire from
 * clocks (`triggered_by_timer_id`), never official discretion; the cycle
 * chains through `prior_election_id` (certification of N opens approval
 * of N+1 — no dead period).
 *
 * Only kinds general/special are writable in Phase B (engine-gated); the
 * full enum lands now so later phases do not migrate.
 *
 * `ballot_key_wrapped` (per-election sodium data key, wrapped by the app
 * key) is HIDDEN from serialization — it must never reach Inertia props
 * or API output.
 */
class Election extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_GENERAL         = 'general';
    public const KIND_SPECIAL         = 'special';
    public const KIND_EXECUTIVE       = 'executive';
    public const KIND_JUDICIAL        = 'judicial';
    public const KIND_REFERENDUM      = 'referendum';
    public const KIND_ORG_BOARD_OWNER  = 'org_board_owner';
    public const KIND_ORG_BOARD_WORKER = 'org_board_worker';
    public const KIND_RESTORATION     = 'restoration';

    public const STATUS_SCHEDULED       = 'scheduled';
    public const STATUS_APPROVAL_OPEN   = 'approval_open';
    public const STATUS_FINALIST_CUTOFF = 'finalist_cutoff';
    public const STATUS_RANKED_OPEN     = 'ranked_open';
    public const STATUS_VOTING_CLOSED   = 'voting_closed';
    public const STATUS_TABULATING      = 'tabulating';
    public const STATUS_CERTIFIED       = 'certified';
    public const STATUS_AUDIT_RERUN     = 'audit_rerun';
    public const STATUS_FINAL           = 'final';
    public const STATUS_CANCELLED       = 'cancelled';

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'legislature_id',
        'kind',
        'status',
        'trigger',
        'voting_method',
        'district_map_id',
        'election_board_id',
        'approval_opens_at',
        'finalist_cutoff_at',
        'ranked_opens_at',
        'ranked_closes_at',
        'certified_at',
        'prior_election_id',
        'triggered_by_timer_id',
        'vacancy_id',
        'ballot_key_wrapped',
        // Phase D (D-O8): the governed board for org_board_* kinds.
        'board_id',
        // Phase D (D-1): the office an `executive`-kind election fills.
        'executive_id',
    ];

    /** The wrapped ballot key must never serialize (design §B.5.2). */
    protected $hidden = [
        'ballot_key_wrapped',
    ];

    protected $casts = [
        'approval_opens_at'  => 'datetime',
        'finalist_cutoff_at' => 'datetime',
        'ranked_opens_at'    => 'datetime',
        'ranked_closes_at'   => 'datetime',
        'certified_at'       => 'datetime',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function districtMap(): BelongsTo
    {
        return $this->belongsTo(LegislatureDistrictMap::class, 'district_map_id');
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(ElectionBoard::class, 'election_board_id');
    }

    /** Phase D: the governance board an org_board_* election fills. */
    public function governedBoard(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id');
    }

    /** Phase D (D-1): the executive office an executive election fills. */
    public function executive(): BelongsTo
    {
        return $this->belongsTo(Executive::class, 'executive_id');
    }

    public function races(): HasMany
    {
        return $this->hasMany(ElectionRace::class, 'election_id');
    }

    public function candidacies(): HasMany
    {
        return $this->hasMany(Candidacy::class, 'election_id');
    }

    public function priorElection(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prior_election_id');
    }

    public function nextElection(): HasOne
    {
        return $this->hasOne(self::class, 'prior_election_id');
    }

    public function triggeredByTimer(): BelongsTo
    {
        return $this->belongsTo(ClockTimer::class, 'triggered_by_timer_id');
    }

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class, 'vacancy_id');
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(ElectionCertification::class, 'election_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(ElectionAudit::class, 'election_id');
    }
}
