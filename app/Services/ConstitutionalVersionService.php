<?php

namespace App\Services;

/**
 * G-VER — the DERIVED `constitutional_version`: a hash of the hardened-compute
 * surface (the files whose change alters HOW the constitution COUNTS — STV/Droop/
 * Gregory transfers, RCV, the supermajority formula, Webster apportionment, the
 * Art. III §6 co-determination math, the finalist cutoff). Because it is derived,
 * it can NEVER drift from reality: any change to that surface changes the version
 * automatically, so a real vote-math change cannot ship as a mere app_release and
 * slip past the upgrade-agreement + freeze (G-VER's whole reason to exist).
 *
 * Two instances with the same constitutional_version provably count identically;
 * a different value means a hardened rule moved and demands agreement (Meters A/B/C).
 *
 * Cross-platform determinism is load-bearing: Box A (Windows host) and a Linux Pi
 * must derive the SAME hash from identical code, so contents are CRLF→LF-normalized
 * and the file list is sorted by a forward-slash relative path before hashing.
 */
class ConstitutionalVersionService
{
    /**
     * The hardened-compute surface. Directories expand to their *.php files.
     * Extend this as new hardened compute lands — the derived version follows.
     */
    public const HARDENED_SURFACE = [
        'app/Domain/Counting',
        'app/Services/VoteCountingService.php',
        'app/Services/ConstitutionalValidator.php',
        'app/Services/DistrictingService.php',
        'app/Services/ElectionTriggerService.php',
        'app/Services/ApprovalService.php',
        'app/Services/Organizations/CoDeterminationService.php',
    ];

    private static ?string $cached = null;

    /** The derived constitutional_version, e.g. `cv1.<32 hex>`. Memoized per process. */
    public function derive(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $h = hash_init('sha256');
        foreach ($this->surfaceFiles() as $rel) {
            $contents = (string) @file_get_contents(base_path($rel));
            // Platform-independent: a Windows CRLF checkout must hash like a Linux LF one.
            $contents = str_replace("\r\n", "\n", $contents);
            hash_update($h, $rel."\n".$contents."\0");
        }

        return self::$cached = 'cv1.'.substr(hash_final($h), 0, 32);
    }

    /** Forget the memoized value (tests that mutate the surface on disk). */
    public function forget(): void
    {
        self::$cached = null;
    }

    /**
     * The hardened-surface files, as forward-slash relative paths, sorted +
     * de-duplicated (directories expanded to their *.php files).
     *
     * @return array<int,string>
     */
    public function surfaceFiles(): array
    {
        $files = [];

        foreach (self::HARDENED_SURFACE as $entry) {
            $abs = base_path($entry);

            if (is_dir($abs)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        $files[] = $this->relative($file->getPathname());
                    }
                }
            } elseif (is_file($abs)) {
                $files[] = str_replace('\\', '/', $entry);
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    private function relative(string $absolute): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return ltrim(str_replace($base, '', str_replace('\\', '/', $absolute)), '/');
    }
}
