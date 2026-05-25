<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single seat on a Judiciary. Status starts vacant; the elections
 * engine fills user_id and term dates when judges are seated.
 */
class JudicialSeat extends Model
{
    use SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'judiciary_id',
        'user_id',
        'seat_number',
        'term_starts_on',
        'term_ends_on',
        'status',
    ];

    protected $casts = [
        'seat_number'    => 'integer',
        'term_starts_on' => 'date',
        'term_ends_on'   => 'date',
    ];

    public function judiciary(): BelongsTo
    {
        return $this->belongsTo(Judiciary::class, 'judiciary_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
