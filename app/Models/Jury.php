<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article IV §4 — "a jury of their peers". One jury per criminal case (when
 * entitled + not waived). The draw seed is published to the audit chain —
 * anyone can verify the draw. No fee field exists anywhere on the jury path
 * (Art. II §8 — jury service may never carry a fee; the no-fee shield is
 * structural).
 */
class Jury extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_DRAWING = 'drawing';

    public const STATUS_VOIR_DIRE = 'voir_dire';

    public const STATUS_EMPANELED = 'empaneled';

    public const STATUS_DELIBERATING = 'deliberating';

    public const STATUS_DISCHARGED = 'discharged';

    protected $fillable = [
        'id',
        'case_id',
        'selection_order_id',
        'pool_size',
        'eligible_jurisdiction_id',
        'seats',
        'alternates',
        'draw_seed',
        'report_on',
        'status',
    ];

    protected $casts = [
        'pool_size' => 'integer',
        'seats' => 'integer',
        'alternates' => 'integer',
        'report_on' => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(CourtCase::class, 'case_id');
    }

    public function eligibleJurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'eligible_jurisdiction_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(JuryMember::class, 'jury_id');
    }
}
