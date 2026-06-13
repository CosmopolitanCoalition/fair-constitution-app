<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ESM-17 — a department chartered by an F-LEG-016 Department Creation
 * Act (Art. II §9: legislatures create departments; the mandatory five —
 * chief executive, treasury, defense, state, justice — are a surface
 * checklist, never auto-seeded).
 *
 * Governance: the overseeing executive (named in the act) nominates
 * governors (F-EXE-001) onto the department's unified `boards` row; the
 * legislature consents (F-LEG-020 via bog_consent); worker seats arrive
 * through the SAME co-determination engine as organizations (Art. III §6
 * applies identically — departments hire through the shared polymorphic
 * worker table; `worker_count` is the counter cache).
 */
class Department extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_CHIEF_EXECUTIVE = 'chief_executive';
    public const KIND_TREASURY        = 'treasury';
    public const KIND_DEFENSE         = 'defense';
    public const KIND_STATE           = 'state';
    public const KIND_JUSTICE         = 'justice';
    public const KIND_OTHER           = 'other';

    /** Art. II §9's named set — the surface checklist. */
    public const MANDATORY_KINDS = [
        self::KIND_CHIEF_EXECUTIVE,
        self::KIND_TREASURY,
        self::KIND_DEFENSE,
        self::KIND_STATE,
        self::KIND_JUSTICE,
    ];

    public const STATUS_CHARTERED           = 'chartered';
    public const STATUS_OVERSIGHT_ASSIGNED  = 'oversight_assigned';
    public const STATUS_GOVERNORS_NOMINATED = 'governors_nominated';
    public const STATUS_CONSENTED           = 'consented';
    public const STATUS_OPERATING           = 'operating';
    public const STATUS_REPORTING           = 'reporting';
    public const STATUS_RECHARTERED         = 'rechartered';
    public const STATUS_DISSOLVED           = 'dissolved';

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'executive_id',
        'kind',
        'name',
        'charter_law_id',
        'reporting_interval_months',
        'board_id',
        'worker_count',
        'status',
    ];

    protected $casts = [
        'reporting_interval_months' => 'integer',
        'worker_count'              => 'integer',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function executive(): BelongsTo
    {
        return $this->belongsTo(Executive::class, 'executive_id');
    }

    public function charterLaw(): BelongsTo
    {
        return $this->belongsTo(Law::class, 'charter_law_id');
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(DepartmentRule::class, 'department_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(DepartmentReport::class, 'department_id');
    }
}
