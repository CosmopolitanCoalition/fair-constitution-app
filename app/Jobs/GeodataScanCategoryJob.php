<?php

namespace App\Jobs;

use App\Models\GeodataFlag;
use App\Services\Geodata\GeodataFlagService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One acceptance-scan detector, run as its own queue job (operator order
 * 2026-08-02: "parallelize this work"). Five categories dispatch in
 * parallel; displaced_geometry chains behind mis_anchored_cluster (the one
 * documented ordering dependency — clusters claim rows displaced excludes).
 *
 * Coordination is the engine's own idiom: each job MERGES its result into
 * the acceptance_scan item's metrics (jsonb ||, atomic), and whichever job
 * completes the sixth category closes the item — no batch tables, no
 * separate orchestrator. Wall time collapses from Σ(detectors) to
 * ~max(detector) + the one chained edge.
 */
class GeodataScanCategoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;
    public int $tries = 2;   // a hard-killed worker must not strand its category

    public function __construct(
        public string $runId,
        public string $category,
        /** Next category, or an ORDERED CHAIN of them (2026-08-05: the four
         *  heavy geometry detectors run strictly one-at-a-time — concurrent
         *  planet-CTEs crash-looped postgres all night; see the dispatcher). */
        public string|array|null $nextCategory = null,
    ) {
        $this->onQueue('long-running');
    }

    /** Run a write with reconnect-retry. THE CRASH-PROOF RECORD (2026-08-05):
     *  when a detector's planet-CTE OOM-killed postgres, the -1/error write
     *  raced the database's recovery window and died too — so the category
     *  looked never-run, and the pump re-dispatched the same crash every 31
     *  minutes, forever. A landed record (even "-1 errored") is what breaks
     *  that loop, so the record write must survive a recovering database:
     *  5 attempts, 10 s apart, fresh connection each time. */
    private static function writeWithRetry(callable $write): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $write();

                return;
            } catch (\Throwable $e) {
                if ($attempt >= 5) {
                    Log::error('Geodata scan record write failed after retries', [
                        'message' => mb_substr($e->getMessage(), 0, 200),
                    ]);

                    return;
                }
                sleep(10);
                try {
                    DB::reconnect();
                } catch (\Throwable) {
                    // recovering — next attempt retries the connect
                }
            }
        }
    }

    /** Parent-proof cat_started stamp — the pump's liveness signal. Same
     *  jsonb form as the cats write (the parent-trap fix): the inner ||
     *  re-inserts the CURRENT cat_started object so concurrent stamps
     *  compose and nothing landed is lost. */
    private static function stampStarted(string $runId, string $category): void
    {
        DB::update(
            "UPDATE geodata_items
                SET metrics = jsonb_set(
                        COALESCE(metrics, '{}'::jsonb)
                          || jsonb_build_object('cat_started', COALESCE(metrics->'cat_started', '{}'::jsonb)),
                        ARRAY['cat_started', ?], to_jsonb(?::float8), true),
                    updated_at = now()
              WHERE run_id = ? AND kind = 'acceptance_scan' AND status = 'running'",
            [$category, microtime(true), $runId],
        );
    }

    public function handle(GeodataFlagService $service): void
    {
        // PHASE GUARD (2026-08-03, operator: "the scan is running WHILE
        // attribution is taking place, which is hilariously broken"). The
        // dispatch is phase-gated, but an in-flight detector keeps running
        // if the phase REWINDS underneath it — a requeue of attribution
        // work does exactly that. Scanning half-written data flags noise,
        // so a detector that finds itself outside the scanning phase exits
        // without recording; the pump re-dispatches when scanning resumes.
        $phase = DB::table('geodata_runs')->where('id', $this->runId)->value('phase');
        if ($phase !== 'scanning') {
            Log::info('Geodata scan category aborted — run left the scanning phase', [
                'run_id' => $this->runId, 'category' => $this->category, 'phase' => $phase,
            ]);

            return;
        }

        // LIVENESS MARKER (2026-08-03, the 4-hour scan thrash): the pump's
        // stale audit keyed on updated_at going quiet for 5 minutes — but a
        // heavy detector is legitimately silent for 10+ while its
        // planet-wide MATERIALIZED CTE grinds, so the audit re-dispatched
        // LIVE detectors forever. Duplicates stacked, their concurrent CTEs
        // OOM-killed postgres backends in a loop, and the scan span 4h17m
        // without landing the heavy four. The pump now re-dispatches a
        // category only when it is absent from BOTH cats and a fresh
        // cat_started marker (30 min, the engine's stale-claim constant).
        self::stampStarted($this->runId, $this->category);

        $flags = null;
        $error = null;
        try {
            // DETECTOR RETRY (2026-08-05, the rescan cascade): one crash left
            // the DB recovering for ~40s and every chained detector behind it
            // fail-fasted a -1 into that window. A connection-class failure
            // now waits out the recovery and RE-RUNS the detector — a crash
            // costs one attempt, never the chain's tail.
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    // MEMORY-BOUND the detector session (the OOM levers):
                    // clamped work_mem spills to disk instead of ballooning a
                    // backend, and ZERO parallel workers keeps it ONE backend
                    // holding geometry transients, not N of them. The heavy
                    // detectors are now pair/iso-chunked service-side
                    // (2026-08-05), so each statement's peak is set by chunk
                    // size, never geography. Session-scoped; RESET below —
                    // horizon workers reuse connections across jobs.
                    DB::statement("SET work_mem = '64MB'");
                    DB::statement("SET max_parallel_workers_per_gather = 0");
                    DB::statement("SET statement_timeout = '1800s'");
                    $counts = $service->scan([$this->category]);
                    $flags  = (int) array_sum($counts);
                    $error  = null;
                    break;
                } catch (\Throwable $e) {
                    $error = mb_substr($e->getMessage(), 0, 300);
                    $connectionClass = str_contains($e->getMessage(), 'SQLSTATE[08')
                        || str_contains($e->getMessage(), 'recovery mode')
                        || str_contains($e->getMessage(), 'server closed the connection');
                    if ($connectionClass && $attempt < 3) {
                        Log::warning('Geodata scan category hit a dead/recovering connection — retrying', [
                            'run_id' => $this->runId, 'category' => $this->category,
                            'attempt' => $attempt,
                        ]);
                        sleep(30);
                        try {
                            DB::reconnect();
                        } catch (\Throwable) {
                            // still recovering — the next attempt reconnects
                        }
                        continue;
                    }
                    Log::error('Geodata scan category errored', [
                        'run_id' => $this->runId, 'category' => $this->category,
                        'message' => $e->getMessage(),
                    ]);
                    break;
                }
            }
        } finally {
            try {
                DB::statement('RESET work_mem');
                DB::statement('RESET statement_timeout');
            } catch (\Throwable) {
                // dead/recovering connection — writeWithRetry reconnects fresh
            }
        }

        // PATH-LEVEL write, never top-level concat (external audit P0,
        // convicted live: jsonb || is a SHALLOW merge — {"cats":{"a":1}} ||
        // {"cats":{"b":2}} = {"cats":{"b":2}}, so six categories would
        // clobber each other, the closer could never see six keys, and the
        // run would park in scanning forever). jsonb_set writes one path;
        // concurrent writers serialize on the row lock and compose.
        //
        // THE PARENT TRAP (2026-08-03, observed live on run 019fc460 — scan
        // parked in 'scanning' with metrics = {"scan_started": ...} and
        // NOTHING else after 90 minutes): jsonb_set's create_missing creates
        // only the FINAL path element, and only when every earlier element
        // already exists. The dispatcher seeds only scan_started — no 'cats'
        // parent — so jsonb_set(metrics, '{cats,x}', ...) RETURNS THE TARGET
        // UNCHANGED. Every detector ran its planet-wide query to completion
        // and recorded nothing; the sixth-lander close condition could never
        // be met. Fix at the WRITE, not by seeding '{"cats":{}}' at the
        // dispatcher with || — that is the shallow-merge clobber above
        // wearing a new hat (a re-dispatch against a live item would wipe
        // landed results). The inner || re-inserts the CURRENT cats object
        // (or empty on first write) so the parent always exists and nothing
        // landed is lost.
        self::writeWithRetry(fn () => DB::update(
            "UPDATE geodata_items
                SET metrics = jsonb_set(
                        COALESCE(metrics, '{}'::jsonb)
                          || jsonb_build_object('cats', COALESCE(metrics->'cats', '{}'::jsonb)),
                        ARRAY['cats', ?], to_jsonb(?::int), true),
                    updated_at = now()
              WHERE run_id = ? AND kind = 'acceptance_scan' AND status = 'running'",
            [$this->category, $error === null ? $flags : -1, $this->runId],
        ));
        if ($error !== null) {
            // Same trap — masked until now only because 'cats' was equally
            // broken (an error write that lands on nothing looks identical
            // to an error write that never happened).
            self::writeWithRetry(fn () => DB::update(
                "UPDATE geodata_items
                    SET metrics = jsonb_set(
                            COALESCE(metrics, '{}'::jsonb)
                              || jsonb_build_object('cat_errors', COALESCE(metrics->'cat_errors', '{}'::jsonb)),
                            ARRAY['cat_errors', ?], to_jsonb(?::text), true),
                        updated_at = now()
                  WHERE run_id = ? AND kind = 'acceptance_scan' AND status = 'running'",
                [$this->category, $error, $this->runId],
            ));
        }

        if ($this->nextCategory !== null && $this->nextCategory !== []) {
            // The chained category counts as dispatched NOW — stamp its
            // start here so the pump never mistakes "queued behind the
            // chain" for "never dispatched" and races a duplicate. A chain
            // ARRAY hops one link per completion (heavies run one-at-a-time).
            $chain = is_array($this->nextCategory) ? $this->nextCategory : [$this->nextCategory];
            $next  = array_shift($chain);
            self::stampStarted($this->runId, $next);
            self::dispatch($this->runId, $next, $chain === [] ? null : $chain);
        }

        // Whoever lands the sixth category closes the item (idempotent:
        // the WHERE status='running' makes the second closer a no-op).
        $row = DB::table('geodata_items')
            ->where('run_id', $this->runId)
            ->where('kind', 'acceptance_scan')
            ->where('status', 'running')
            ->first(['metrics']);
        if ($row === null) {
            return;
        }
        $m = json_decode($row->metrics ?? '{}', true) ?: [];
        $cats = array_keys($m['cats'] ?? []);
        if (count(array_intersect(GeodataFlag::CATEGORIES, $cats))
                < count(GeodataFlag::CATEGORIES)) {
            return;
        }
        // Defense-in-depth on the close: a cats value of -1 IS an error,
        // whatever cat_errors says. The -1 sentinel and the cat_errors row
        // are written by two separate statements; if the second ever
        // regresses (the parent trap masked exactly this for a day), the
        // close must still refuse to stamp 'done' over a failed detector.
        $errors = $m['cat_errors'] ?? [];
        foreach (($m['cats'] ?? []) as $cat => $flags) {
            if ((int) $flags < 0 && ! isset($errors[$cat])) {
                $errors[$cat] = 'detector recorded -1 (errored) with no cat_errors entry';
            }
        }
        // A retried category that SUCCEEDED leaves its attempt-1 error entry
        // behind (run 019fd200: same_space landed 639 flags on attempt 2 yet
        // still closed the scan as review). Only a cat whose RESULT is -1 is
        // an error; stale entries for landed cats drop.
        $errors = array_filter(
            $errors,
            fn ($msg, $cat) => (int) ($m['cats'][$cat] ?? -1) < 0,
            ARRAY_FILTER_USE_BOTH,
        );
        $elapsed = isset($m['scan_started'])
            ? round(microtime(true) - (float) $m['scan_started'], 1)
            : null;
        self::writeWithRetry(fn () => DB::update(
            "UPDATE geodata_items
                SET status = ?, reason = ?,
                    metrics = COALESCE(metrics, '{}'::jsonb) || ?::jsonb,
                    finished_at = now(), updated_at = now()
              WHERE run_id = ? AND kind = 'acceptance_scan' AND status = 'running'",
            [
                $errors === [] ? 'done' : 'review',
                $errors === [] ? null
                    : ('scan detector(s) errored: ' . implode(', ', array_keys($errors))),
                json_encode(['elapsed' => $elapsed, 'parallel' => true]),
                $this->runId,
            ],
        ));
    }
}
