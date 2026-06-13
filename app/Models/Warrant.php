<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article II §8 (F-JDG-010) — arrest/search/seizure authorization. The Arrest
 * Warrant Requirement: "no arrest except with a warrant from a court
 * establishing the reason for the arrest and the maximum duration an
 * Individual can be held." `stated_reason` is NOT NULL/non-empty and (for an
 * arrest) `max_hold_duration_hours` > 0 — the two constitutional facts are
 * columns, so a warrant missing either is structurally unfilable.
 */
class Warrant extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_ARREST = 'arrest';

    public const KIND_SEARCH = 'search';

    public const KIND_SEIZURE = 'seizure';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_QUASHED = 'quashed';

    protected $fillable = [
        'id',
        'case_id',
        'issued_by_seat_id',
        'kind',
        'stated_reason',
        'max_hold_duration_hours',
        'subject_user_id',
        'status',
        'issued_at',
        'executed_at',
        'expires_at',
        'record_id',
    ];

    protected $casts = [
        'max_hold_duration_hours' => 'integer',
        'issued_at' => 'datetime',
        'executed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(CourtCase::class, 'case_id');
    }

    public function issuedBySeat(): BelongsTo
    {
        return $this->belongsTo(JudicialSeat::class, 'issued_by_seat_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }
}
