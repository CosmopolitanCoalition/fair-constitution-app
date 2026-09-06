<?php

namespace App\Services\Demo;

use App\Jobs\RunSimStartJob;
use App\Models\SimRun;
use App\Services\AuditService;
use App\Support\GameMode;
use App\Support\InstanceClass;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * DRIVE the simulated-world populate run — start, halt, resume — from ONE code
 * path that both the /simworld console and the `sim:*` CLI verbs call.
 *
 * WHY A SHARED SERVICE AND NOT TWO CONTROLLERS. UI↔CLI parity is the standing
 * order (ruling 10): a terminal-only capability needs a UI, a UI-only one needs
 * a CLI, and — the part that is easy to get wrong — the GUARDS TRAVEL WITH THE
 * PAIR. If the console wrote `halt_requested_at` directly and the CLI called a
 * service, the two doors could drift: one could forget the synthetic-data guard,
 * or the single-run law, and nobody would notice until a real world got driven.
 * So the guard, the single-run law and the audit mark live HERE, once, and every
 * door is a thin caller. `sim:start` (the CLI start half) already existed and is
 * untouched; this service is the UI's async wrapper around it plus the halt and
 * resume verbs that had no door on EITHER surface before.
 *
 * THE GUARD IS THE SAME RULE AS every demo command's `GuardsSyntheticData`
 * trait: synthetic-world control is lawful only where the world declared itself
 * not-real (`instance_class = scale_demo` OR `game_mode = sandbox`). The boolean
 * is identical; only the rendering differs — the trait prints a console block,
 * this returns a sentence the console shows VERBATIM (the D2 refusal-sentence
 * contract). Uncertainty fails CLOSED: a DB that will not answer resolves to
 * production and refuses.
 *
 * HALT / RESUME ARE INSTANT AND SYNCHRONOUS. Halt sets the DB flag the pump
 * already honours (`halt_requested_at`); the pump parks workers at their next
 * claim boundary within the minute, and the console's existing `run.halt_requested`
 * banner reflects it on the next 2 s poll. Resume clears the flag; the pump flips
 * halted→running. Neither needs a job — they are one column write each.
 *
 * START IS ASYNC. Enumeration is the engine's single biggest write (~907k rows,
 * THE ETL RULE) and cannot run inside a web request, so the console queues
 * `RunSimStartJob`, which runs the REAL `sim:start` command on the `sim` queue.
 * The command re-checks the guard in the child, so even a mis-queued start on a
 * real world exits before touching anything — the service's pre-check is the
 * fast, honest refusal; the command is the backstop truth.
 */
class SimRunControl
{
    /** A short-lived marker so the console can show a start the instant it is
     *  requested, before the queued command has minted the run row. */
    public const CONTROL_KEY = 'sim:control';

    public const CONTROL_TTL = 3600;

    public function __construct(private readonly AuditService $audit) {}

    /** The world has declared itself synthetic-safe — the one boolean the guard rests on. */
    public function syntheticSafe(): bool
    {
        return InstanceClass::isScaleDemo() || GameMode::isSandbox();
    }

    /**
     * Null when control is allowed; otherwise the refusal sentence, rendered
     * VERBATIM by the console (the D2 contract) and printed by the CLI verbs.
     */
    public function refusalReason(): ?string
    {
        if ($this->syntheticSafe()) {
            return null;
        }

        return 'This world has not declared itself synthetic-safe, so the populate '
            .'engine will not run here. Driving a run mints residents, governments and '
            .'civic records that no human consented to — lawful only on a world founded '
            .'as an illustration (instance_class = scale_demo) or a playground '
            .'(game_mode = sandbox). Both are chosen at founding, never as flags. On a '
            .'real world this refusal is the rail working.';
    }

