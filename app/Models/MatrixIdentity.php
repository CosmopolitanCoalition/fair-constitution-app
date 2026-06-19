<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase K-3 — the bridge between a CGA user and their Matrix identity. The localpart is the
 * pseudonymous social_profiles.handle; Matrix only ever sees @localpart:domain + a displayname +
 * a device key. There is NO name/email/residency column here, EVER — de-anonymization is
 * judicial-only (M-1). One live identity per user; the localpart is case-insensitively unique.
 */
class MatrixIdentity extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'user_id',
        'matrix_localpart',
        'matrix_user_id',
        'device_master_key',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
