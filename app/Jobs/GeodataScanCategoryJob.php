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

    public function handle(GeodataFlagService $service): void
    {
        $flags = null;
        $error = null;
        try {
            // ISO-BATCHED for continuous visibility (operator order
            // 2026-08-02: "I want to see the progress"): the service takes
            // iso scoping as a first-class parameter, so each detector runs
            // as batches of countries with a progress write after every
            // batch — the panel bar moves every few seconds instead of
            // once per 5-minute detector. deleteOpenFlags is iso-scoped,
            // so batches compose exactly like one full pass.
            $isos = DB::table('jurisdictions')
                ->whereNotNull('iso_code')->whereNull('deleted_at')
                ->where('adm_level', 1)
                ->distinct()->orderBy('iso_code')->pluck('iso_code')->all();
            $batches = array_chunk($isos, 10);
            $total   = count($batches);
            $flags   = 0;
            foreach ($batches as $bi => $batch) {
                $counts = $service->scan([$this->category], $batch);
                $flags += (int) array_sum($counts);
                DB::update(
                    "UPDATE geodata_items
                        SET metrics = jsonb_set(COALESCE(metrics,'{}'::jsonb),
                                ARRAY['cats_progress', ?],
                                jsonb_build_array(?::int, ?::int, ?::int), true),
                            updated_at = now()
                      WHERE run_id = ? AND kind = 'acceptance_scan' AND status = 'running'",
                    [$this->category, $bi + 1, $total, $flags, $this->runId],
                );
            }
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
        DB::update(
            "UPDATE geodata_items
                SET metrics = jsonb_set(COALESCE(metrics, '{}'::jsonb),
                                        ARRAY['cats', ?], to_jsonb(?::int), true),
                    updated_at = now()
              WHERE run_id = ? AND kind = 'acceptance_scan' AND status = 'running'",
            [$this->category, $error === null ? $flags : -1, $this->runId],
        );
        if ($error !== null) {
            DB::update(
                "UPDATE geodata_items
                    SET metrics = jsonb_set(COALESCE(metrics, '{}'::jsonb),
                                            ARRAY['cat_errors', ?], to_jsonb(?::text), true),
                        updated_at = now()
                  WHERE run_id = ? AND kind = 'acceptance_scan' AND status = 'running'",
                [$this->category, $error, $this->runId],
            );
        }

        if ($this->nextCategory !== null) {
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
