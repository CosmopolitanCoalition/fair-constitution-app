<?php

namespace App\Services\Federation;

use App\Models\InstanceSettings;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Zero-foreknowledge cold-start discovery (roles campaign). A fresh node with no peers and no host
 * address finds an existing federation so it can JOIN it — the question this answers is "where is a
 * federation I can join, and who is it?", BEFORE any trust relationship exists.
 *
 * The keystone is a single PUBLIC, unauthenticated descriptor every set-up node serves at
 * GET /.well-known/cga-federation. It carries only already-public facts (server_id + public_key — the
 * same identity exposed at /api/federation/identity — plus the self URL + reachable entry endpoints).
 * It is ADVISORY, exactly like the G9 directory: a hostile responder can at worst point a joiner at an
 * endpoint that then REJECTS it at the signed adopt handshake. NO secret is ever published — not the
 * Cloudflare token, not a join key, not any box-specific instruction.
 *
 * Two channels LOCATE descriptor-serving nodes (neither admits anyone — admission stays the signed flow):
 *   • FRONT DOOR  — config('cga.federation_bootstrap_urls') (default https://worldofstatecraft.org). A node
 *     with zero LAN + zero config still finds the canonical federation; a front door may vouch for more
 *     entry points via its descriptor's `known_federations`.
 *   • LAN SWEEP   — an opt-in, operator-triggered probe of the operator's OWN private subnet (RFC1918,
 *     capped at a /24, the well-known path only). This is how Box B finds Box A on a LAN "from jump".
 */
class FederationDiscoveryService
{
    public const WELL_KNOWN_PATH = '/.well-known/cga-federation';

    /** Protocol tag a valid descriptor must carry — guards against probing a non-CGA host. */
    public const PROTOCOL = 'cga-federation';

    public const PROTOCOL_VERSION = 1;

    /** Hard cap on a single LAN sweep, independent of the configured CIDR (a /24 is 256 hosts). */
    private const LAN_MAX_HOSTS = 256;

    /** Hard cap on the transitive front-door fan-out (a hostile descriptor can't list 10k targets). */
    private const MAX_KNOWN_FEDERATIONS = 16;

    /** Max distinct ports a LAN sweep will probe per host (defends against a port-scan via config). */
    private const LAN_MAX_PORTS = 4;

    public function __construct(
        private readonly InstanceIdentityService $identity,
        private readonly TransportService $transports,
    ) {}

    /**
     * The PUBLIC self-descriptor served at /.well-known/cga-federation. Public facts only.
     *
     * @return array<string,mixed>
     */
    public function describeSelf(): array
    {
        $settings = InstanceSettings::current();

        $selfUrl = (string) config('cga.federation_self_url');
        $endpoints = $this->transports->selfEndpoints();
        // Fall back to the single self URL when no transport rows are registered yet.
        if ($endpoints === [] && $selfUrl !== '') {
            $endpoints = [['transport' => 'https', 'url' => $selfUrl]];
        }

        return [
            'protocol' => self::PROTOCOL,
            'version' => self::PROTOCOL_VERSION,
            'name' => (string) $settings->instance_name,
            'server_id' => (string) $settings->server_id,
            'public_key' => (string) $settings->public_key,
            'federation_self_url' => $selfUrl !== '' ? $selfUrl : null,
            'entry_endpoints' => $endpoints,
            'constitutional_version' => $settings->constitutionalVersion(),
            'app_release' => config('cga.app_release'),
            // A joiner becomes a read-only MIRROR of an authoritative host, so only an authoritative,
            // set-up node with a callback URL advertises itself as joinable. A mirror still serves the
            // descriptor (it can vouch for its own host below) but does not present itself as the entry.
            'accepting_joins' => $settings->isSetupComplete()
                && ! $settings->isMirror()
                && $selfUrl !== '',
            // Optional: other entry points this node vouches for (a front door acts as a server browser).
            // Default empty; populated only when an operator curates a public catalog.
            'known_federations' => $this->knownFederations($settings),
        ];
    }

    /**
     * Run the configured discovery channels and return a deduped, normalized list of candidate
     * federations a fresh node could JOIN, plus any LAN-sweep error. Each entry is advisory.
     *
     * The front-door probe ALWAYS runs; the LAN sweep is isolated in its own try so a bad/out-of-bounds
     * CIDR surfaces as `lan_error` WITHOUT discarding the front-door results already gathered.
     *
     * @return array{federations: list<array<string,mixed>>, lan_error: ?string}
     */
    public function discover(bool $includeLan = false, ?string $lanCidr = null): array
    {
        $found = $this->discoverViaBootstrap();
        $lanError = null;

        if ($includeLan && config('cga.federation_lan_discovery')) {
            try {
                $found = array_merge($found, $this->discoverOnLan((string) $lanCidr));
            } catch (\InvalidArgumentException $e) {
                $lanError = $e->getMessage();
            }
        }

        return ['federations' => $this->dedupe($found), 'lan_error' => $lanError];
    }

