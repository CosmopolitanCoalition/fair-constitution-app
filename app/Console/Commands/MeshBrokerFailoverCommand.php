<?php

namespace App\Console\Commands;

use App\Services\Federation\BrokerFailoverService;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Console\Command;
use Throwable;

/**
 * mesh:broker-failover — the operator's driver for trusted-broker credential failover (Identity Broker,
 * roles campaign Phase 4). A primary broker may share its per-domain Cloudflare credential, SEALED, with an
 * EXPLICITLY-trusted failover broker. Both sides opt in by name (mutual trust); the share rides the mesh
 * sealed-to-the-recipient and is the ONE authorized exception to "the token never leaves the box."
 *
 *   mesh:broker-failover status                              — credentials (provenance, no token) + trust lists
 *   mesh:broker-failover designate <domain> <peer-server-id> — PRIMARY: name a peer as a failover target
 *   mesh:broker-failover undesignate <domain> <peer-server-id>
 *   mesh:broker-failover share <domain> <peer-server-id>     — PRIMARY: seal + push the credential now
 *   mesh:broker-failover share-all <domain>                  — PRIMARY: re-push to every designated failover
 *   mesh:broker-failover allow <domain> <peer-server-id>     — FAILOVER: accept that primary's shares
 *   mesh:broker-failover deny  <domain> <peer-server-id>
 *
 * Revocation is honest: once shared, the bytes are out. undesignate/deny stop FUTURE shares; to truly
 * revoke a shared credential, ROTATE the Cloudflare token and `share-all` again.
 */
class MeshBrokerFailoverCommand extends Command
{
    protected $signature = 'mesh:broker-failover
        {action : status|designate|undesignate|share|share-all|allow|deny}
        {domain? : the naming-root domain (e.g. worldofstatecraft.org)}
        {peer? : the failover peer server_id (designate/undesignate/share/allow/deny)}';

    protected $description = 'Drive trusted-broker credential failover: status / designate / share / allow (+ undesignate / deny)';

    public function handle(BrokerFailoverService $failover, InstanceIdentityService $identity): int
    {
        $identity->ensureIdentity();
        $domain = (string) ($this->argument('domain') ?? '');
        $peer = (string) ($this->argument('peer') ?? '');

        try {
            return match ((string) $this->argument('action')) {
                'status' => $this->status($failover),
                'designate' => $this->mutate($failover, 'designate', $domain, $peer),
                'undesignate' => $this->mutate($failover, 'undesignate', $domain, $peer),
                'allow' => $this->mutate($failover, 'allow', $domain, $peer),
                'deny' => $this->mutate($failover, 'deny', $domain, $peer),
                'share' => $this->share($failover, $domain, $peer),
                'share-all' => $this->shareAll($failover, $domain),
                default => $this->bail('Unknown action — use status|designate|undesignate|share|share-all|allow|deny.'),
            };
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function status(BrokerFailoverService $failover): int
    {
        $s = $failover->failoverStatus();

        $this->info('Broker credentials (token NEVER shown):');
        if ($s['credentials'] === []) {
            $this->line('  (none configured)');
        }
        foreach ($s['credentials'] as $c) {
            $from = $c['from_server_id'] !== null ? ' from '.substr((string) $c['from_server_id'], 0, 8).'…' : '';
            $this->line(sprintf('  %-28s zone=%s  [%s%s]', $c['domain'], $c['zone_id'] ?? '?', $c['source'], $from));
        }

        $this->info('Share with (we are PRIMARY → these peers are our failovers):');
        $this->printList($s['share_with']);
        $this->info('Accept from (we are FAILOVER → we accept shares from these peers):');
        $this->printList($s['accept_from']);

        return self::SUCCESS;
    }

    /** @param array<string,list<string>> $byDomain */
    private function printList(array $byDomain): void
    {
        if ($byDomain === []) {
            $this->line('  (none)');

            return;
        }
        foreach ($byDomain as $domain => $peers) {
            $short = array_map(fn (string $id) => substr($id, 0, 8).'…', $peers);
            $this->line(sprintf('  %-28s %s', $domain, implode(', ', $short)));
        }
    }

    private function mutate(BrokerFailoverService $failover, string $verb, string $domain, string $peer): int
    {
        if ($domain === '' || $peer === '') {
            $this->error("Pass <domain> and <peer-server-id> for {$verb}.");

            return self::FAILURE;
        }

        match ($verb) {
            'designate' => $failover->designateFailover($domain, $peer),
            'undesignate' => $this->report('undesignate', $failover->undesignateFailover($domain, $peer)),
            'allow' => $failover->allowFrom($domain, $peer),
            'deny' => $this->report('deny', $failover->denyFrom($domain, $peer)),
        };

        if (in_array($verb, ['designate', 'allow'], true)) {
            $this->info("[OK] {$verb} {$domain} ↔ ".substr($peer, 0, 8).'…');
        }

        return self::SUCCESS;
    }

    private function report(string $verb, bool $changed): void
    {
        $this->info($changed ? "[OK] {$verb} removed the entry." : "[NOOP] no matching {$verb} entry.");
    }

    private function share(BrokerFailoverService $failover, string $domain, string $peer): int
    {
        if ($domain === '' || $peer === '') {
            $this->error('Pass <domain> and <peer-server-id> for share.');

            return self::FAILURE;
        }
        $r = $failover->shareTo($domain, $peer);
        $this->renderShare($r);

        return $r['delivered'] ? self::SUCCESS : self::FAILURE;
    }

    private function shareAll(BrokerFailoverService $failover, string $domain): int
    {
        if ($domain === '') {
            $this->error('Pass <domain> for share-all.');

            return self::FAILURE;
        }
        $results = $failover->shareAll($domain);
        if ($results === []) {
            $this->comment("No designated failover targets for [{$domain}] — designate one first.");

            return self::SUCCESS;
        }
        $ok = true;
        foreach ($results as $r) {
            $this->renderShare($r);
            $ok = $ok && ($r['delivered'] ?? false);
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @param array{peer_server_id:string,delivered:bool,status:int,error?:?string} $r */
    private function renderShare(array $r): void
    {
        $peer = substr($r['peer_server_id'], 0, 8).'…';
        if ($r['delivered']) {
            $this->info("[SHARED] sealed credential delivered to {$peer} (HTTP {$r['status']}).");

            return;
        }
        $why = ($r['error'] ?? null) !== null ? $r['error'] : 'HTTP '.$r['status'];
        $this->error("[FAILED] {$peer} — {$why}");
    }

    private function bail(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
