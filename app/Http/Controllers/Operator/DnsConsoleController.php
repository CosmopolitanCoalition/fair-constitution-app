<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\BrokerAuthorization;
use App\Services\Federation\BrokerCredentialService;
use App\Services\Federation\BrokerFailoverService;
use App\Services\Federation\CertGrantStore;
use App\Services\Operator\OperatorInfraService;
use App\Support\SurfaceMeta;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /operator/dns — "DNS & certificates", the operator-plane read surface
 * over the mesh-cert-broker machinery (design contract:
 * mockups/v3/operator/dns.html). Same citizen-shell pattern as the rest of
 * the /operator suite: the page shell is reachable by any authenticated
 * user; the data block is built only for an auth:operator session.
 *
 * READ page over existing stores — the write doors it links (credential
 * set/forget) are the EXISTING /federation/broker/credentials endpoints;
 * grant/request/failover remain CLI + mesh flows. The mockup's budget
 * rails, DDNS re-point and wildcard grants have NO backend — they render
 * exactly as the mockup renders them, labeled future, never simulated
 * (see docs/plans/launch/DNS_BROKER_FUTURES.md).
 */
class DnsConsoleController extends Controller
{
    public function dns(
        BrokerCredentialService $credentials,
        BrokerFailoverService $failover,
        CertGrantStore $grants,
        OperatorInfraService $infra,
    ): Response {
        $operator = Auth::guard('operator')->user();
        $authed = $operator !== null;

        return Inertia::render('Operator/Dns', [
            'surface' => SurfaceMeta::for('operator/dns'),
            'authed' => $authed,
            'operator' => $authed ? ($operator->username ?? null) : null,
            'dns' => $authed ? $this->dnsData($credentials, $failover, $grants, $infra) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function dnsData(
        BrokerCredentialService $credentials,
        BrokerFailoverService $failover,
        CertGrantStore $grants,
        OperatorInfraService $infra,
    ): array {
        $failoverStatus = $failover->failoverStatus();

        // Live routing-table rows: who may broker which domain, per the
        // signed gossiped attestations.
        $authorizations = BrokerAuthorization::query()
            ->whereNull('revoked_at')
            ->orderBy('domain')
            ->get()
            ->map(fn (BrokerAuthorization $a) => [
                'domain' => (string) $a->domain,
                'broker_server_id' => (string) $a->broker_server_id,
                'authority_server_id' => (string) $a->authority_server_id,
                'issued_at' => $a->issued_at?->toIso8601String(),
            ])->values()->all();

        // The broker's own append-only issuance ledger — present only on a
        // box that brokers (the table auto-migrates on first issue).
        $issuances = [];
        try {
            if (Schema::hasTable('issuances')) {
                $issuances = DB::table('issuances')
                    ->orderByDesc('issued_at')
                    ->limit(15)
                    ->get(['fqdn', 'domain', 'peer_server_id', 'issued_at', 'not_after'])
                    ->map(fn ($r) => [
                        'fqdn' => (string) $r->fqdn,
                        'domain' => (string) $r->domain,
                        'peer_server_id' => (string) $r->peer_server_id,
                        'issued_at' => (int) $r->issued_at,
                        'not_after' => (int) $r->not_after,
                    ])->values()->all();
            }
        } catch (\Throwable) {
            // A broker-less box: the ledger simply doesn't exist.
        }

        return [
            // Token-free by construction — status() never returns the secret.
            'credentials' => $credentials->status(),
            'accept_from' => (array) ($failoverStatus['accept_from'] ?? []),
            'share_with' => (array) ($failoverStatus['share_with'] ?? []),
            'authorizations' => $authorizations,
            'received_grants' => $grants->fqdns(),
            'installed_certs' => $infra->installedCerts(),
            'issuances' => $issuances,
        ];
    }
}
