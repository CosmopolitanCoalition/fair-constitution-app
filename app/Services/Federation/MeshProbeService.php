<?php

namespace App\Services\Federation;

use App\Models\InstanceSettings;
use Illuminate\Support\Str;

/**
 * The per-peer two-way reachability probe (Phase G, G8b). Dials a target over every known
 * transport FROM INSIDE the app container — the exact test of whether the container→host→
 * overlay datapath works — and reports which rungs are live + whether the peer counts under
 * the same constitutional_version (a mismatch makes Meter C refuse sync).
 *
 * Shared by the `mesh:doctor` CLI and the federation-console GUI so the operator gets the
 * SAME probe in both. Pure read — dials /api/federation/identity, records nothing.
 */
class MeshProbeService
{
    public function __construct(
        private readonly FederationClient $client,
        private readonly TransportEndpoints $endpoints,
    ) {}

    /**
     * @return array{target:string,rungs:list<array{transport:string,url:string,reachable:bool,latency_ms:int|null,http_status:int|null,version:string,version_match:bool|null,error:string|null}>,reached:int,total:int}
     */
    public function probe(string $target): array
    {
        $target = trim($target);
        $ourCv = InstanceSettings::current()->constitutionalVersion();

        // A uuid → that peer's whole ladder; anything else → the URL dialed directly.
        $rungs = Str::isUuid($target)
            ? array_map(fn ($r) => ['transport' => $r['transport'], 'url' => $r['url']], $this->endpoints->forPeer($target))
            : [['transport' => '(direct)', 'url' => rtrim($target, '/')]];

        $timeout = max(1, (int) config('cga.federation_probe_timeout_seconds', 5));
        $out = [];
        $reached = 0;

        foreach ($rungs as $rung) {
            $startedMs = (int) (microtime(true) * 1000);

            try {
                $resp = $this->client->get($rung['url'], '/api/federation/identity', [], $timeout);
            } catch (\Throwable $e) {
                $out[] = $rung + ['reachable' => false, 'latency_ms' => null, 'http_status' => null, 'version' => '', 'version_match' => null, 'error' => $e->getMessage()];

                continue;
            }

            $ms = (int) (microtime(true) * 1000) - $startedMs;

            if (! $resp->successful()) {
                $out[] = $rung + ['reachable' => false, 'latency_ms' => $ms, 'http_status' => $resp->status(), 'version' => '', 'version_match' => null, 'error' => null];

                continue;
            }

            $reached++;
            $peerCv = (string) (((array) $resp->json())['constitutional_version'] ?? '');
            $out[] = $rung + [
                'reachable' => true,
                'latency_ms' => $ms,
                'http_status' => $resp->status(),
                'version' => $peerCv,
                'version_match' => $peerCv === '' ? null : ($peerCv === $ourCv),
                'error' => null,
            ];
        }

        return ['target' => $target, 'rungs' => $out, 'reached' => $reached, 'total' => count($rungs)];
    }
}
