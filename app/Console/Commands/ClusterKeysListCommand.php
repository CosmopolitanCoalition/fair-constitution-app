<?php

namespace App\Console\Commands;

use App\Models\ClusterJoinKey;
use Illuminate\Console\Command;

/**
 * Phase G (G2) — list cluster join keys (handle + usage; never the secret).
 */
class ClusterKeysListCommand extends Command
{
    protected $signature = 'cluster:keys:list {--all : include dead (revoked/expired/exhausted) keys}';

    protected $description = 'List cluster join keys (handle + usage; the secret is never shown).';

    public function handle(): int
    {
        $rows = ClusterJoinKey::query()->orderByDesc('created_at')->get()
            ->filter(fn (ClusterJoinKey $k) => $this->option('all') || $k->isLive())
            ->map(fn (ClusterJoinKey $k) => [
                $k->handle,
                $k->uses.'/'.$k->max_uses,
                $k->isLive() ? 'live' : 'dead',
                optional($k->expires_at)->toIso8601String() ?? '—',
                $k->scope_jurisdiction_id ?? '—',
            ])->all();

        if ($rows === []) {
            $this->info('No join keys.');

            return self::SUCCESS;
        }

        $this->table(['handle', 'uses', 'state', 'expires_at', 'scope'], $rows);

        return self::SUCCESS;
    }
}
