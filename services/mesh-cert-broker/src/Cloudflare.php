<?php

namespace MeshCertBroker;

/**
 * Minimal Cloudflare API v4 client — used ONLY to point the granted name at the peer's address (an A/AAAA
 * record). DNS-01 challenge records are handled by the ACME client (lego), not here. The per-domain token
 * is used for this one call and never logged.
 */
final class Cloudflare
{
    /** @param array<string,mixed> $domainCfg */
    public static function upsertAddressRecord(array $domainCfg, string $fqdn, string $target): void
    {
        $token = (string) ($domainCfg['cloudflare_token'] ?? '');
        $zone = (string) ($domainCfg['cloudflare_zone_id'] ?? '');
        if ($token === '' || $zone === '') {
            throw new BrokerError('Cloudflare zone not configured for A-record creation.', 500);
        }

        $type = str_contains($target, ':') ? 'AAAA' : 'A';
        $proxied = (bool) ($domainCfg['a_record_proxied'] ?? false);
        $record = ['type' => $type, 'name' => $fqdn, 'content' => $target, 'ttl' => 120, 'proxied' => $proxied];

        $existing = self::api($token, 'GET', "/zones/{$zone}/dns_records?type={$type}&name=".rawurlencode($fqdn));
        $id = $existing['result'][0]['id'] ?? null;

        if (is_string($id) && $id !== '') {
            self::api($token, 'PUT', "/zones/{$zone}/dns_records/{$id}", $record);
        } else {
            self::api($token, 'POST', "/zones/{$zone}/dns_records", $record);
        }
    }

    /** @return array<string,mixed> */
    private static function api(string $token, string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init('https://api.cloudflare.com/client/v4'.$path);
        $headers = ['Authorization: Bearer '.$token, 'Content-Type: application/json', 'Accept: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = is_string($resp) ? json_decode($resp, true) : null;
        if ($code >= 300 || ! is_array($json) || empty($json['success'])) {
            throw new BrokerError('Cloudflare DNS update failed.', 502);
        }

        return $json;
    }
}
