<?php

namespace MeshCertBroker;

/**
 * Extracts EVERY name a CSR would put in the issued cert — the CN and ALL subjectAltName entries of
 * EVERY type (DNS, IP, email, URI, …) — so the broker can refuse a CSR that asks for anything other than
 * the one DNS name the grant authorizes. A peer must not smuggle an extra SAN and walk away with a cert
 * for a name it was never granted.
 *
 * FAIL-CLOSED: if the names cannot be fully enumerated (openssl CLI/exec unavailable, parse error), this
 * THROWS — it never returns a partial (e.g. CN-only) list, because a partial list would silently drop a
 * smuggled SAN. A non-DNS SAN is surfaced as a sentinel token so the exact-match gate refuses it.
 */
final class CsrInspector
{
    /** @return list<string> the lowercased name set (CN + DNS SANs; non-DNS SANs become "non-dns:<type>"). */
    public static function names(string $csrPem): array
    {
        // Fail closed if we cannot run the inspector at all — never assume "no extra names".
        if (! function_exists('exec') || in_array('exec', self::disabledFunctions(), true)) {
            throw new BrokerError('CSR cannot be inspected on this host (exec unavailable) — refusing to issue.', 500);
        }
        // CN via ext-openssl (reliable, in-process).
        $subject = @openssl_csr_get_subject($csrPem, true);
        if ($subject === false) {
            throw new BrokerError('Unparseable CSR.', 400);
        }
        $names = [];
        $cn = $subject['CN'] ?? ($subject['commonName'] ?? null);
        if (! empty($cn)) {
            $names[] = strtolower((string) $cn);
        }

        // SANs (every GeneralName type) from the openssl text dump — fail closed on any error.
        $tmp = tempnam(sys_get_temp_dir(), 'csr');
        try {
            file_put_contents($tmp, $csrPem);
            $out = [];
            $rc = 1; // pessimistic default — if exec silently no-ops, we MUST treat it as failure
            @exec('openssl req -in '.escapeshellarg($tmp).' -noout -text 2>/dev/null', $out, $rc);
            $text = implode("\n", $out);

            // Proof the CLI actually parsed the CSR; otherwise we cannot trust "no SANs".
            if ($rc !== 0 || ! str_contains($text, 'Subject:')) {
                throw new BrokerError('Could not verify the CSR\'s names — refusing to issue (fail closed).', 500);
            }

            // The SAN block lists "X509v3 Subject Alternative Name:\n  DNS:a, IP Address:b, email:c".
            if (preg_match('/X509v3 Subject Alternative Name:\s*\n\s*(.+)/', $text, $m)) {
                foreach (explode(',', $m[1]) as $entry) {
                    $entry = trim($entry);
                    if ($entry === '') {
                        continue;
                    }
                    if (preg_match('/^DNS:(.+)$/i', $entry, $dm)) {
                        $names[] = strtolower(trim($dm[1]));
                    } else {
                        // ANY non-DNS SAN (IP/email/URI/otherName/DirName) is disallowed → a sentinel that
                        // can never equal the granted FQDN, so the exact-match gate refuses the request.
                        $names[] = 'non-dns:'.strtolower(explode(':', $entry, 2)[0]);
                    }
                }
            }
        } finally {
            @unlink($tmp);
        }

        return array_values(array_unique($names));
    }

    /** @return list<string> */
    private static function disabledFunctions(): array
    {
        $raw = (string) ini_get('disable_functions');

        return array_map('trim', $raw === '' ? [] : explode(',', $raw));
    }
}