    /**
     * Probe the configured front-door URL(s). A front door may also list other entry points in its
     * descriptor's `known_federations`, which we probe transitively (one hop only).
     *
     * @return list<array<string,mixed>>
     */
    public function discoverViaBootstrap(): array
    {
        $out = [];
        $seenUrls = [];

        foreach ((array) config('cga.federation_bootstrap_urls', []) as $url) {
            $url = $this->normalizeBaseUrl((string) $url);
            if ($url === null || isset($seenUrls[$url])) {
                continue;
            }
            $seenUrls[$url] = true;

            $descriptor = $this->probe($url);
            if ($descriptor === null) {
                continue;
            }
            $out[] = $this->toCandidate($url, $descriptor, 'bootstrap');

            // One transitive hop: a front door vouching for other federations. These URLs are
            // DESCRIPTOR-SUPPLIED (untrusted) — probe() applies the public-target SSRF guard, and the list
            // is hard-capped so a hostile front door can't drive an unbounded fan-out of server-side GETs.
            foreach (array_slice((array) ($descriptor['known_federations'] ?? []), 0, self::MAX_KNOWN_FEDERATIONS) as $entry) {
                $childUrl = $this->normalizeBaseUrl((string) (is_array($entry) ? ($entry['url'] ?? '') : ''));
                if ($childUrl === null || isset($seenUrls[$childUrl])) {
                    continue;
                }
                $seenUrls[$childUrl] = true;
                $childDescriptor = $this->probe($childUrl);
                if ($childDescriptor !== null) {
                    $out[] = $this->toCandidate($childUrl, $childDescriptor, 'bootstrap');
                }
            }
        }

        return $out;
    }

    /**
     * Sweep the operator's OWN private subnet for descriptor-serving nodes. Bounded to RFC1918 ranges
     * and a /24, probing only the well-known path. Throws on an out-of-bounds CIDR (caller validates UI-side
     * too) so a public range can never be turned into a scanning amplifier.
     *
     * @return list<array<string,mixed>>
     */
    public function discoverOnLan(string $cidr): array
    {
        $hosts = $this->enumeratePrivateHosts($cidr); // throws on a non-private / oversized range
        $ports = $this->sweepPorts();

        // Build (url) targets: each host × each port, well-known path only.
        $targets = [];
        foreach ($hosts as $host) {
            foreach ($ports as $port) {
                $base = 'http://'.$host.':'.$port;
                $targets[$base] = $base;
            }
        }
        $targets = array_values($targets);

        // A LAN round-trip is sub-millisecond — keep the sweep snappy and bounded (a dead /24 must not
        // stall the request thread). Deliberately shorter than the WAN front-door probe timeout.
        $timeout = max(1, min((int) config('cga.federation_probe_timeout_seconds', 3), 2));
        $out = [];

        // Concurrent probing in bounded batches so a /24 × ports doesn't open thousands of sockets at once.
        // withoutRedirecting: a LAN host must not 3xx us off to an internal target (redirect-to-internal).
        foreach (array_chunk($targets, 64) as $batch) {
            $responses = Http::pool(fn ($pool) => array_map(
                fn (string $base) => $pool->as($base)
                    ->withoutRedirecting()
                    ->timeout($timeout)
                    ->acceptJson()
                    ->get($base.self::WELL_KNOWN_PATH),
                $batch
            ));

            foreach ($batch as $base) {
                $descriptor = $this->descriptorFromResponse($responses[$base] ?? null);
                if ($descriptor !== null) {
                    $out[] = $this->toCandidate($base, $descriptor, 'lan');
                }
            }
        }

        return $out;
    }

