<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article IV §4 (F-JDG-009) — a sentencing order, issued ONLY on a guilty
 * criminal verdict (CaseService asserts verdict.outcome='guilty' before
 * insert). `vacated` only via the overturn path.
 */
class SentencingOrder extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_ISSUED = 'issued';

    public const STATUS_STAYED = 'stayed';

    public const STATUS_VACATED = 'vacated';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'id',
        'case_id',
        'verdict_id',
        'issued_by_seat_id',
        'terms',
        'effective_at',
        'expires_at',
        'status',
        'record_id',
    ];

    protected $casts = [
        'effective_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(CourtCase::class, 'case_id');
    }

    public function verdict(): BelongsTo
    {
        return $this->belongsTo(Verdict::class, 'verdict_id');
    }

    public function issuedBySeat(): BelongsTo
    {
        return $this->belongsTo(JudicialSeat::class, 'issued_by_seat_id');
    }
}
