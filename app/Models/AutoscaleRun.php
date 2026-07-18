<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One full-scale autoscale run: map-data acceptance → size every legislature
 * → district-map every one. The row carries phase status plus the
 * denormalised counters the Step-3 dashboard polls; per-legislature state
 * lives on AutoscaleItem (the durable resume cursor + review list).
 */
class AutoscaleRun extends Model
{
    use HasUuids;

    public const STATUSES = ['queued', 'sizing', 'mapping', 'done', 'halted', 'failed'];

    public const HALT_CACHE_KEY = 'autoscale.halt';

    protected $table = 'autoscale_runs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'status',
        'adm_max',
        'initiator_user_id',
        'template',
        'sized_parents',
        'sized_leaves',
        'singles_total',
        'singles_done',
        'sweeps_total',
        'sweeps_done',
        'review_count',
        'last_error',
        'sizing_started_at',
        'mapping_started_at',
        'finished_at',
    ];

    protected $casts = [
        'adm_max'            => 'integer',
        'sized_parents'      => 'integer',
        'sized_leaves'       => 'integer',
        'singles_total'      => 'integer',
        'singles_done'       => 'integer',
        'sweeps_total'       => 'integer',
        'sweeps_done'        => 'integer',
        'review_count'       => 'integer',
        'sizing_started_at'  => 'datetime',
        'mapping_started_at' => 'datetime',
        'finished_at'        => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(AutoscaleItem::class, 'run_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'sizing', 'mapping'], true);
    }

    /** The run to resume, if any: newest non-terminal run. */
    public static function unfinished(): ?self
    {
        return static::query()
            ->whereIn('status', ['queued', 'sizing', 'mapping', 'halted'])
            ->orderByDesc('created_at')
            ->first();
    }
}
