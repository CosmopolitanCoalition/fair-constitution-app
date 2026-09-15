<?php

namespace App\Console\Commands;

use App\Console\Concerns\GuardsSyntheticData;
use App\Services\Organizations\CoalitionSeedService;
use Illuminate\Console\Command;

/**
 * institutions:demo-coalition — Phase J (W-0300) on a synthetic world.
 *
 * Seeds the Cosmopolitan Party Foundation and its Coalition programme at New
 * York County, New York, with a member roster and the Foundation's board. The
 * sim world load calls the same service on every run (sim:start), so this
 * command exists for a walkthrough and for --fresh teardown. It refuses any
 * world that has not declared itself synthetic-safe: on a real world the
 * Foundation is registered by a human through F-IND-012, never minted here
 * (plan §9).
 */
class DemoCoalitionCommand extends Command
{
    use GuardsSyntheticData;

    protected $signature = 'institutions:demo-coalition {--fresh : retire the seeded organisations first, then seed again}';

    protected $description = 'Seed the Foundation and the Coalition programme at New York County on a synthetic world (Phase J, W-0300)';

    public function handle(CoalitionSeedService $seed): int
    {
        if (! $this->guardSyntheticData()) {
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $gone = $seed->teardown();
            $this->line("teardown: {$gone['removed']} organisation(s) retired");
        }

        $out = $seed->ensure();
        $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $out['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
