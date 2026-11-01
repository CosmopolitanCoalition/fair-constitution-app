<?php

namespace Tests\Unit;

use App\Jobs\Clocks\EvaluateCriticalPopulationJob;
use App\Jobs\Clocks\EvaluatePetitionThresholdJob;
use App\Jobs\Clocks\EvaluateResidencyThresholdsJob;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SHORT-HARDWARE GUARDS (operator order 2026-11-01). A box with a slow disk and
 * a large user table must not run its heavy automatic work.
 *
 *   · a frozen box exits the three threshold sweeps before any database read,
 *     including payloads queued before the freeze
 *   · the boot prewarm needs 4 GiB of Horizon memory
 *   · the login email lookup tries the exact lowercase address first
 */
class ShortHardwareGuardsTest extends TestCase
{
    public function test_the_threshold_sweeps_exit_before_any_query_when_frozen(): void
    {
        config(['cga.clocks_frozen' => true]);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        foreach ([EvaluateCriticalPopulationJob::class, EvaluateResidencyThresholdsJob::class, EvaluatePetitionThresholdJob::class] as $job) {
            app()->call([new $job, 'handle']);
        }

        $this->assertSame(0, $queries);
    }

    public function test_the_boot_prewarm_needs_four_gib_of_horizon_memory(): void
    {
        $script = file_get_contents(base_path('docker/php/entrypoint.sh'));

        $this->assertStringContainsString('[ "$gib" -lt 4 ]', $script);
        $this->assertStringNotContainsString('[ "$gib" -lt 2 ]', $script);
    }

    public function test_login_tries_the_exact_lowercase_email_before_the_scan_fallback(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Auth/AuthenticatedSessionController.php'));

        $exact = strpos($src, "->where('email', \$lowered)");
        $fallback = strpos($src, "whereRaw('lower(email) = ?'");

        $this->assertNotFalse($exact);
        $this->assertNotFalse($fallback);
        $this->assertLessThan($fallback, $exact);
    }
}
