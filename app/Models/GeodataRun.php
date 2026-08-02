<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One geodata ETL run under the pull engine. The row carries the pipeline
 * phase (which item KIND is claimable) plus the DB halt flag + pg-crash
 * breaker; per-item state lives on GeodataItem (the durable resume cursor +
 * review list). Mirrors AutoscaleRun.
 */
class GeodataRun extends Model
{
    use HasUuids;

    protected $table = 'geodata_runs';

    protected $keyType = 'string';

    public $incrementing = false;

    /** Pipeline phases, in order — the pump advances through these. */
    public const PHASES = [
        'enumerating', 'boundaries', 'resolving', 'rasters',
        'attribution', 'finalizing', 'scanning', 'done',
    ];

    /** The single item kind claimable in each phase (barriers are one-item pools). */
    public const PHASE_KIND = [
        'enumerating' => 'manifest',
        'boundaries'  => 'boundary_iso',
        'resolving'   => 'resolve_global',
        'rasters'     => 'raster_iso',
        'attribution' => 'attribution_pair',
        'finalizing'  => 'finalize_global',
        'scanning'    => 'acceptance_scan',
    ];

    protected $fillable = [
        'id', 'status', 'phase', 'data_root', 'options',
        'items_total', 'items_done', 'items_review', 'items_failed',
        'halt_requested_at', 'paused_until', 'pg_fingerprint',
        'phase_timestamps', 'initiator_user_id', 'last_error', 'finished_at',
    ];

    protected $casts = [
        'options'           => 'array',
        'phase_timestamps'  => 'array',
        'items_total'       => 'integer',
        'items_done'        => 'integer',
        'items_review'      => 'integer',
        'items_failed'      => 'integer',
        'halt_requested_at' => 'datetime',
        'paused_until'      => 'datetime',
        'finished_at'       => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(GeodataItem::class, 'run_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['running', 'halted'], true);
    }

    /** The item kind claimable in the current phase (null in a terminal phase). */
    public function currentKind(): ?string
    {
        return self::PHASE_KIND[$this->phase] ?? null;
    }

    /** DB-backed operator halt — workers stop at their next claim boundary. */
    public function haltRequested(): bool
    {
        return $this->halt_requested_at !== null;
    }

    /** pg-crash breaker: claims pause while this is in the future. */
    public function isPaused(): bool
    {
        return $this->paused_until !== null && $this->paused_until->isFuture();
    }

    /** The run to resume, if any: newest non-terminal run. */
    public static function unfinished(): ?self
    {
        return static::query()
            ->whereIn('status', ['running', 'halted'])
            ->orderByDesc('created_at')
            ->first();
    }
}
