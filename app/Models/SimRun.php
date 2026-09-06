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
        'civics',
        'training',
        'stipends',
        'verifying',
        'done',
    ];

    /** Which item kinds belong to each phase — the claim ladder's rung map. */
    public const PHASE_KINDS = [
        // Empty slot (W7 item 3): enumeration happens in SimStartCommand, not a
        // stage — no kind is declared, so nothing throws "no stage wired" and
        // advancePhase treats it as drained and advances straight through.
        'enumerating' => [],
        // The research lane. profile_research is a LIVE network kind: it carries
        // the longer reclaim grace (SimClaims::NETWORK_KINDS) so a rate-limited,
        // paid research call is never duplicated by an early reclaim. No stage
        // consumes it yet and a run starts at `cohorts`, so production never
        // enters this phase; the kind stays declared so the grace and its pin
        // (SimPullEnginePinTest) hold when the research layer lands. With no such
        // items present, advancePhase still advances (kinds non-empty, pool
        // empty → drained). profile_inherit was dropped — no stage, no pin.
        'profiling' => ['profile_research'],
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
        // CENSUS-FLAVORED civics (operator ruling 2026-08-08, rubric
        // sim-org-bill-rates = B): per-capita orgs (parties, nonprofits,
        // businesses) + bills per session — real rates, sampled rows, true
        // counts in metrics. One item per jurisdiction whose bench item
        // settled.
        'civics' => ['civics_scope'],
        // TRAINING (W7 item 7, ruling edu-arming A). AFTER the content stages so
        // arming the training gate never blocks their gated forms. The catalog
        // is published once at the transition (SimPumpCommand::advancePhase);
        // TrainingStage then pre-trains each jurisdiction's seated holders. One
        // item per jurisdiction whose seating landed — bounded, resumable.
        'training' => ['training_scope'],
        // THE MONEY PLANE (W7 item 8). One stipend item per jurisdiction that
        // has residents; StipendStage runs the real F-TRE-004 over that
        // jurisdiction's roster — bounded per scope, so no planet-wide
        // transaction. Wallets were opened in the identities phase.
        'stipends' => ['stipend_scope'],
        // Empty slot (W7 item 3): a real acceptance scan is future work. No kind
        // declared, so advancePhase advances straight through to done.
        'verifying' => [],
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
