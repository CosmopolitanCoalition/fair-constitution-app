<?php

namespace App\Console\Commands;

use App\Services\Federation\MeshGateService;
use Illuminate\Console\Command;

/**
 * mesh:gates — run the operator federation-readiness gates and print the greens (Phase G,
 * G8b). The terminal version of the federation-console "Run gates" panel: a pass/warn/fail
 * checklist of whether THIS node is set up to federate. Pair with `mesh:doctor <peer>` (the
 * per-peer two-way reachability probe) for the full pre-rig check. Exits non-zero if any
 * gate is a hard FAIL so it can gate a script.
 */
class MeshGatesCommand extends Command
{
    protected $signature = 'mesh:gates';

    protected $description = 'Run the operator federation-readiness gates (pass/warn/fail checklist)';

    public function handle(MeshGateService $gates): int
    {
        $failed = false;

        foreach ($gates->evaluate() as $gate) {
            [$mark, $method] = match ($gate['status']) {
                MeshGateService::PASS => ['[PASS]', 'info'],
                MeshGateService::WARN => ['[warn]', 'comment'],
                default => ['[FAIL]', 'error'],
            };
            if ($gate['status'] === MeshGateService::FAIL) {
                $failed = true;
            }
            $this->{$method}(sprintf('%-7s %-46s %s', $mark, $gate['label'], $gate['detail']));
        }

        $this->newLine();
        if ($failed) {
            $this->error('Not ready to federate — resolve the [FAIL] gates above.');

            return self::FAILURE;
        }
        $this->info('Node is ready to federate. Next: mesh:doctor <peer-url> to prove the two-way datapath.');

        return self::SUCCESS;
    }
}
