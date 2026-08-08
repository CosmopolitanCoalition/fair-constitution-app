<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One simulated-world populate run.
 *
 * Mirrors `AutoscaleRun`: the run row is the engine's whole state machine, so
 * halt / resume / requeue / revert are all plain SQL against it and the UI is
 * trivial. Nothing lives in Redis and nothing lives in a file.
 *
 * @property string $status  queued|running|done|halted|failed
 * @property string $phase   enumerating→profiling→cohorts→identities→elections→counting→seating→verifying→done
 */
class SimRun extends Model
{
    use HasUuids;

    protected $table = 'sim_runs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'options' => 'array',
        'phase_timings' => 'array',
        'halt_requested_at' => 'datetime',
        'paused_until' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** The phase DAG, in order. The pump advances; workers never do. */
    public const PHASES = [
        'enumerating',
        'profiling',
        'cohorts',
        'identities',
        'elections',
        'counting',
        'seating',
        'governance',
        'judiciary',
        'verifying',
        'done',
    ];

    /** Which item kinds belong to each phase — the claim ladder's rung map. */
    public const PHASE_KINDS = [
        'enumerating' => ['manifest'],
        'profiling' => ['profile_research', 'profile_inherit'],
        'cohorts' => ['cohort_scope'],
        'identities' => ['identity_batch'],
        'elections' => ['election_scope'],
        // One item per ELECTION, not per race. Counting batches its races so a
        // single Merkle-rooted audit entry attests to many counts (D3): the
        // chain is a serial writer, and one entry per race would put ~16 hours
        // of pure lock time in front of a planetary run. An election is also
        // the unit certification acts on, so the two stages line up.
        'counting' => ['count_election'],
        'seating' => ['seat_scope'],
        // The growth dial (§5 stages 4-5): a seated chamber grows its committees
        // toward K(S) and its departments toward D(P) THROUGH THE REAL FORMS —
        // GovernanceStage files F-LEG-009/014/016, never a direct row. One item
        // per jurisdiction whose seating landed.
        'governance' => ['governance_scope'],
        // The bench (Art. IV, operator 2026-08-08 — the courtroom gap): once a
        // place's growth dial has run, JudiciaryStage files the real F-LEG-017
        // creation + per-seat F-LEG-021 constituent nominations, so the
        // constituent-shaped judge pools appear through the forms. One item
        // per jurisdiction whose governance item settled.
        'judiciary' => ['judiciary_scope'],
        'verifying' => ['acceptance_scan'],
        'done' => [],
    ];

    public function haltRequested(): bool
    {
        return $this->halt_requested_at !== null;
    }

    public function isPaused(): bool
    {
        return $this->paused_until !== null && $this->paused_until->isFuture();
    }

    /** Claims are only handed out on a live, unhalted, unpaused run. */
    public function isClaimable(): bool
    {
        return $this->status === 'running' && ! $this->haltRequested() && ! $this->isPaused();
    }

    /** @return list<string> */
    public function currentKinds(): array
    {
        return self::PHASE_KINDS[$this->phase] ?? [];
    }

    public function nextPhase(): ?string
    {
        $i = array_search($this->phase, self::PHASES, true);

        if ($i === false || $i + 1 >= count(self::PHASES)) {
            return null;
        }

        return self::PHASES[$i + 1];
    }
}
