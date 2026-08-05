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

    /** Pipeline phases, in order — the pump advances through these.
     *
     *  The operator's definitive model (2026-08-05):
     *    BOUNDARIES + RASTERS → REVIEW → RESOLVE + ATTRIBUTION → REVIEW → FINALIZE → SCAN
     *  so the two GROUPS are consecutive: INGEST = {boundaries, rasters},
     *  DERIVE = {resolving, attribution}. `rasters` moved BEFORE `resolving`
     *  (it used to sit after it, which let resolve start before rasters were
     *  done). The review fires at the END of each group — see
     *  GeodataPumpCommand::reviewGateHolds. */
    public const PHASES = [
        'enumerating', 'boundaries', 'rasters', 'resolving',
        'attribution', 'finalizing', 'scanning', 'done',
    ];

    /** The single item kind claimable in each phase (barriers are one-item pools).
     *  Governs phase-DRAIN (phaseDrained() gates on this kind alone) — untouched
     *  by PHASE_FALLTHROUGH below. Mirrors claims.py PHASE_KIND. */
    public const PHASE_KIND = [
        'enumerating' => 'manifest',
        'boundaries'  => 'boundary_iso',
        'rasters'     => 'raster_iso',
        'resolving'   => 'resolve_global',
        'attribution' => 'attribution_pair',
        'finalizing'  => 'finalize_global',
        'scanning'    => 'acceptance_scan',
    ];

    /** Kinds a lane may ALSO claim when the phase's own kind has nothing
     *  pending. WITHIN-GROUP overlap only — never across the ingest/derive
     *  boundary (that cross-group leak is what let resolve start before rasters
     *  finished). Mirrors claims.py PHASE_FALLTHROUGH exactly; keep in lockstep.
     *  GeodataClaims::next() expands a kind to its range family. */
    public const PHASE_FALLTHROUGH = [
        // INGEST overlap — rasters during boundaries, boundaries during rasters
        // (the latter so a review-requeued boundary is claimable at `rasters`).
        'boundaries'  => ['raster_iso'],
        'rasters'     => ['boundary_iso'],
        // DERIVE overlap — attribution during resolve, resolve during
        // attribution. raster_iso is GONE from resolving: rasters are the ingest
        // group and are done + review-free before resolving opens.
        'resolving'   => ['attribution_pair'],
        'attribution' => ['resolve_global'],
    ];

    protected $fillable = [
        'id', 'status', 'phase', 'review_pass', 'data_root', 'options',
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
