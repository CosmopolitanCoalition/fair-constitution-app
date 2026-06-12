<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Misconduct investigation docket row (chamber ops §D.3). Intake by any
 * resident (audited non-form action — registry gap flagged in the design),
 * own motion, or system (CLK-02 referral: complainant_user_id NULL).
 * Findings publish to public_records; `referred` hands off to a
 * removal_proceedings row.
 */
class MisconductInvestigation extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_INTAKE            = 'intake';
    public const STATUS_INVESTIGATING     = 'investigating';
    public const STATUS_REFERRED          = 'referred';
    public const STATUS_CLOSED_NO_FINDING = 'closed_no_finding';

    protected $fillable = [
        'id',
        'admin_office_id',
        'code',
        'subject_type',
        'subject_id',
        'complainant_user_id',
        'summary',
        'status',
        'findings_record_id',
        'referred_proceeding_id',
    ];

    public function office(): BelongsTo
    {
        return $this->belongsTo(AdminOffice::class, 'admin_office_id');
    }

    public function complainant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'complainant_user_id');
    }

    public function referredProceeding(): BelongsTo
    {
        return $this->belongsTo(RemovalProceeding::class, 'referred_proceeding_id');
    }
}
