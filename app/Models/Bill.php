<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ESM-07 bill (C-6). `act_type` FIXES the floor-vote basis at
 * introduction; `scale` (jurisdiction ids bound) is validated ⊆ the
 * legislature's subtree at F-LEG-003 and fixed there (Art. V §4).
 *
 * Lifecycle: introduced → referred → in_committee → (reported | tabled)
 * → on_floor → (passed | failed) → enacted, plus withdrawn. Bicameral
 * chambers pass only when committee AND floor votes each adopt per kind
 * (the vote engine enforces; this lifecycle consumes `outcome`).
 */
class Bill extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPE_ORDINARY = 'ordinary';

    public const TYPE_SETTING_CHANGE = 'setting_change';

    public const TYPE_SUPERMAJORITY = 'supermajority';

    public const TYPE_DUAL_SUPERMAJORITY = 'dual_supermajority';

    public const ACT_TYPES = [
        self::TYPE_ORDINARY, self::TYPE_SETTING_CHANGE,
        self::TYPE_SUPERMAJORITY, self::TYPE_DUAL_SUPERMAJORITY,
    ];

    public const STATUS_INTRODUCED = 'introduced';

    public const STATUS_REFERRED = 'referred';

    public const STATUS_IN_COMMITTEE = 'in_committee';

    public const STATUS_REPORTED = 'reported';

    public const STATUS_TABLED = 'tabled';

    public const STATUS_ON_FLOOR = 'on_floor';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ENACTED = 'enacted';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'id',
        'legislature_id',
        'jurisdiction_id',
        'sponsor_member_id',
        'title',
        'act_type',
        'scale',
        'scope_judiciary_id',
        'targets_setting_key',
        'proposed_value',
        'targets_challenge_id',
        'effective_at',
        'status',
        'committee_id',
        'current_version_no',
        'introduced_at',
        'passed_at',
        'failed_at',
        'enacted_at',
        'enacted_law_id',
    ];

    protected $casts = [
        'scale' => 'array',
        'proposed_value' => 'json',
        'effective_at' => 'datetime',
        'current_version_no' => 'integer',
        'introduced_at' => 'datetime',
        'passed_at' => 'datetime',
        'failed_at' => 'datetime',
        'enacted_at' => 'datetime',
    ];

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(LegislatureMember::class, 'sponsor_member_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BillVersion::class, 'bill_id');
    }

    public function enactedLaw(): BelongsTo
    {
        return $this->belongsTo(Law::class, 'enacted_law_id');
    }

    /** The current (latest) version row. */
    public function currentVersion(): ?BillVersion
    {
        return $this->versions()->where('version_no', $this->current_version_no)->first();
    }
}
