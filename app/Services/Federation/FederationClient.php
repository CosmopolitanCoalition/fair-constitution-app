<?php

namespace App\Services\Federation;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Signed server-to-server HTTP client for the federation mesh (Phase F).
 *
 * Every outbound peer request is signed with this instance's Ed25519 key over a
 * canonical string the receiving VerifyPeerSignature middleware reconstructs
 * BYTE-FOR-BYTE:
 *
 *   METHOD \n REQUEST_TARGET \n TIMESTAMP \n sha256(raw_body)
 *
 * where REQUEST_TARGET is the path plus query (no host), so any in-transit
 * mutation of the method, path, query, or body invalidates the signature.
 */
class FederationClient
{
    public function __construct(private readonly InstanceIdentityService $identity) {}

    public function get(string $baseUrl, string $path, array $query = []): Response
    {
        $target = $path.($query !== [] ? '?'.http_build_query($query) : '');

        return $this->send('GET', $baseUrl, $target, '');
    }

    public function post(string $baseUrl, string $path, array $payload = []): Response
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->send('POST', $baseUrl, $path, (string) $body);
    }

    /**
     * The canonical string both sides sign/verify. MUST stay byte-identical
     * with VerifyPeerSignature::signingString().
     */
    public static function signingString(string $method, string $requestTarget, int $timestamp, string $body): string
    {
        return strtoupper($method)."\n".$requestTarget."\n".$timestamp."\n".hash('sha256', $body);
    }

    /**
     * The transport seam (Phase G, G8): the SAME signed bytes travel over any
     * channel. A `.onion` base URL is dialled through the configured SOCKS proxy
     * (a local Tor daemon); everything else (https, a tailnet address) is reached
     * directly unless a global proxy is set. Null = direct (the default).
     */
    public function proxyFor(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host !== '' && str_ends_with($host, '.onion')) {
            $socks = config('cga.federation_socks_proxy');

            return $socks !== null && $socks !== '' ? (string) $socks : null;
        }

        $proxy = config('cga.federation_proxy');

        return $proxy !== null && $proxy !== '' ? (string) $proxy : null;
    }

    private function send(string $method, string $baseUrl, string $requestTarget, string $body): Response
    {
        $timestamp = now()->timestamp;
        $signature = $this->identity->sign(self::signingString($method, $requestTarget, $timestamp, $body));

        $headers = [
            'X-Federation-Server-Id' => $this->identity->serverId(),
            'X-Federation-Timestamp' => (string) $timestamp,
            'X-Federation-Signature' => $signature,
        ];

        $url = rtrim($baseUrl, '/').$requestTarget;

        $request = Http::withHeaders($headers)->timeout(20)->acceptJson();

        // Route .onion (and any globally-proxied) traffic through the SOCKS seam.
        // Default config is null → no proxy option → existing calls are unchanged.
        $proxy = $this->proxyFor($url);
        if ($proxy !== null) {
            $request = $request->withOptions(['proxy' => $proxy]);
        }

        if ($method === 'GET') {
            return $request->get($url);
        }

        return $request->withBody($body, 'application/json')->post($url);
    }
}
