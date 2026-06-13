<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article IV §4 — the bench SAT to one case: "at least three (3), Odd in
 * number, and scale with the severity… Constitutional Questions of
 * significant importance are heard by the entire court." `size` is computed
 * by the pure PanelSizing::sizeFor; the DB belt (size >= 3 AND size % 2 = 1)
 * is the floor.
 */
class Panel extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_DRAWING = 'drawing';

    public const STATUS_SCREENING = 'screening';

    public const STATUS_SEATED = 'seated';

    public const STATUS_DISSOLVED = 'dissolved';

    protected $fillable = [
        'id',
        'case_id',
        'judiciary_id',
        'size',
        'is_en_banc',
        'severity_basis',
        'presiding_judge_seat_id',
        'draw_seed',
        'status',
    ];

    protected $casts = [
        'size' => 'integer',
        'is_en_banc' => 'boolean',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(CourtCase::class, 'case_id');
    }

    public function judiciary(): BelongsTo
    {
        return $this->belongsTo(Judiciary::class, 'judiciary_id');
    }

    public function presidingSeat(): BelongsTo
    {
        return $this->belongsTo(JudicialSeat::class, 'presiding_judge_seat_id');
    }

    public function judges(): HasMany
    {
        return $this->hasMany(PanelJudge::class, 'panel_id');
    }
}
