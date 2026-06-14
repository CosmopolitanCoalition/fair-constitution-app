<?php

namespace App\Console\Commands;

use App\Services\Mirror\MirrorJoinKeyService;
use Illuminate\Console\Command;

/**
 * Phase G (G2) — revoke a cluster join key by its handle.
 */
class ClusterKeysRevokeCommand extends Command
{
    protected $signature = 'cluster:keys:revoke {handle : the public key handle}';

    protected $description = 'Revoke a cluster join key by its handle.';

    public function handle(MirrorJoinKeyService $keys): int
    {
        $handle = (string) $this->argument('handle');

        if ($keys->revoke($handle)) {
            $this->info("Revoked join key {$handle}.");

            return self::SUCCESS;
        }

        $this->error("No live join key with handle {$handle}.");

        return self::FAILURE;
    }
}
