<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One claimable geodata work unit: an ISO's boundary chain, an ISO's raster
 * load, one (iso, adm_level) attribution pair, or a barrier singleton
 * (manifest / resolve_global / finalize_global / acceptance_scan). Mirrors
 * AutoscaleItem/AutoscaleScope. status flows pending → running →
 * done|review|failed; a failed/review item never sinks the run (the phase
 * barrier opens when the pool has zero pending+running).
 */
class GeodataItem extends Model
{
    use HasUuids;

    protected $table = 'geodata_items';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'run_id', 'kind', 'iso_code', 'adm_level', 'status',
        'claim_token', 'reason', 'position', 'est_cost', 'dry_run',
        'metrics', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'adm_level'   => 'integer',
        'position'    => 'integer',
        'est_cost'    => 'integer',
        'dry_run'     => 'boolean',
        'metrics'     => 'array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(GeodataRun::class, 'run_id');
    }
}
