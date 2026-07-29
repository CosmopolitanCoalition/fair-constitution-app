<?php

namespace App\Jobs;

use App\Services\Demo\SimRunControl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * The console's START button, made async: run the REAL `sim:start` command on
 * the `sim` queue so enumeration (~907k rows, THE ETL RULE) never happens inside
 * a web request.
 *
 * It calls the actual command — not a paraphrase — so the guard, the single-run
 * law and the chunked-enumeration discipline are exactly the terminal's. The
 * command re-checks `GuardsSyntheticData` in this worker, so a start that somehow
 * reached a real world exits FAILURE before writing anything; the controller's
 * pre-check is the fast refusal, this is the backstop.
 *
 * The bars are the live feedback (the console reads `sim_items` fresh every 2 s),
 * so this job does not stream — it only stamps the control marker done/failed at
 * the end, carrying the command's output tail so a backstop refusal is legible.
 *
 * `tries = 1`: a populate run must never be silently re-started by a retry.
 */
class RunSimStartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    /** The sim queue runs timeout=0; the enumeration of a full planet is minutes. */
    public $timeout = 0;

    private const TAIL_CHARS = 4000;

    /**
     * @param  array<string,mixed>  $options  the sim:start option array (already normalised)
     */
    public function __construct(
        public readonly array $options,
        public readonly ?string $actor,
    ) {
        $this->onQueue('sim');
    }

    public function handle(): void
    {
        $mark = function (string $status, string $tail = '') {
            Cache::put(SimRunControl::CONTROL_KEY, [
                'action' => ! empty($this->options['--resume']) ? 'resume-enumerate' : 'start',
                'status' => $status,
                'by' => $this->actor,
                'at' => now()->toIso8601String(),
                'tail' => mb_substr($tail, -self::TAIL_CHARS),
            ], SimRunControl::CONTROL_TTL);
        };

        $mark('enumerating', 'Enumerating the worklist…');

        try {
            $exit = Artisan::call('sim:start', $this->options);
            $mark($exit === 0 ? 'done' : 'failed', Artisan::output());
        } catch (\Throwable $e) {
            $mark('failed', $e->getMessage());

            throw $e;
        }
    }
}
