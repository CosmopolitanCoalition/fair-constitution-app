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
        public ?string $nextCategory = null,
    ) {
        $this->onQueue('long-running');
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
            // WHOLE-DETECTOR runs (iso-batching REVERTED same-day: the
            // heavy detectors open with a MATERIALIZED planet-wide CTE
            // that computes before any iso filter applies — measured 142s
            // per 10-iso batch, so batching multiplied cost ~24x instead
            // of dividing it). Honest visibility grain = one tick per
            // completed detector; finer grain requires making those CTEs
            // iso-sargable — filed as detector-query rework, not a
            // coordination change.
            $counts = $service->scan([$this->category]);
            $flags  = (int) array_sum($counts);
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 300);
            Log::error('Geodata scan category errored', [
                'run_id' => $this->runId, 'category' => $this->category,
                'message' => $e->getMessage(),
            ]);
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
        DB::update(
            "UPDATE geodata_items
                SET metrics = jsonb_set(
                        COALESCE(metrics, '{}'::jsonb)
                          || jsonb_build_object('cats', COALESCE(metrics->'cats', '{}'::jsonb)),
                        ARRAY['cats', ?], to_jsonb(?::int), true),
                    updated_at = now()
              WHERE run_id = ? AND kind = 'acceptance_scan' AND status = 'running'",
            [$this->category, $error === null ? $flags : -1, $this->runId],
        );
        if ($error !== null) {
            // Same trap — masked until now only because 'cats' was equally
            // broken (an error write that lands on nothing looks identical
            // to an error write that never happened).
            DB::update(
                "UPDATE geodata_items
                    SET metrics = jsonb_set(
                            COALESCE(metrics, '{}'::jsonb)
                              || jsonb_build_object('cat_errors', COALESCE(metrics->'cat_errors', '{}'::jsonb)),
                            ARRAY['cat_errors', ?], to_jsonb(?::text), true),
                        updated_at = now()
                  WHERE run_id = ? AND kind = 'acceptance_scan' AND status = 'running'",
                [$this->category, $error, $this->runId],
            );
        }

        if ($this->nextCategory !== null) {
            // The chained category counts as dispatched NOW — stamp its
            // start here so the pump never mistakes "queued behind the
            // chain" for "never dispatched" and races a duplicate.
            self::stampStarted($this->runId, $this->nextCategory);
            self::dispatch($this->runId, $this->nextCategory);
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
        $errors  = $m['cat_errors'] ?? [];
        $elapsed = isset($m['scan_started'])
            ? round(microtime(true) - (float) $m['scan_started'], 1)
            : null;
        DB::update(
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
        );
    }
}
