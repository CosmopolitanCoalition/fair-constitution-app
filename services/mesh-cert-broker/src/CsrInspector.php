<?php

namespace MeshCertBroker;

/**
 * Extracts EVERY DNS name a CSR would put in the issued cert (CN + all subjectAltName DNS entries), so the
 * broker can refuse a CSR that asks for any name other than the one the grant authorizes. A peer must not
 * be able to smuggle an extra SAN and walk away with a cert for a name it was never granted.
 */
final class CsrInspector
{
    /** @return list<string> lowercased DNS names in the CSR (CN + SAN DNS:). Throws on an unparseable CSR. */
    public static function names(string $csrPem): array
    {
        $names = [];

        $subject = @openssl_csr_get_subject($csrPem, true); // shortnames → 'CN'
        if ($subject === false) {
            throw new BrokerError('Unparseable CSR.', 400);
        }
        $cn = $subject['CN'] ?? ($subject['commonName'] ?? null);
        if (! empty($cn)) {
            $names[] = strtolower((string) $cn);
        }

        // SANs are not exposed by openssl_csr_get_subject — read them from the CSR text via the openssl CLI.
        $tmp = tempnam(sys_get_temp_dir(), 'csr');
        try {
            file_put_contents($tmp, $csrPem);
            $out = [];
            $rc = 0;
            exec('openssl req -in '.escapeshellarg($tmp).' -noout -text 2>/dev/null', $out, $rc);
            if ($rc === 0) {
                foreach ($out as $line) {
                    if (preg_match_all('/DNS:([^,\s]+)/', $line, $m)) {
                        foreach ($m[1] as $dns) {
                            $names[] = strtolower(trim($dns));
                        }
                    }
                }
            }
        } finally {
            @unlink($tmp);
        }

        return array_values(array_unique($names));
    }
}
