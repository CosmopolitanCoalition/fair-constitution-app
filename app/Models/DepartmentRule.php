<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * F-BOG-001 — a versioned department rule citing an enabling instrument.
 * "Rules implement, they cannot exceed": enforced structurally at filing
 * (the instrument exists, is in force/active, and its scope covers the
 * department); semantic excess is Phase E judicial-review territory.
 * Emergency-enabled rules (`expires_with_enabling`) die with their power
 * — the CLK-03 cascade flips them to `expired`.
 */
class DepartmentRule extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_DRAFT      = 'draft';
    public const STATUS_IN_FORCE   = 'in_force';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_EXPIRED    = 'expired';

    protected $fillable = [
        'id',
        'department_id',
        'rule_code',
        'name',
        'text',
        'enabling_type',
        'enabling_id',
        'expires_with_enabling',
        'version_no',
        'supersedes_rule_id',
        'filed_by_seat_id',
        'record_id',
        'status',
    ];

    protected $casts = [
        'expires_with_enabling' => 'boolean',
        'version_no'            => 'integer',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(BoardSeat::class, 'filed_by_seat_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(DepartmentRule::class, 'supersedes_rule_id');
    }
}
