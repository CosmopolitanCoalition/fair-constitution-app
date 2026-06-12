<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One emergency-power renewal (F-LEG-025): a FRESH supermajority vote
 * extending the power from its CURRENT expiry by `extension_days`
 * (1..min(90, resolved max) — Art. II §7 "renewal by supermajority, each
 * ≤ max"). There is no auto-renewal path anywhere — nothing rolls over
 * silently.
 */
class EmergencyPowerRenewal extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'emergency_power_id',
        'vote_id',
        'extension_days',
        'previous_expires_at',
        'new_expires_at',
        'created_at',
    ];

    protected $casts = [
        'extension_days'      => 'integer',
        'previous_expires_at' => 'datetime',
        'new_expires_at'      => 'datetime',
        'created_at'          => 'datetime',
    ];

    public function power(): BelongsTo
    {
        return $this->belongsTo(EmergencyPower::class, 'emergency_power_id');
    }

    public function vote(): BelongsTo
    {
        return $this->belongsTo(ChamberVote::class, 'vote_id');
    }
}
