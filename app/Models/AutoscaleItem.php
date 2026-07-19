<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One legislature inside an autoscale run.
 *
 * kind `sweep`  — has children: a real mixed-autoseed mass sweep draws its map.
 * kind `single` — childless leaf: one at-large district, created set-based.
 *
 * `drift` (seats_seated − seats_expected) is INFORMATIONAL per the seating
 * law: no total-forcing, ever. A drifted-but-complete map still activates.
 */
class AutoscaleItem extends Model
{
    use HasUuids;

    public const KINDS = ['sweep', 'single'];

    // 'queued' and 'halted' are legacy (pre-pull-engine) values — still read,
    // never written. 'assessing' = all scopes closed, completeness assessment
    // + activation in flight (claimed atomically by exactly one worker).
    public const STATUSES = ['pending', 'queued', 'running', 'assessing', 'done', 'review', 'halted', 'failed'];

    protected $table = 'autoscale_items';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'run_id',
        'legislature_id',
        'jurisdiction_id',
        'adm_level',
        'kind',
        'status',
        'position',
        'seats_expected',
        'seats_seated',
        'drift',
        'reason',
        'started_at',
        'finished_at',
        'map_id',
        'child_count',
        'claim_token',
    ];

    protected $casts = [
        'adm_level'      => 'integer',
        'position'       => 'integer',
        'seats_expected' => 'integer',
        'seats_seated'   => 'integer',
        'drift'          => 'integer',
        'child_count'    => 'integer',
        'started_at'     => 'datetime',
        'finished_at'    => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutoscaleRun::class, 'run_id');
    }

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }
}
