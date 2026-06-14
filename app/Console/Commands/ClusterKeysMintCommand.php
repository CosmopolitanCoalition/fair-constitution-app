<?php

namespace App\Console\Commands;

use App\Services\Mirror\MirrorJoinKeyService;
use DateTimeImmutable;
use Illuminate\Console\Command;

/**
 * Phase G (G2) — mint a cluster join key (host side). The plaintext is shown ONCE;
 * only the Argon2id hash is stored and only the public handle is audited.
 */
class ClusterKeysMintCommand extends Command
{
    protected $signature = 'cluster:keys:mint
        {--max-uses=1 : how many mirrors this key admits}
        {--expires= : an expiry, e.g. "+7 days" or an ISO timestamp}
        {--scope= : a jurisdiction id this key admits a mirror for (default: whole corpus)}';

    protected $description = 'Mint a cluster join key a mirror presents to adopt (plaintext shown ONCE).';

    public function handle(MirrorJoinKeyService $keys): int
    {
        $expires = $this->option('expires');

        [$plaintext, $key] = $keys->mint(
            maxUses: (int) $this->option('max-uses'),
            expiresAt: $expires ? new DateTimeImmutable($expires) : null,
            scopeJurisdictionId: $this->option('scope') ?: null,
        );

        $this->info('Join key minted — copy it now; it is shown only once:');
        $this->newLine();
        $this->line('  '.$plaintext);
        $this->newLine();
        $this->table(
            ['handle', 'max_uses', 'expires_at', 'scope'],
            [[$key->handle, $key->max_uses, optional($key->expires_at)->toIso8601String() ?? '—', $key->scope_jurisdiction_id ?? '—']]
        );

        return self::SUCCESS;
    }
}
