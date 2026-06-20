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
        $csrFile = $work.'/req.csr';
        file_put_contents($csrFile, $csrPem);

        $args = [
            $bin, '--accept-tos', '--email', $email, '--dns', 'cloudflare',
            '--csr', $csrFile, '--path', $work,
        ];
        if (! empty($this->acme['staging'])) {
            $args[] = '--server';
            $args[] = 'https://acme-staging-v02.api.letsencrypt.org/directory';
        }
        $args[] = 'run';

        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $env = ['CLOUDFLARE_DNS_API_TOKEN' => $token, 'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'];

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, $work, $env);
        if (! is_resource($proc)) {
            $this->cleanup($work);
            throw new BrokerError('Could not start the ACME client.', 500);
        }
        stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        foreach ($pipes as $p) {
            fclose($p);
        }
        $rc = proc_close($proc);

        // lego writes the issued cert to <work>/certificates/<fqdn>.crt
        $certPath = $work.'/certificates/'.$fqdn.'.crt';
        if ($rc !== 0 || ! is_file($certPath)) {
            $this->cleanup($work);
            // Surface a SHORT reason; never echo the full lego output (it can contain the challenge/token).
            throw new BrokerError('ACME issuance failed for '.$fqdn.'.', 502);
        }
        $cert = (string) file_get_contents($certPath);
        $this->cleanup($work);

        return $cert;
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
