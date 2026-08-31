<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One drawing unit of one map (THE LEDGER SINGLE HOME, 2026-08-31).
 * Facts: (legislature_id, scope_jurisdiction_id) identity, seat_budget,
 * walk_position, depth, parent_jurisdiction_id, area_tier. Work state:
 * status, claim_token, retry_count, timings. The surrogate `id` is the
 * claim + heartbeat key; `updated_at` is the reclaim clock.
 */
class LedgerScope extends Model
{
    protected $table = 'apportionment_ledger_scopes';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
        'step_timings' => 'array',
    ];
}
