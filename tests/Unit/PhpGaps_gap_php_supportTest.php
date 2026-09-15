<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * gap-php-support — PHP response-string i18n gap pin (2026-09-15 catalogue sweep).
 *
 * Guards that the lane's listed PHP files carry NO raw user-visible literal in
 * the flash / validation-bag / abort-message / JSON-response shapes, that every
 * __() literal in those files resolves to a lang/en.json key whose value equals
 * the key, that en.json stays SORT_STRING-sorted, and that the named Inertia
 * label producers now pass their display strings through __().
 *
 * Pure file scans. No database, no HTTP. DB-free.
 */
final class PhpGaps_gap_php_supportTest extends TestCase
{
    /** The lane's sweep list (directories mean every *.php inside, recursively). */
    private const TARGETS = [
        'app/Support',
        'app/Domain/Forms/FormRegistry.php',
        'app/Http/Controllers/Judiciary/ChallengeController.php',
        'app/Http/Controllers/SetupController.php',
        'app/Services/Federation',
        'app/Domain/Engine/SystemOnlyForwardedActor.php',
        'app/Services/Districting/LeafGiantResolver.php',
        'app/Services/Districting/SubdivisionAutoseedService.php',
        'app/Services/Identity',
        'app/Services/Organizations',
        'app/Services/Legislature',
        'app/Services/Judiciary',
    ];