    /**
     * Fetch + validate a single candidate's descriptor. Returns the normalized descriptor array, or null
     * if unreachable / not a valid CGA descriptor. Never throws (probing is best-effort).
     *
     * @return array<string,mixed>|null
     */
    public function probe(string $baseUrl): ?array
    {
        $base = $this->normalizeBaseUrl($baseUrl);
        if ($base === null) {
            return null;
        }
        // SSRF guard: probe() reaches PUBLIC-internet front doors and DESCRIPTOR-SUPPLIED known_federations.
        // Refuse any target that resolves into internal space (loopback, RFC1918, link-local 169.254 incl.
        // the cloud-metadata endpoint, CGNAT, IPv6 ULA/link-local) so a hostile descriptor can't turn this
        // into an internal port-scanner or a metadata-credential fetcher.
        if (! $this->publicTargetAllowed($base)) {
            return null;
        }
        $timeout = max(1, (int) config('cga.federation_probe_timeout_seconds', 3));

        try {
            // withoutRedirecting: a 3xx must not chase us to an internal Location (redirect-to-internal /
            // DNS-rebinding escape past the IP check above).
            $response = Http::withoutRedirecting()->timeout($timeout)->acceptJson()->get($base.self::WELL_KNOWN_PATH);
        } catch (Throwable) {
            return null;
        }

        return $this->descriptorFromResponse($response);
    }

    // ── internals ─────────────────────────────────────────────────────────────

    /**
     * @param  mixed  $response  an Illuminate\Http\Client\Response (or null/throwable from a pool)
     * @return array<string,mixed>|null
     */
    private function descriptorFromResponse($response): ?array
    {
        if (! is_object($response) || ! method_exists($response, 'successful') || ! $response->successful()) {
            return null;
        }

        try {
            $body = (array) $response->json();
        } catch (Throwable) {
            return null;
        }

        // Must self-identify as our protocol and carry a usable server identity.
        if (($body['protocol'] ?? null) !== self::PROTOCOL) {
            return null;
        }
        if (! is_string($body['server_id'] ?? null) || $body['server_id'] === '') {
            return null;
        }

        return $body;
    }

    /**
     * @param  array<string,mixed>  $descriptor
     * @return array{url:string,server_id:?string,name:string,version:?string,accepting_joins:bool,source:string}
     */
    private function toCandidate(string $baseUrl, array $descriptor, string $source): array
    {
        // Prefer the node's self-declared callback URL (NORMALIZED — a descriptor is untrusted, so a path /
        // trailing slash / junk scheme can't flow into the join target or split the dedupe key); else the
        // URL we reached it at.
        $declared = $this->normalizeBaseUrl((string) ($descriptor['federation_self_url'] ?? ''));
        $joinUrl = $declared ?? $baseUrl;

        return [
            'url' => $joinUrl,
            'server_id' => is_string($descriptor['server_id'] ?? null) ? (string) $descriptor['server_id'] : null,
            'name' => (string) ($descriptor['name'] ?? 'A federation'),
            'version' => isset($descriptor['constitutional_version']) ? (string) $descriptor['constitutional_version'] : null,
            'accepting_joins' => (bool) ($descriptor['accepting_joins'] ?? false),
            'source' => $source,
        ];
    }

    /**
     * Dedupe by server_id when known (a node reachable via the front door AND the LAN is one federation),
     * else by URL. A node that is accepting joins wins over one that is not.
     *
     * @param  list<array<string,mixed>>  $candidates
     * @return list<array<string,mixed>>
     */
    private function dedupe(array $candidates): array
    {
        $byKey = [];
        foreach ($candidates as $c) {
            $key = ! empty($c['server_id']) ? 'id:'.$c['server_id'] : 'url:'.$c['url'];
            if (! isset($byKey[$key]) || ((bool) $c['accepting_joins'] && ! (bool) $byKey[$key]['accepting_joins'])) {
                $byKey[$key] = $c;
            }
        }

        return array_values($byKey);
    }

