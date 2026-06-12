<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A seated (or historical) legislature member — the R-09 derivation source
 * (B-11). Created by F-ELB-004 certification with status 'elected'; the
 * Phase C oath (F-LEG-001) flips to 'seated'. Re-election across terms
 * keeps history rows — only one CURRENT row per (legislature, user)
 * (partial unique `legislature_members_one_current`).
 *
 * `seat_type` is the legacy char(1) 'a'/'b'; `seatKind()` maps it to the
 * election_races.seat_kind enum ('type_a'/'type_b'). `vote_share_norm` is
 * copied from race_results at seating (committee tie-break input).
 */
class LegislatureMember extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_ELECTED    = 'elected';
    public const STATUS_SEATED     = 'seated';
    public const STATUS_VACATED    = 'vacated';
    public const STATUS_REMOVED    = 'removed';
    public const STATUS_TERM_ENDED = 'term_ended';

    /** Statuses that count as "currently holding the seat". */
    public const CURRENT_STATUSES = [self::STATUS_ELECTED, self::STATUS_SEATED];

    /** char(1) seat_type ↔ election_races.seat_kind. */
    public const SEAT_KIND_MAP = ['a' => 'type_a', 'b' => 'type_b'];

    protected $fillable = [
        'id',
        'legislature_id',
        'user_id',
        'seat_type',
        'seat_no',
        'district_id',
        'elected_in_race_id',
        'term_id',
        'election_id',
        'vote_share_norm',
        'seated_on',
        'seated_at',
        'term_ends_on',
        'status',
        'vacated_at',
        'vacancy_reason',
        'home_jurisdiction_id',
        'is_speaker',
    ];

    protected $casts = [
        'seat_no'         => 'integer',
        'vote_share_norm' => 'decimal:4',
        'seated_on'       => 'date',
        'seated_at'       => 'datetime',
        'term_ends_on'    => 'date',
        'vacated_at'      => 'datetime',
        'is_speaker'      => 'boolean',
    ];

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(LegislatureDistrict::class, 'district_id');
    }

    public function electedInRace(): BelongsTo
    {
        return $this->belongsTo(ElectionRace::class, 'elected_in_race_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'term_id');
    }

    public function homeJurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'home_jurisdiction_id');
    }

    public function scopeCurrent($query)
    {
        return $query->whereIn('status', self::CURRENT_STATUSES);
    }

    /** election_races.seat_kind equivalent of the char(1) seat_type. */
    public function seatKind(): string
    {
        return self::SEAT_KIND_MAP[$this->seat_type] ?? 'type_a';
    }
}
