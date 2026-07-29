<?php

namespace App\Jobs;

use App\Services\Dev\ScenarioPresetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/**
 * D5 — one scenario preset run: shell the REAL seeder command as a
 * subprocess and stream its output into the run state, live.
 *
 * A subprocess, not Artisan::call(), deliberately: the seeders print rich
 * bounded-chunk progress (the ETL rule) and Artisan::call() only yields
 * output at the END — which would turn a five-minute election into a
 * silent five-minute button. The Process output callback updates the
 * cached tail as lines arrive, so the flyout's 2-second poll (the
 * sim-console idiom) shows the seeder's own honest progress, including
 * its refusals.
 *
 * The guard runs in the CHILD too — the seeder carries
 * GuardsSyntheticData like every demo command, so even a mis-queued job
 * on a real world exits FAILURE before touching anything. The lock is
 * one-run-at-a-time (the seeders share worlds); it releases in finally,
 * crash or not, and tries=1 on the sim queue means no surprise re-run.
 */
class RunScenarioPresetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** The sim queue's workers run timeout=0; cap the child ourselves. */
    private const PROCESS_TIMEOUT_SECONDS = 3900; // the seeders' own poll ceiling is 3600

    private const TAIL_CHARS = 6000;

    public $tries = 1;

    public $timeout = 4000;

    public function __construct(
        public readonly string $preset,
        public readonly string $command,
        public readonly array $args,
        public readonly ?string $queuedBy,
    ) {
        $this->onQueue('sim');
    }

    public function handle(ScenarioPresetService $scenarios): void
    {
        $tail = '';

        $write = function (array $patch) use ($scenarios, &$tail) {
            $scenarios->putRunState($this->preset, $patch + [
                'preset' => $this->preset,
                'command' => $this->command,
                'tail' => mb_substr($tail, -self::TAIL_CHARS),
                'queued_by' => $this->queuedBy,
            ]);
        };

        try {
            $write(['status' => 'running', 'started_at' => now()->toIso8601String()]);

            $cmd = ['php', base_path('artisan'), $this->command];

            foreach ($this->args as $key => $value) {
                if ($value === true) {
                    $cmd[] = $key; // flag option, e.g. --instant
                } elseif (str_starts_with((string) $key, '--')) {
                    $cmd[] = "{$key}={$value}";
                } else {
                    $cmd[] = (string) $value; // positional, e.g. the slug
                }
            }

            $process = new Process($cmd, base_path(), ['CGA_SCENARIO_PRESET' => $this->preset]);
            $process->setTimeout(self::PROCESS_TIMEOUT_SECONDS);

            $lastFlush = 0.0;
            $process->run(function ($type, string $buffer) use (&$tail, &$lastFlush, $write) {
                $tail .= $buffer;

                // Flush at most ~1/s — the poll is 2s, faster writes buy nothing.
                $now = microtime(true);
                if ($now - $lastFlush >= 1.0) {
                    $lastFlush = $now;
                    $write(['status' => 'running', 'started_at' => now()->toIso8601String()]);
                }
            });

            $write([
                'status' => $process->getExitCode() === 0 ? 'done' : 'failed',
                'exit_code' => $process->getExitCode(),
                'finished_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            $tail .= "\n".$e->getMessage();
            $write(['status' => 'failed', 'exit_code' => null, 'finished_at' => now()->toIso8601String()]);

            throw $e;
        } finally {
            // Release the one-at-a-time lock no matter how the run ended.
            Cache::forget(ScenarioPresetService::LOCK_KEY);
        }
    }
}
