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