    /** Normalize a base URL: require http/https, strip any path/query, drop a trailing slash. */
    private function normalizeBaseUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $authority = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $scheme.'://'.$authority;
    }

    /**
     * Expand a CIDR to host IPs, ENFORCING that it is a private (RFC1918) range no larger than a /24.
     * This is the SSRF guard: the sweep can only ever touch the operator's own LAN, never a public host
     * or a cloud metadata endpoint (169.254.0.0/16 is excluded by the RFC1918 requirement).
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException on a malformed, public, or oversized range
     */
    public function enumeratePrivateHosts(string $cidr): array
    {
        $cidr = trim($cidr);
        if (! str_contains($cidr, '/')) {
            throw new \InvalidArgumentException('Provide a CIDR range, e.g. 192.168.1.0/24.');
        }
        [$network, $prefix] = explode('/', $cidr, 2);
        $prefix = (int) $prefix;

        $netLong = ip2long($network);
        if ($netLong === false || ! filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new \InvalidArgumentException('Only IPv4 LAN ranges are supported for the local sweep.');
        }
        if ($prefix < 24 || $prefix > 32) {
            throw new \InvalidArgumentException('The local sweep is capped at a /24 (use a /24 or smaller range).');
        }
        if (! $this->isPrivateIpv4($network)) {
            throw new \InvalidArgumentException('The local sweep only scans your own private network (10/8, 172.16/12, 192.168/16).');
        }

        $mask = $prefix === 32 ? 0xFFFFFFFF : (~((1 << (32 - $prefix)) - 1)) & 0xFFFFFFFF;
        $base = $netLong & $mask;
        $count = $prefix === 32 ? 1 : (1 << (32 - $prefix));
        $count = min($count, self::LAN_MAX_HOSTS);

        $hosts = [];
        for ($i = 0; $i < $count; $i++) {
            $ipLong = ($base + $i) & 0xFFFFFFFF;
            $ip = long2ip($ipLong);
            // Belt-and-suspenders: re-confirm each enumerated address is private before it can be probed
            // (network + broadcast addresses simply won't answer, so they're harmless to include).
            if (! $this->isPrivateIpv4($ip)) {
                continue;
            }
            $hosts[] = $ip;
        }

        return $hosts;
    }

    private function isPrivateIpv4(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        // RFC1918 ONLY. FILTER_FLAG_NO_PRIV_RANGE makes the validator return false for EXACTLY the private
        // ranges (10/8, 172.16/12, 192.168/16) — so a private IP is one that fails this validation. We must
        // NOT also accept reserved ranges (NO_RES_RANGE would let link-local 169.254/16 — the cloud-metadata
        // neighbour — through), so reserved/link-local/CGNAT addresses correctly fall outside this set.
        $notPrivate = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE);

        return $notPrivate === false;
    }

    /**
     * SSRF guard for the PUBLIC discovery channels (front door + descriptor-supplied known_federations).
     * A target is allowed only if it cannot reach internal space. A literal IP must be public; a hostname
     * must resolve EXCLUSIVELY to public addresses (so a rebinding/poisoned name pointing at 127.0.0.1 or
     * 169.254.169.254 is refused). A name that does not resolve is allowed — the HTTP client uses the same
     * resolver, so it simply won't connect (and a fake test host is intercepted before any socket).
     */
    private function publicTargetAllowed(string $baseUrl): bool
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }
        $host = trim($host, '[]'); // unwrap a bracketed IPv6 literal

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host);
        }

        $ips = $this->resolveHost($host);
        if ($ips === []) {
            return true; // unresolvable → cannot connect anyway
        }
        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /** A public, routable IP: not private, not reserved (loopback/link-local/etc.), not CGNAT (100.64/10). */
    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // PHP's reserved set omits CGNAT (RFC6598 100.64.0.0/10) — block it explicitly (it covers tailnet).
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            if ($long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a hostname to its A + AAAA records. Best-effort: returns [] on any failure (the caller treats
     * an unresolvable host as harmless — it cannot be connected to).
     *
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        $ips = [];
        try {
            $a = gethostbynamel($host);
            if (is_array($a)) {
                $ips = $a;
            }
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $rec) {
                    if (isset($rec['ipv6'])) {
                        $ips[] = (string) $rec['ipv6'];
                    }
                }
            }
        } catch (Throwable) {
            return $ips;
        }

        return array_values(array_unique($ips));
    }

    /**
     * The LAN-sweep port set: valid TCP ports only, capped in count. Defends against an env that turns the
     * sweep into a broad internal port-scanner (the design is "the well-known path on a couple of HTTP ports").
     *
     * @return list<int>
     */
    private function sweepPorts(): array
    {
        $ports = [];
        foreach ((array) config('cga.federation_lan_discovery_ports', ['8080']) as $p) {
            $n = (int) $p;
            if ($n >= 1 && $n <= 65535) {
                $ports[$n] = $n;
            }
        }
        $ports = array_values($ports);

        return array_slice($ports !== [] ? $ports : [8080], 0, self::LAN_MAX_PORTS);
    }

    /**
     * Entry points this node vouches for (the front-door "server browser"). Empty unless an operator
     * curates a public catalog via config; never auto-populated from untrusted peer data.
     *
     * @return list<array{url:string,name?:string}>
     */
    private function knownFederations(InstanceSettings $settings): array
    {
        $curated = (array) config('cga.federation_known_entry_points', []);
        $out = [];
        foreach ($curated as $entry) {
            $url = $this->normalizeBaseUrl((string) (is_array($entry) ? ($entry['url'] ?? '') : $entry));
            if ($url !== null) {
                $out[] = array_filter([
                    'url' => $url,
                    'name' => is_array($entry) ? (string) ($entry['name'] ?? '') : '',
                ]);
            }
        }

        return $out;
    }
}
