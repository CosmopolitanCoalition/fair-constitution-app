<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * THE LEDGER SINGLE HOME (operator ruling 2026-08-31): one row per
 * legislature — the map's FACTS (head, kind, block keys, founding map id,
 * gate verdict) and its WORK STATE (map_status, claim, seated, drift).
 * compute_status is the phase-2 walk state; map_status is the phase-4
 * drawing state. A benchmark reset clears work columns only.
 */
class LedgerHeader extends Model
{
    protected $table = 'apportionment_ledger';

    protected $primaryKey = 'legislature_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'computed_at'         => 'datetime',
        'started_at'          => 'datetime',
        'finished_at'         => 'datetime',
        'priority_at'         => 'datetime',
        'redraw_requested_at' => 'datetime',
    ];
}
