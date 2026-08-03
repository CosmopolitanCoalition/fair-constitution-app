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

    /** The single item kind claimable in each phase (barriers are one-item pools).
     *  Governs phase-DRAIN (phaseDrained() gates on this kind alone) — untouched
     *  by PHASE_FALLTHROUGH below. Mirrors claims.py PHASE_KIND. */
    public const PHASE_KIND = [
        'enumerating' => 'manifest',
        'boundaries'  => 'boundary_iso',
        'resolving'   => 'resolve_global',
        'rasters'     => 'raster_iso',
        'attribution' => 'attribution_pair',
        'finalizing'  => 'finalize_global',
        'scanning'    => 'acceptance_scan',
    ];

    /** Overlap ingest (2026-08-02, INGEST_OVERLAP_PLAN.md, adopted): kinds a
     *  lane may also claim when the phase's own kind has nothing pending —
     *  boundaries and rasters are independent fan-outs serialized only by
     *  phase convention. Mirrors claims.py PHASE_FALLTHROUGH exactly; keep
     *  both in lockstep. Just 'raster_iso' — GeodataClaims::next() already
     *  expands a kind to its range family. */
    public const PHASE_FALLTHROUGH = [
        'boundaries' => ['raster_iso'],
        // Early attribution (2026-08-03): pairs are enumerated at the START
        // of the resolve barrier, so idle lanes attribute while the
        // per-country ladders grind. Mirrors claims.py PHASE_FALLTHROUGH.
        'resolving'  => ['raster_iso', 'attribution_pair'],
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
