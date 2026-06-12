<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F-CHR-004 — committee report. The report body is a public_records row
 * (`report_record_id` soft ref); `bill_id` soft-refs the sibling bills
 * table (FK wired conditionally by the committees migration).
 */
class CommitteeReport extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'committee_id',
        'bill_id',
        'filed_by_member_id',
        'report_record_id',
    ];

    public function committee(): BelongsTo
    {
        return $this->belongsTo(Committee::class, 'committee_id');
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(LegislatureMember::class, 'filed_by_member_id');
    }
}
