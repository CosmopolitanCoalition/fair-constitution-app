<?php

namespace App\Console\Commands;

use App\Models\ClockTimer;
use App\Services\ClockService;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Console\Command;

/**
 * federation:init — mint this instance's federation identity and open the mesh.
 *
 * Idempotent: re-running leaves an existing identity untouched (use --rotate to
 * force a fresh server_id + keypair, e.g. after cloning an instance). Sets
 * `federation_enabled` so the /api/federation/* peer endpoints answer; --disable
 * closes them again without dropping the identity.
 *
 * In F4 this also arms CLK-20 (the federation sync heartbeat).
 *
 * Usage:
 *   php artisan federation:init
 *   php artisan federation:init --rotate
 *   php artisan federation:init --disable
 */
class FederationInitCommand extends Command
{
    protected $signature = 'federation:init
                            {--rotate : Force a fresh server_id + keypair (clone re-key)}
                            {--disable : Close the mesh endpoints (keep the identity)}';

    protected $description = 'Mint the federation identity (server_id + Ed25519 keypair) and toggle the mesh on/off';

    public function handle(InstanceIdentityService $identity, ClockService $clocks): int
    {
        if ($this->option('rotate')) {
            $identity->rotate();
            $this->warn('Federation identity ROTATED — peers must re-handshake.');
        } else {
            $identity->ensureIdentity();
        }

        $enable = ! $this->option('disable');
        $settings = $identity->setEnabled($enable);

        // Arm the recurring CLK-20 heartbeat (idempotent — retire any stale
        // armed timer first, then arm one if federation is on).
        ClockTimer::query()
            ->where('clock_id', 'CLK-20')
            ->where('state', ClockTimer::STATE_ARMED)
            ->get()
            ->each(fn (ClockTimer $t) => $clocks->cancel($t, 'federation:init re-arm'));

        if ($enable) {
            $minutes = max(1, (int) config('cga.federation_heartbeat_minutes', 5));
            $clocks->arm('CLK-20', firesAt: now()->addMinutes($minutes));
        }

        $this->info(sprintf('Federation %s.', $enable ? 'ENABLED' : 'disabled'));
        $this->line('  server_id   : '.$settings->server_id);
        $this->line('  public key  : '.$this->fingerprint((string) $settings->public_key));
        $this->line('  heartbeat   : '.($enable ? 'CLK-20 armed (~'.config('cga.federation_heartbeat_minutes', 5).'m)' : 'disarmed'));
        $this->line('  schema ver. : '.config('cga.schema_version', '1'));

        return self::SUCCESS;
    }

    /** A short, human-readable fingerprint of the base64 public key. */
    private function fingerprint(string $publicKeyB64): string
    {
        $hash = substr(hash('sha256', $publicKeyB64), 0, 16);

        return implode(':', str_split($hash, 4));
    }
}
