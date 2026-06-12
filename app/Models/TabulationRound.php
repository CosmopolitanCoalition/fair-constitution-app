<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One STV round (B-8): elect or eliminate, with the Gregory-fractional
 * `transfer` payload mirroring the mockup STV_DATA.display[] contract
 * exactly ({kind: 'surplus'|'elimination', value, to: [[candidacy_id,
 * votes]], exhausted}) so Results.vue lifts straight from results.html.
 */
class TabulationRound extends Model
{
    use HasUuids;

    public const ACTION_ELECT     = 'elect';
    public const ACTION_ELIMINATE = 'eliminate';

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'tabulation_id',
        'round_no',
        'action',
        'candidacy_id',
        'transfer',
        'tallies',
        'created_at',
    ];

    protected $casts = [
        'round_no'   => 'integer',
        'transfer'   => 'array',
        'tallies'    => 'array',
        'created_at' => 'datetime',
    ];

    public function tabulation(): BelongsTo
    {
        return $this->belongsTo(Tabulation::class, 'tabulation_id');
    }

    public function candidacy(): BelongsTo
    {
        return $this->belongsTo(Candidacy::class, 'candidacy_id');
    }
}
