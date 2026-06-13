<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** D-6 — a grant application against an appropriation line. */
class GrantApplication extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_AWARDED   = 'awarded';
    public const STATUS_DECLINED  = 'declined';
    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'id',
        'appropriation_id',
        'applicant_org_id',
        'amount',
        'purpose',
        'status',
        'decided_by_member_id',
        'decided_at',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    public function appropriation(): BelongsTo
    {
        return $this->belongsTo(Appropriation::class, 'appropriation_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'applicant_org_id');
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(GrantDisbursement::class, 'application_id');
    }
}
