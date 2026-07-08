<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One applied geodata repair (the repair ledger). `params` carries both the
 * operator's inputs and the FULL prior state of every touched row, so
 * GeodataRemediationService::revert() can restore it; `result` records what
 * the repair actually changed. Repairs are only legal inside the repair
 * window (setup incomplete + map data not yet accepted).
 *
 * HasUuids is load-bearing (gen_random_uuid() DB default) — see the
 * InstanceSettings class note.
 */
class GeodataRepair extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const ACTIONS = [
        'reparent',
        'synthesize_anchor',
        'merge_chain',
        'prune',
        'recompute_population',
    ];

    protected $table = 'geodata_repairs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'flag_id',
        'action',
        'target_slug',
        'target_geoboundaries_id',
        'params',
        'result',
        'applied_by',
        'applied_at',
        'reverted_at',
    ];

    protected $casts = [
        'params'      => 'array',
        'result'      => 'array',
        'applied_at'  => 'datetime',
        'reverted_at' => 'datetime',
    ];

    public function flag(): BelongsTo
    {
        return $this->belongsTo(GeodataFlag::class, 'flag_id');
    }
}
