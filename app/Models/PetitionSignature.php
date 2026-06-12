<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One petition signature (F-IND-010). Revocable (`revoked_at`) while the
 * petition is gathering; the partial unique `petition_signatures_one_live`
 * guarantees one LIVE signature per user per petition (a revoked row stays
 * as history — re-signing inserts a fresh row).
 *
 * `association_id` records the residency_confirmations row live at
 * signing (provenance for the F-ELB-005 point-in-time audit).
 */
class PetitionSignature extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'petition_id',
        'user_id',
        'association_id',
        'signed_at',
        'revoked_at',
    ];

    protected $casts = [
        'signed_at'  => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function petition(): BelongsTo
    {
        return $this->belongsTo(Petition::class, 'petition_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeLive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
