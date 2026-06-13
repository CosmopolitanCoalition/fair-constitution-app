<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article IV §4 — a seated judge on one panel, with recusal screening. A
 * recused judge is `replaced` and the draw re-runs over the remaining cleared
 * seats; the conflict-screening result attaches to the case record.
 */
class PanelJudge extends Model
{
    use HasUuids, SoftDeletes;

    public const SCREENING_PENDING = 'pending';

    public const SCREENING_CLEARED = 'cleared';

    public const SCREENING_RECUSED = 'recused';

    public const STATUS_DRAWN = 'drawn';

    public const STATUS_SEATED = 'seated';

    public const STATUS_RECUSED = 'recused';

    public const STATUS_REPLACED = 'replaced';

    protected $fillable = [
        'id',
        'panel_id',
        'judicial_seat_id',
        'user_id',
        'is_presiding',
        'screening_result',
        'recusal_reason',
        'status',
    ];

    protected $casts = [
        'is_presiding' => 'boolean',
    ];

    public function panel(): BelongsTo
    {
        return $this->belongsTo(Panel::class, 'panel_id');
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(JudicialSeat::class, 'judicial_seat_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
