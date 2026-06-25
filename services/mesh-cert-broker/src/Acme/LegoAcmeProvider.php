<?php

namespace MeshCertBroker\Acme;

use MeshCertBroker\BrokerError;

/**
 * PRODUCTION provider — issues a real Let's Encrypt cert via DNS-01, signing the peer's CSR, using the
 * per-domain Cloudflare token. Shells out to `lego` (the battle-tested single-binary ACME client): lego
 * creates + cleans up the _acme-challenge TXT in Cloudflare itself, so the broker never implements ACME
 * crypto. The peer's private key never appears — lego issues FROM the CSR. The token is passed ONLY via
 * the child process env for this one call (never logged, never written to disk by the broker).
 *
 * Requires `lego` on PATH (install: one binary from github.com/go-acme/lego/releases). Set acme.staging
 * true while testing to use Let's Encrypt staging (avoids rate limits).
 */
final class LegoAcmeProvider implements AcmeProvider
{
    /** @param array<string,mixed> $acme */
    public function __construct(private readonly array $acme) {}

    public function issueFromCsr(string $fqdn, string $csrPem, array $domainCfg): string
    {
        $token = (string) ($domainCfg['cloudflare_token'] ?? '');
        $email = (string) ($this->acme['email'] ?? '');
        $bin = (string) ($this->acme['lego_bin'] ?? 'lego');
        if ($token === '' || $email === '') {
            throw new BrokerError('ACME not configured (missing cloudflare_token or acme.email).', 500);
        }

        $work = sys_get_temp_dir().'/cb-'.bin2hex(random_bytes(6));
        @mkdir($work, 0700, true);

        try {
            $csrFile = $work.'/req.csr';
            file_put_contents($csrFile, $csrPem);

            $args = [
                $bin, '--accept-tos', '--email', $email, '--dns', 'cloudflare',
                '--csr', $csrFile, '--path', $work,
            ];
            // Use an EXPLICIT public recursive resolver for DNS-01 zone-detection + propagation checks.
            // Inside Docker the default resolver is the embedded 127.0.0.11, which SERVFAILs on the SOA
            // queries lego uses to find the Cloudflare zone — breaking issuance even with a valid token.
            // A real public resolver (e.g. 1.1.1.1:53) answers them. Empty = lego's default (system resolver).
            $resolvers = trim((string) ($this->acme['dns_resolvers'] ?? ''));
            if ($resolvers !== '') {
                $args[] = '--dns.resolvers';
                $args[] = $resolvers;
            }
            // Skip the local propagation pre-check: it queries the zone's AUTHORITATIVE nameservers
            // directly, which a Docker container often can't reach over UDP/53. Let's Encrypt's own
            // (external) validators still confirm the TXT, so issuance is unaffected — this only drops a
            // local check the container can't perform. Disabled by default for the in-Docker broker.
            if (($this->acme['dns_disable_cp'] ?? true) !== false) {
                $args[] = '--dns.disable-cp';
            }
            // Default to STAGING when unset — only an EXPLICIT staging=false talks to production Let's
            // Encrypt, so a forgotten key can never silently burn the real per-domain rate limit.
            if (($this->acme['staging'] ?? true) !== false) {
                $args[] = '--server';
                $args[] = 'https://acme-staging-v02.api.letsencrypt.org/directory';
            }
            $args[] = 'run';

            $cmd = implode(' ', array_map('escapeshellarg', $args));
            $env = ['CLOUDFLARE_DNS_API_TOKEN' => $token, 'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'];

            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $descriptors, $pipes, $work, $env);
            if (! is_resource($proc)) {
                throw new BrokerError('Could not start the ACME client.', 500);
            }
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]); // drain stderr but NEVER surface it (may carry the challenge)
            foreach ($pipes as $p) {
                fclose($p);
            }
            $rc = proc_close($proc);

            // lego writes the issued cert to <work>/certificates/<fqdn>.crt
            $certPath = $work.'/certificates/'.$fqdn.'.crt';
            if ($rc !== 0 || ! is_file($certPath)) {
                throw new BrokerError('ACME issuance failed for '.$fqdn.'.', 502);
            }

            return (string) file_get_contents($certPath);
        } finally {
            $this->cleanup($work);
        }
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
