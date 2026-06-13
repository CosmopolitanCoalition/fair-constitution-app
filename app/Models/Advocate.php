<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article IV §4 (R-21 · F-IND-015) — a registered advocate ("keeps the bar of
 * advocates zealous and competent"). The registration is at the judiciary
 * level, inherited by descendant courts. R-21 derives from a `registered`
 * advocates row. Registration is available to any R-03 (Art. I) — competence
 * qualifications are a property of the bar, not a gate on the client's right.
 */
class Advocate extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'id',
        'user_id',
        'judiciary_id',
        'jurisdiction_id',
        'status',
        'qualifications_note',
        'registered_at',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function judiciary(): BelongsTo
    {
        return $this->belongsTo(Judiciary::class, 'judiciary_id');
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function scopeRegistered($query)
    {
        return $query->where('status', self::STATUS_REGISTERED);
    }
}