    /** @return list<string> absolute paths of every listed .php file */
    private function files(): array
    {
        $out = [];
        foreach (self::TARGETS as $t) {
            $abs = base_path($t);
            if (is_dir($abs)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile() && $f->getExtension() === 'php') {
                        $out[] = $f->getPathname();
                    }
                }
            } else {
                $this->assertFileExists($abs, "listed target missing: {$t}");
                $out[] = $abs;
            }
        }
        sort($out);

        return $out;
    }

    private function rel(string $abs): string
    {
        return ltrim(str_replace('\\', '/', str_replace(str_replace('\\', '/', base_path()), '', str_replace('\\', '/', $abs))), '/');
    }

    /**
     * (1) Zero raw literals in the flash / validation / abort / JSON shapes.
     * Each regex matches the RAW form only; a __()-wrapped value never matches.
     */
    public function test_no_raw_literals_in_response_shapes(): void
    {
        $shapes = [
            // (a) flash: ->with('status'|'error'|'success'|'warning', '<literal>')
            'flash'      => '/->with\(\s*([\'"])(?:status|error|success|warning)\1\s*,\s*([\'"])/',
            // (b) validation bag: withErrors([.. => \'<literal>\']) / withMessages([.. => \'<literal>\'])
            //     matches static (::), instance (->) and bare calls alike.
            'validation' => '/\bwith(?:Errors|Messages)\(\s*\[[^\]]*=>\s*([\'"])/',
            // (c) abort()/abort_if()/abort_unless() with a string-literal message (one level of nested parens tolerated)
            'abort'      => '/(?<![>\w])abort(?:_if|_unless)?\s*\((?:[^()]|\([^()]*\))*?,\s*([\'"])/',
            // (d) JSON response: \'message\'|\'error\' => \'<literal>\'
            'json'       => '/([\'"])(?:error|message)\1\s*=>\s*([\'"])/',
        ];

        $offenders = [];
        foreach ($this->files() as $abs) {
            $lines = explode("\n", (string) file_get_contents($abs));
            foreach ($lines as $i => $line) {
                foreach ($shapes as $kind => $re) {
                    if (preg_match($re, $line)) {
                        // The abort helper's private-method form ($this->abort) is
                        // excluded by the lookbehind; numeric-only aborts carry no
                        // quote and never match. Any match here is a raw literal.
                        $offenders[] = $this->rel($abs).':'.($i + 1).' ['.$kind.'] '.trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Raw user-visible literals still unwrapped:\n".implode("\n", $offenders));
    }

    /** Every __('literal') first-argument in the listed files. @return array<string,string> value=>file */
    private function translatedLiterals(): array
    {
        // Matches __( '…' ) or __( "…" ) capturing the quoted literal (with escapes).
        $re = '/__\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/';
        $found = [];
        foreach ($this->files() as $abs) {
            $txt = (string) file_get_contents($abs);
            if (preg_match_all($re, $txt, $m)) {
                foreach ($m[1] as $q) {
                    $found[$this->decode($q)] = $this->rel($abs);
                }
            }
        }

        return $found;
    }

    /** Decode a PHP single- or double-quoted literal to its runtime string (the shapes used here). */
    private function decode(string $q): string
    {
        $body = substr($q, 1, -1);
        if ($q[0] === "'") {
            $s = str_replace('\\\\', "\x00", $body);
            $s = str_replace("\\'", "'", $s);

            return str_replace("\x00", '\\', $s);
        }
        $s = str_replace('\\\\', "\x00", $body);
        $s = strtr($s, ['\\"' => '"', '\\n' => "\n", '\\t' => "\t", '\\$' => '$']);

        return str_replace("\x00", '\\', $s);
    }

    /** (2) Every __() literal is a key in en.json whose value equals the key (dotted group keys excepted). */
    public function test_translated_literals_are_catalog_keys(): void
    {
        $en = json_decode((string) file_get_contents(base_path('lang/en.json')), true);
        $this->assertIsArray($en, 'lang/en.json must parse to an array');

        $missing = [];
        foreach ($this->translatedLiterals() as $s => $file) {
            if (! array_key_exists($s, $en)) {
                $missing[] = "MISSING KEY  [{$file}]  ".json_encode($s, JSON_UNESCAPED_UNICODE);
            } elseif ($en[$s] !== $s) {
                $missing[] = "VALUE != KEY [{$file}]  ".json_encode($s, JSON_UNESCAPED_UNICODE);
            }
        }

        $this->assertSame([], $missing, "en.json catalog gaps:\n".implode("\n", $missing));
    }

    /** (3) en.json parses and its keys are SORT_STRING-ordered. */
    public function test_en_json_parses_and_is_sorted(): void
    {
        $raw = (string) file_get_contents(base_path('lang/en.json'));
        $en = json_decode($raw, true);
        $this->assertIsArray($en, 'lang/en.json must parse');

        $keys = array_keys($en);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys, 'lang/en.json keys must be SORT_STRING-sorted');
    }

    /**
     * (4) The named Inertia label producers now pass their display strings through __().
     * Each entry: relative file => regex that must be present after the sweep.
     */
    public function test_named_label_arrays_call_translator(): void
    {
        $asserts = [
            // Form display name at the two FormRegistry consumers.
            ['app/Support/SurfaceMeta.php', '/\'name\'\s*=>\s*__\(\$meta\[\'name\'\]\)/'],
            ['app/Http/Controllers/Judiciary/ChallengeController.php', '/\'name\'\s*=>\s*__\(\$meta\[\'name\'\]\)/'],
            // Institution-act action name + a detail label map value.
            ['app/Support/InstitutionActWorkspace.php', '/\'name\'\s*=>\s*__\(\$meta\[\'name\'\]\).*?\'delegated_scope\'\s*=>\s*__\(\'Delegated powers\'\)/s'],
            // Setup ladder step labels.
            ['app/Support/SetupLadder.php', '/\'label\'\s*=>\s*__\(self::LABELS\[\$n\]\)/'],
            // Jurisdiction place-page nav groups and labels.
            ['app/Support/JurisdictionContext.php', '/\'group\'\s*=>\s*__\(\$group\).*?\'label\'\s*=>\s*__\(\$label\)/s'],
            // 2026-09-15 review findings — Inertia display props that the sweep missed.
            // (e) Directory paginator notice string handed to the page.
            ['app/Support/CandidacyEndorsementDirectory.php', '/\$notice\s*=\s*__\(\'This page link is invalid or belongs to a different selection\. Showing the first page\.\'\)/'],
            // (e) Constitutional-challenge basis option-list labels.
            ['app/Http/Controllers/Judiciary/ChallengeController.php', '/\'label\'\s*=>\s*__\(\'Contradicts the Constitution\'\).*?\'label\'\s*=>\s*__\(\'Contradicts another \(superior\) law\'\)/s'],
            // (e) Step-4 progress stage labels and one-line notes.
            ['app/Http/Controllers/SetupController.php', '/\'label\'\s*=>\s*__\(\'Building the work-list\'\)/'],
            ['app/Http/Controllers/SetupController.php', '/\'note\'\s*=>\s*__\(\'One ledger row per legislature/'],
            ['app/Http/Controllers/SetupController.php', '/\'label\'\s*=>\s*__\(\'Institution shells\'\)/'],
            ['app/Http/Controllers/SetupController.php', '/\'label\'\s*=>\s*__\(\'Seats, committees, departments\'\)/'],
            // (e) Map-data dataset labels.
            ['app/Http/Controllers/SetupController.php', '/\'label\'\s*=>\s*__\(\'Jurisdiction boundaries \(geoBoundaries\)\'\)/'],
            ['app/Http/Controllers/SetupController.php', '/\'label\'\s*=>\s*__\(\'Population \(WorldPop\)\'\)/'],
            ['app/Http/Controllers/SetupController.php', '/\'label\'\s*=>\s*__\(\'Basemap tiles \(Protomaps\)\'\)/'],
        ];

        foreach ($asserts as [$rel, $re]) {
            $txt = (string) file_get_contents(base_path($rel));
            $this->assertMatchesRegularExpression($re, $txt, "expected wrapped label producer in {$rel}");
        }
    }
}
