<?php

namespace App\Console\Commands;

use App\Services\Federation\NoSurvivingTransport;
use App\Services\PeerUpgradeAgreementService;
use Illuminate\Console\Command;

/**
 * federation:upgrade:consent {proposal} {proposer} [--reject] — deliver THIS node's
 * Meter C mesh consent for a co-affected upgrade proposal to the proposing peer
 * (Phase G, G-VER / A2). Run on a co-affected node once it has learned of a proposal
 * (it rides the synced audit tail); the proposer records it after re-verifying our
 * standing. The signed consent travels the survival mesh, so it survives a down
 * clearnet exactly as any other S2S message.
 *
 *   php artisan federation:upgrade:consent <proposal-uuid> <proposer-server-uuid>
 *   php artisan federation:upgrade:consent <proposal-uuid> <proposer-server-uuid> --reject
 */
class FederationUpgradeConsentCommand extends Command
{
    protected $signature = 'federation:upgrade:consent {proposal : the proposal id} {proposer : the proposing peer server_id} {--reject : deliver a NO instead of a YES}';

    protected $description = 'Deliver this node\'s Meter C mesh consent for an upgrade proposal to the proposer';

    public function handle(PeerUpgradeAgreementService $upgrades): int
    {
        $consented = ! $this->option('reject');

        try {
            $response = $upgrades->deliverConsent(
                (string) $this->argument('proposal'),
                (string) $this->argument('proposer'),
                $consented,
            );
        } catch (NoSurvivingTransport $e) {
            $this->error('Proposer unreachable over every transport: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($response->successful()) {
            $this->info('Consent ('.($consented ? 'yes' : 'no').') delivered: '.($response->json('status') ?? 'ok').'.');

            return self::SUCCESS;
        }

        $this->error("Consent delivery refused (HTTP {$response->status()}): ".$response->body());

        return self::FAILURE;
    }
}
