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
        // Mint (or rotate) and KEEP the authoritative singleton — then enable + print on the SAME
        // object. The previous version called setEnabled() and the printers separately, each
        // re-fetching the singleton via current(); if a duplicate settings row ever exists those
        // fetches can diverge, so federation:init could mint the identity into one row and enable
        // ANOTHER — the enabled-but-unminted half-state caught on a cold deploy (federation_enabled
        // =true but server_id NULL → mesh:gates "identity minted" FAILs at Step 4). One object, one
        // save. (current() is now deterministic too, which closes the divergence at the source.)
        $settings = $this->option('rotate') ? $identity->rotate() : $identity->ensureIdentity();

        if ($this->option('rotate')) {
            $this->warn('Federation identity ROTATED — peers must re-handshake.');
        }

        $enable = ! $this->option('disable');
        $settings->federation_enabled = $enable;
        $settings->save();

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

        // Validate what actually PERSISTED, not just the in-memory object — re-read the row from the
        // DB. A save() that silently matched 0 rows (the null-id UPDATE class) leaves the in-memory
        // model populated while the DB stayed empty, so an in-memory-only check would pass and exit
        // SUCCESS not-ready. Enabling the mesh MUST leave a persisted, minted, ENABLED row (else
        // /api/federation/* 404s while the readiness gate fails) — fail loud so the deploy aborts.
        $settings->refresh();
        if ($enable && ($settings->server_id === null || $settings->public_key === null || ! $settings->federation_enabled)) {
            $this->error('federation:init did not persist a minted, enabled identity (server_id/public_key/enabled).');

            return self::FAILURE;
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
