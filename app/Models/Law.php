<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One statute (C-7) — git-style versioned (law_versions hold complete
 * text per version; diffs are computed at render time). `act_number` is
 * unique per legislature, allocated under an advisory lock at enactment.
 *
 * CLK-19 shield inputs (`referendum_passed_by_supermajority`,
 * `shield_expires_with_election_id`) exist now; the validator gate and
 * shield release land with the referendum batch.
 */
class Law extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_ORDINARY               = 'ordinary';
    public const KIND_SETTING_CHANGE         = 'setting_change';
    public const KIND_RULES_OF_ORDER         = 'rules_of_order';
    public const KIND_ETHICS_CODE            = 'ethics_code';
    public const KIND_CHARTER                = 'charter';
    public const KIND_CREATION_ACT           = 'creation_act';
    public const KIND_REFERENDUM_ACT         = 'referendum_act';
    public const KIND_CONSTITUTIONAL_ARTICLE = 'constitutional_article';

    public const ORIGIN_BILL                = 'bill';
    public const ORIGIN_REFERENDUM          = 'referendum';
    public const ORIGIN_PETITION_INITIATIVE = 'petition_initiative';
    public const ORIGIN_JUDICIAL_REMEDY     = 'judicial_remedy';
    public const ORIGIN_FOUNDING            = 'founding';

    public const STATUS_IN_FORCE   = 'in_force';
    public const STATUS_AMENDED    = 'amended';
    public const STATUS_REPEALED   = 'repealed';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_STRUCK     = 'struck';

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'legislature_id',
        'act_number',
        'title',
        'kind',
        'scale',
        'scope_judiciary_id',
        'origin',
        'enacting_bill_id',
        'origin_ref_type',
        'origin_ref_id',
        'referendum_passed_by_supermajority',
        'shield_expires_with_election_id',
        'status',
        'current_version_no',
        'effective_at',
        'enacted_at',
    ];

    protected $casts = [
        'scale'                              => 'array',
        'referendum_passed_by_supermajority' => 'boolean',
        'current_version_no'                 => 'integer',
        'effective_at'                       => 'datetime',
        'enacted_at'                         => 'datetime',
    ];

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LawVersion::class, 'law_id');
    }

    public function enactingBill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'enacting_bill_id');
    }

    /** The in-force (latest) version row. */
    public function currentVersion(): ?LawVersion
    {
        return $this->versions()->where('version_no', $this->current_version_no)->first();
    }
}
