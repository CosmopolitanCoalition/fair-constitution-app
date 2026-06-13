<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * R-25 substrate (F-IND-014) — THE worker-count source (owner ruling
 * #12): headcount = COUNT(*) WHERE status='active'. Polymorphic employer
 * ('organizations' | 'departments') — Art. III §6 applies identically,
 * the headcount source is singular (binding cross-designer contract).
 *
 * `active` = countersigned (a co-signed labor_recurring contract backs
 * the row). Every status flip dispatches RecomputeWorkerHeadcountJob —
 * queued, never synchronous.
 */
class OrgWorker extends Model
{
    use HasUuids, SoftDeletes;

    public const EMPLOYER_ORGANIZATIONS = 'organizations';
    public const EMPLOYER_DEPARTMENTS   = 'departments';

    public const STATUS_APPLIED = 'applied';
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_ENDED   = 'ended';

    protected $fillable = [
        'id',
        'employer_type',
        'employer_id',
        'user_id',
        'contract_id',
        'status',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(OrgContract::class, 'contract_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForEmployer($query, string $type, string $id)
    {
        return $query->where('employer_type', $type)->where('employer_id', $id);
    }
}
