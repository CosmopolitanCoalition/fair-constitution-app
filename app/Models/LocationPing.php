<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRIVATE location ping — never exposed to other users, never recorded to
 * the audit chain (pings are audited as count-bumps, not coordinates).
 *
 * The Postgres trigger `location_pings_set_geom` fills `geom` from
 * latitude/longitude on insert. PRIVACY RULE (code, not schema): on claim
 * verification, all raw pings for that claim are DELETED — only the
 * claim's `qualifying_days` and the audit entry survive
 * (ResidencyService::verify).
 *
 * No soft deletes — a soft-deleted ping would still be a stored raw
 * location, defeating the purge.
 */
class LocationPing extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'claim_id',
        'latitude',
        'longitude',
        'accuracy_meters',
        'source',
        'pinged_at',
        'is_qualifying',
        'evaluated_at',
    ];

    protected $casts = [
        'latitude'        => 'float',
        'longitude'       => 'float',
        'accuracy_meters' => 'float',
        'pinged_at'       => 'datetime',
        'is_qualifying'   => 'boolean',
        'evaluated_at'    => 'datetime',
    ];

    /** Sources accepted by the schema CHECK constraint. */
    public const SOURCES = ['mobile', 'web', 'manual', 'simulated'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ResidencyClaim::class, 'claim_id');
    }
}
