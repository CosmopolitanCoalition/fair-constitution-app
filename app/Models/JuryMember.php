<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article IV §4 — a summoned/empaneled juror (the juror-view R-22 surface
 * reads this). voir dire removes CONFLICTS ONLY — `excusal_reason` is
 * conflict | hardship, never opinion/demographics/politics. The juror stepper
 * is summoned → screening → cleared/excused → empaneled → discharged.
 *
 * R-22 (Juror) derives from a member in screening_status summoned/screening/
 * cleared/empaneled on a non-discharged jury.
 */
class JuryMember extends Model
{
    use HasUuids, SoftDeletes;

    public const SEAT_JUROR = 'juror';

    public const SEAT_ALTERNATE = 'alternate';

    public const SCREENING_SUMMONED = 'summoned';

    public const SCREENING_SCREENING = 'screening';

    public const SCREENING_CLEARED = 'cleared';

    public const SCREENING_EXCUSED = 'excused';

    public const SCREENING_EMPANELED = 'empaneled';

    public const SCREENING_DISCHARGED = 'discharged';

    public const EXCUSAL_CONFLICT = 'conflict';

    public const EXCUSAL_HARDSHIP = 'hardship';

    /** Screening statuses in which the member is still an active juror (R-22). */
    public const ACTIVE_SCREENING_STATUSES = [
        self::SCREENING_SUMMONED,
        self::SCREENING_SCREENING,
        self::SCREENING_CLEARED,
        self::SCREENING_EMPANELED,
    ];

    protected $fillable = [
        'id',
        'jury_id',
        'user_id',
        'seat_kind',
        'seat_no',
        'screening_status',
        'excusal_reason',
    ];

    protected $casts = [
        'seat_no' => 'integer',
    ];

    public function jury(): BelongsTo
    {
        return $this->belongsTo(Jury::class, 'jury_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
