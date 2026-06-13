<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F-BOG-002 — a department report obligation (WF-EXE-09). Reporting
 * cadence is CHARTER DATA, not a constitutional clock: the first
 * periodic row seeds when the department reaches `operating`; filing
 * creates the next row; a nightly sweep flips due → overdue. No soft
 * deletes — the filing record is the record.
 */
class DepartmentReport extends Model
{
    use HasUuids;

    public const KIND_PERIODIC = 'periodic';
    public const KIND_SPECIAL  = 'special';

    public const STATUS_DUE     = 'due';
    public const STATUS_FILED   = 'filed';
    public const STATUS_OVERDUE = 'overdue';

    protected $fillable = [
        'id',
        'department_id',
        'kind',
        'period_label',
        'due_on',
        'filed_at',
        'filed_by_seat_id',
        'recipients',
        'record_id',
        'status',
    ];

    protected $casts = [
        'due_on'     => 'date',
        'filed_at'   => 'datetime',
        'recipients' => 'array',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(BoardSeat::class, 'filed_by_seat_id');
    }
}
