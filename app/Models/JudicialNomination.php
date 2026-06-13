<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single judicial nomination (PHASE_E_DESIGN_judiciary §A E-4). Both
 * Art. IV §2 paths (equal-per-constituent, judicial committee) produce a
 * set of nominees, each flowing into its OWN F-LEG-021 consent vote. This
 * row makes equal-per-constituent auditable (count GROUP BY
 * nominating_jurisdiction_id must be uniform — the §B.2 invariant).
 */
class JudicialNomination extends Model
{
    use HasUuids, SoftDeletes;

    public const MODE_CONSTITUENT = 'constituent';

    public const MODE_COMMITTEE = 'committee';

    public const STATUS_NOMINATED = 'nominated';

    public const STATUS_CONSENTED = 'consented';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'id',
        'judiciary_id',
        'seat_id',
        'mode',
        'nominating_jurisdiction_id',
        'nominee_user_id',
        'appointment_id',
        'dossier_record_id',
        'status',
    ];

    public function judiciary(): BelongsTo
    {
        return $this->belongsTo(Judiciary::class, 'judiciary_id');
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(JudicialSeat::class, 'seat_id');
    }

    public function nominatingJurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'nominating_jurisdiction_id');
    }

    public function nominee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nominee_user_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }
}
