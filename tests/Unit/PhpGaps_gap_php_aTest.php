<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Gap lane gap-php-a (kind php2) pin. DB-free.
 *
 * Guards the PHP response-string wiring for the lane's listed files:
 *   app/Http/Controllers/Legislature/**            (recursive)
 *   app/Http/Controllers/Judiciary/**              (recursive, EXCLUDES ChallengeController.php — another lane)
 *   app/Http/Controllers/Elections/**              (recursive)
 *   app/Http/Controllers/Executive/**              (recursive)
 *   app/Http/Controllers/LegislatureController.php
 *
 * (1) Zero RAW user-visible literals remain in shapes (a) flash/status,
 *     (b) validation bags, (c) abort messages, (d) JSON message/error.
 *     A `__(` at the value position counts as wrapped.
 * (2) Every __('literal') in the listed files is a key in lang/en.json whose
 *     value equals the key (dotted validation-group keys excepted).
 * (3) lang/en.json parses and its keys are SORT_STRING ordered.
 * (4) The controller-built Inertia label maps that were converted to __()
 *     still call __().
 */
final class PhpGaps_gap_php_aTest extends TestCase
{
    /** @return list<string> absolute paths of the lane's listed files */
    private function listedFiles(): array
    {
        $base = base_path();
        $dirs = [
            $base.'/app/Http/Controllers/Legislature',
            $base.'/app/Http/Controllers/Judiciary',
            $base.'/app/Http/Controllers/Elections',
            $base.'/app/Http/Controllers/Executive',
        ];
        $files = [];
        foreach ($dirs as $d) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php') {
                    continue;
                }
                $p = str_replace('\\', '/', $f->getPathname());
                if (str_ends_with($p, '/Judiciary/ChallengeController.php')) {
                    continue; // owned by another lane
                }
                $files[] = $p;
            }
        }
        $files[] = $base.'/app/Http/Controllers/LegislatureController.php';
        sort($files);
        return $files;
    }

    public function test_no_raw_literals_in_response_shapes(): void
    {
        // Each offender pattern matches the shape's value position followed
        // immediately by a RAW quote. A wrapped value ("__(" ) or an
        // expression ($var, ternary, array) never starts with a quote here,
        // so it is not matched.
        $patterns = [
            'flash/->with' => "/->with\\(\\s*'(?:status|error|success|warning|info)'\\s*,\\s*['\"]/",
            'withErrors'   => "/withErrors\\(\\s*\\[\\s*'[^']+'\\s*=>\\s*['\"]/",
            'withMessages' => "/withMessages\\(\\s*\\[\\s*'[^']+'\\s*=>\\s*['\"]/",
            'abort_if/unless' => "/\\babort(?:_if|_unless)?\\([^;]*?,\\s*(?:400|401|403|404|409|410|422|423|500)\\s*,\\s*['\"]/",
            'abort()'      => "/\\babort\\(\\s*(?:400|401|403|404|409|410|422|423|500)\\s*,\\s*['\"]/",
            'json message' => "/'message'\\s*=>\\s*['\"]/",
            'json error'   => "/'error'\\s*=>\\s*['\"]/",
        ];

        $offenders = [];
        foreach ($this->listedFiles() as $file) {
            $lines = file($file);
            $src = implode('', $lines);
            foreach ($patterns as $label => $rx) {
                if (preg_match_all($rx, $src, $m, PREG_OFFSET_CAPTURE)) {
                    foreach ($m[0] as $hit) {
                        $line = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
                        $rel = str_replace(base_path().'/', '', $file);
                        $offenders[] = "$rel:$line [$label] ".trim($lines[$line - 1] ?? '');
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Raw response-string literals remain:\n".implode("\n", $offenders));
    }

    public function test_every_wrapped_literal_is_a_catalog_key(): void
    {
        $catalog = $this->catalog();
        $missing = [];
        foreach ($this->listedFiles() as $file) {
            foreach ($this->wrappedLiterals($file) as $line => $key) {
                if (!array_key_exists($key, $catalog)) {
                    $missing[] = str_replace(base_path().'/', '', $file).":$line missing key ".var_export($key, true);
                } elseif ($catalog[$key] !== $key) {
                    $missing[] = str_replace(base_path().'/', '', $file).":$line value!=key for ".var_export($key, true);
                }
            }
        }
        $this->assertSame([], $missing, "Wrapped literals absent from lang/en.json (or value!=key):\n".implode("\n", $missing));
    }

    public function test_catalog_parses_and_is_sort_string_ordered(): void
    {
        $path = base_path().'/lang/en.json';
        $raw = file_get_contents($path);
        $cat = json_decode($raw, true);
        $this->assertIsArray($cat, 'lang/en.json does not parse');

        $keys = array_keys($cat);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys, 'lang/en.json keys are not SORT_STRING ordered');
    }

    public function test_named_label_maps_call_translate(): void
    {
        $base = base_path();
        // (file, method-name) — each returns an Inertia label array that was
        // converted from a raw const map to a __()-wrapped builder.
        $methods = [
            ['app/Http/Controllers/Judiciary/AdvocateController.php', 'filingTypes'],
            ['app/Http/Controllers/Judiciary/AdvocateController.php', 'stateLabels'],
            ['app/Http/Controllers/Judiciary/CaseController.php', 'stages'],
            ['app/Http/Controllers/Judiciary/CaseController.php', 'severityDisplay'],
            ['app/Http/Controllers/Judiciary/CaseController.php', 'kindDisplay'],
            ['app/Http/Controllers/Judiciary/DocketController.php', 'kindDisplay'],
            ['app/Http/Controllers/Judiciary/DocketController.php', 'severityDisplay'],
            ['app/Http/Controllers/Judiciary/DocketController.php', 'admLabels'],
            ['app/Http/Controllers/Judiciary/JurorController.php', 'questions'],
            ['app/Http/Controllers/Judiciary/JudiciaryController.php', 'panelRule'],
            ['app/Http/Controllers/Legislature/SettingsController.php', 'meta'],
        ];

        foreach ($methods as [$rel, $name]) {
            $src = file_get_contents($base.'/'.$rel);
            // Body from "function <name>(): array" to the method's closing
            // "\n    }" (these methods hold only a returned array literal).
            $this->assertMatchesRegularExpression(
                '/function\s+'.preg_quote($name, '/').'\s*\(\s*\)\s*:\s*array\s*\{.*?__\(.*?\n    \}/s',
                $src,
                "$rel::$name() should build its label array with __()"
            );
        }

        // BillController act-type option list (inline array, not a method).
        $bill = file_get_contents($base.'/app/Http/Controllers/Legislature/BillController.php');
        $this->assertMatchesRegularExpression(
            "/'actTypes'\\s*=>\\s*\\[.*?'label'\\s*=>\\s*__\\(.*?\\],/s",
            $bill,
            'BillController actTypes option labels should call __()'
        );

        // TypeBMapController overrides the shared surface title with Type B's
        // own term. That override is a shape-(e) display label handed to
        // Inertia and must be wrapped.
        $typeb = file_get_contents($base.'/app/Http/Controllers/Legislature/TypeBMapController.php');
        $this->assertMatchesRegularExpression(
            "/\\\$surface\\['title'\\]\\s*=\\s*__\\(/",
            $typeb,
            'TypeBMapController surface title override should call __()'
        );
    }

    /**
     * A display-prop title assigned by statement ($var['title'] = ...) must
     * never be a raw quoted literal in a listed file. This guards the surface
     * title override class the pin's method list did not cover. The array
     * ENTRY form ('title' => ...) is out of scope here: it also carries
     * persisted engine-payload titles that are deliberately not display props.
     */
    public function test_no_raw_title_assignment_literals(): void
    {
        $rx = "/\\['title'\\]\\s*=\\s*['\"]/";
        $offenders = [];
        foreach ($this->listedFiles() as $file) {
            $lines = file($file);
            $src = implode('', $lines);
            if (preg_match_all($rx, $src, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $hit) {
                    $line = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
                    $rel = str_replace(base_path().'/', '', $file);
                    $offenders[] = "$rel:$line ".trim($lines[$line - 1] ?? '');
                }
            }
        }
        $this->assertSame([], $offenders, "Raw display-title assignment literals remain:\n".implode("\n", $offenders));
    }

    /** @return array<string,string> */
    private function catalog(): array
    {
        $cat = json_decode(file_get_contents(base_path().'/lang/en.json'), true);
        $this->assertIsArray($cat);
        return $cat;
    }

    /**
     * Every __('literal') first-argument in a file, keyed by line number.
     *
     * @return array<int,string>
     */
    private function wrappedLiterals(string $file): array
    {
        $src = file_get_contents($file);
        $tokens = token_get_all($src);
        $n = count($tokens);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && $t[0] === T_STRING && $t[1] === '__') {
                $j = $i + 1;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if ($j < $n && $tokens[$j] === '(') {
                    $k = $j + 1;
                    while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                        $k++;
                    }
                    if ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $val = $this->decodeLiteral($tokens[$k][1]);
                        if ($val !== null) {
                            $out[$tokens[$k][2]] = $val;
                        }
                    }
                }
            }
        }
        return $out;
    }

    private function decodeLiteral(string $lit): ?string
    {
        $q = $lit[0];
        $inner = substr($lit, 1, -1);
        if ($q === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
        }
        if ($q === '"') {
            if (str_contains($inner, '$')) {
                return null;
            }
            return stripcslashes($inner);
        }
        return null;
    }
}