    /** The one unfinished run, oldest-first — the same one the pump and console act on. */
    public function activeRun(): ?SimRun
    {
        return SimRun::query()
            ->whereIn('status', ['queued', 'running', 'halted'])
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Queue a populate run. Refuses on a non-demo world and refuses a second
     * concurrent run (the single-run law — one run holds the engine), exactly as
     * `sim:start` does, because it IS `sim:start` that ultimately runs.
     *
     * @param  array<string,mixed>  $options  world-version, turnout, adm-max, limit, resume
     * @return array{ok: bool, reason: ?string, queued?: bool, resumed?: bool}
     */
    public function start(array $options, ?string $actor): array
    {
        if (($reason = $this->refusalReason()) !== null) {
            return ['ok' => false, 'reason' => $reason];
        }

        $resume = (bool) ($options['resume'] ?? false);
        $active = $this->activeRun();

        if ($active !== null && ! $resume) {
            return [
                'ok' => false,
                'reason' => 'A populate run already holds the engine (status: '.$active->status.'). '
                    .'One run at a time — halt it first, or resume it, rather than starting a second.',
            ];
        }

        if ($active === null && $resume) {
            return ['ok' => false, 'reason' => 'Nothing to resume — there is no unfinished run.'];
        }

        // The marker the console shows immediately: the queued command mints the
        // run row a moment later, and the live bars take over from there.
        $this->putControl([
            'action' => $resume ? 'resume-enumerate' : 'start',
            'status' => 'queued',
            'by' => $actor,
            'at' => now()->toIso8601String(),
            'note' => $resume
                ? 'Resuming the unfinished run — re-enumerating cleanly (a redo is a no-op).'
                : 'Enumerating the worklist — the bars come alive within the minute.',
        ]);

        RunSimStartJob::dispatch($this->cliOptions($options), $actor);

        // The operator's ACT of starting is itself audited, distinct from the
        // command's own `sim.enumerated` — every dev/operator control leaves a mark.
        $this->audit->append(
            module: 'simworld',
            event: $resume ? 'sim.resume_requested' : 'sim.start_requested',
            payload: [
                'options' => $this->cliOptions($options),
                'actor' => $actor,
                'dev_control' => true,
            ],
            ref: 'WF-SYS-04',
        );

        return ['ok' => true, 'reason' => null, 'queued' => true, 'resumed' => $resume];
    }

    /**
     * Halt the active run — set the flag the pump honours. The workers stop at
     * their next claim boundary, never mid-item, and nothing is abandoned.
     *
     * @return array{ok: bool, reason: ?string, status?: string}
     */
    public function halt(?string $actor): array
    {
        if (($reason = $this->refusalReason()) !== null) {
            return ['ok' => false, 'reason' => $reason];
        }

        $run = $this->activeRun();

        if ($run === null) {
            return ['ok' => false, 'reason' => 'There is no active run to halt.'];
        }

        if ($run->haltRequested()) {
            return ['ok' => true, 'reason' => null, 'status' => $run->status]; // idempotent
        }

        $run->forceFill(['halt_requested_at' => now()])->save();

        $this->putControl([
            'action' => 'halt',
            'status' => 'requested',
            'by' => $actor,
            'at' => now()->toIso8601String(),
            'note' => 'Halt requested — workers stop at the next claim boundary. Resume picks up where it stopped.',
        ]);

        $this->audit->append(
            module: 'simworld',
            event: 'sim.halted',
            payload: ['run_id' => (string) $run->id, 'actor' => $actor, 'dev_control' => true],
            ref: 'WF-SYS-04',
        );

        return ['ok' => true, 'reason' => null, 'status' => $run->status];
    }

    /**
     * Resume a halted run — clear the flag. The pump flips halted→running on its
     * next tick and re-seeds the worker pool.
     *
     * @return array{ok: bool, reason: ?string, status?: string}
     */
    public function resume(?string $actor): array
    {
        if (($reason = $this->refusalReason()) !== null) {
            return ['ok' => false, 'reason' => $reason];
        }

        $run = $this->activeRun();

        if ($run === null) {
            return ['ok' => false, 'reason' => 'There is no run to resume.'];
        }

        if (! $run->haltRequested()) {
            return ['ok' => true, 'reason' => null, 'status' => $run->status]; // idempotent
        }

        $run->forceFill(['halt_requested_at' => null])->save();

        $this->putControl([
            'action' => 'resume',
            'status' => 'cleared',
            'by' => $actor,
            'at' => now()->toIso8601String(),
            'note' => 'Resumed — the pump re-seeds workers within the minute.',
        ]);

        $this->audit->append(
            module: 'simworld',
            event: 'sim.resumed',
            payload: ['run_id' => (string) $run->id, 'actor' => $actor, 'dev_control' => true],
            ref: 'WF-SYS-04',
        );

        return ['ok' => true, 'reason' => null, 'status' => $run->status];
    }

    /**
     * REVERT the run's engine bookkeeping — the SAFE subset (W7 item 3B).
     *
     * This abandons THIS run so a fresh `sim:start` re-enumerates cleanly: it
     * deletes the run's worklist (`sim_items`) and its worker leases in bounded
     * committed chunks (THE ETL RULE), then the run row. It does NOT touch the
     * world the run produced — cohorts, people, seated members, civic records —
     * because that teardown cascades ~40 RESTRICT foreign keys and its scope is
     * an operator decision (rubric sim-revert-scope), not a default. The Step 4
     * institutions and every map stay untouched here exactly as they do on the
     * Step 4 rollback the operator narrowed to "institutions".
     *
     * THE ESCAPE-HATCH LAW: a recovery control is never blocked by the state it
     * recovers from. It refuses only to protect a LIVE run (unless --force), and
     * even then it SEIZES — culling stale leases first — rather than waiting.
     *
     * @return array{ok:bool, error:?string, deleted:array<string,int>}
     */
    public function revert(bool $force = false): array
    {
        if (($reason = $this->refusalReason()) !== null) {
            return ['ok' => false, 'error' => $reason, 'deleted' => []];
        }

        $run = SimRun::query()->orderByDesc('created_at')->first();

        if ($run === null) {
            return ['ok' => false, 'error' => 'No populate run to roll back.', 'deleted' => []];
        }

        if (in_array($run->status, ['queued', 'running'], true) && ! $force) {
            return [
                'ok' => false,
                'error' => "Run {$run->id} is {$run->status} — halt it first, then roll back.",
                'deleted' => [],
            ];
        }

        // SEIZE, do not wait: cull leases the reaper's horizon has passed so a
        // dead worker never keeps the door shut. A genuinely live lane (force)
        // is culled too — the operator asked for the run to end.
        $cutoff = $force ? now() : now()->subMinutes(2);
        $leasesCulled = (int) DB::table('sim_worker_leases')
            ->where('run_id', $run->id)
            ->where('last_seen_at', '<=', $cutoff)
            ->delete();

        if (! $force) {
            $liveLanes = (int) DB::table('sim_worker_leases')
                ->where('run_id', $run->id)
                ->where('last_seen_at', '>', now()->subMinutes(2))
                ->count();
            if ($liveLanes > 0) {
                return [
                    'ok' => false,
                    'error' => "{$liveLanes} lane(s) still live — wait for them to park (≤2 min), or force.",
                    'deleted' => ['sim_worker_leases' => $leasesCulled],
                ];
            }
        } else {
            $leasesCulled += (int) DB::table('sim_worker_leases')->where('run_id', $run->id)->delete();
        }

        // Delete the worklist in bounded, committed chunks — a kill mid-revert
        // costs one chunk, never the pass (THE ETL RULE).
        $itemsDeleted = 0;
        do {
            $n = (int) DB::table('sim_items')
                ->where('run_id', $run->id)
                ->whereIn('id', function ($q) use ($run) {
                    $q->select('id')->from('sim_items')
                        ->where('run_id', $run->id)
                        ->limit(self::REVERT_CHUNK);
                })
                ->delete();
            $itemsDeleted += $n;
        } while ($n > 0);

        DB::table('sim_runs')->where('id', $run->id)->delete();

        $this->putControl([
            'action' => 'revert',
            'status' => 'done',
            'at' => now()->toIso8601String(),
            'note' => 'Run cleared — worklist and leases removed. A fresh start re-enumerates. '
                .'The world it produced (cohorts, people, seats) was left in place.',
        ]);

        $this->audit->append(
            module: 'simworld',
            event: 'sim.reverted',
            payload: [
                'run_id' => (string) $run->id,
                'sim_items' => $itemsDeleted,
                'sim_worker_leases' => $leasesCulled,
                'forced' => $force,
                'dev_control' => true,
            ],
            ref: 'WF-SYS-04',
        );

        return [
            'ok' => true,
            'error' => null,
            'deleted' => [
                'sim_items' => $itemsDeleted,
                'sim_worker_leases' => $leasesCulled,
                'sim_runs' => 1,
            ],
        ];
    }

    /** THE ETL RULE chunk size for the revert worklist delete. */
    private const REVERT_CHUNK = 25000;

    /** The last control action, for the console to echo (null once expired). */
    public function control(): ?array
    {
        return Cache::get(self::CONTROL_KEY);
    }

    private function putControl(array $state): void
    {
        Cache::put(self::CONTROL_KEY, $state, self::CONTROL_TTL);
    }

    /**
     * Normalise UI/service options into the `sim:start` option array the command
     * and the job both consume. Only the options the command declares are passed
     * through; everything else is dropped rather than smuggled onto the child.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function cliOptions(array $options): array
    {
        $out = [];

        if (isset($options['world-version'])) {
            $out['--world-version'] = (int) $options['world-version'];
        }
        if (isset($options['turnout'])) {
            $out['--turnout'] = max(0, min(100, (int) $options['turnout']));
        }
        if (isset($options['adm-max'])) {
            $out['--adm-max'] = (int) $options['adm-max'];
        }
        if (isset($options['limit']) && $options['limit'] !== null && $options['limit'] !== '') {
            $out['--limit'] = max(1, (int) $options['limit']);
        }
        if (! empty($options['resume'])) {
            $out['--resume'] = true;
        }

        return $out;
    }
}
