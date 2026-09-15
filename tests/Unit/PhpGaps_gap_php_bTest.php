<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Gap lane gap-php-b (php2 kind) — the i18n regression pin for PHP response
 * strings across the Civic / Organizations / Economy / Jurisdictions /
 * Federation / Rooms / Operator / System / Media controllers, the root
 * JurisdictionController, and app/Services/Rooms.
 *
 * The lane wraps every user-visible PHP string produced for display with
 * __(): flash messages (->with('status'|'error'|…)), validation bags
 * (withErrors / ValidationException::withMessages), human-readable abort()
 * messages, response()->json() 'message'/'error' literals, and the display
 * labels handed to Inertia props. It leaves raw: machine tokens (kebab
 * status slugs, snake_case peer error codes), keys, and $variable messages.
 *
 * This DB-free test guards against regressions:
 *   (1) no raw display literal survives in shapes (a)-(d);
 *   (2) every __('...') literal in scope is a key in lang/en.json whose value
 *       equals the key;
 *   (3) lang/en.json parses and its keys are sorted SORT_STRING;
 *   (4) the Inertia label arrays the lane wrapped now call __().
 */
class PhpGaps_gap_php_bTest extends TestCase
{
    /** Directories in scope; every .php file inside, recursively. */
    private const DIRS = [
        'app/Http/Controllers/Civic',
        'app/Http/Controllers/Organizations',
        'app/Http/Controllers/Economy',
        'app/Http/Controllers/Jurisdictions',
        'app/Http/Controllers/Federation',
        'app/Http/Controllers/Rooms',
        'app/Http/Controllers/Operator',
        'app/Http/Controllers/System',
        'app/Http/Controllers/Media',
        'app/Services/Rooms',
    ];

    /** Single files in scope. */
    private const FILES = [
        'app/Http/Controllers/JurisdictionController.php',
    ];

    /** Owned by other lanes — excluded from this pin. */
    private const EXCLUDE = [
        'app/Http/Controllers/Civic/JourneysController.php',
        'app/Http/Controllers/System/ClocksController.php',
    ];

    /**
     * The intentionally-raw machine tokens: peer-to-peer JSON error CODES
     * consumed by another server, never shown to a person. They carry no
     * whitespace, which is how the shape checks below tell them from display
     * text.
     */
    private const RAW_JSON_TOKENS = [
        'replay_detected',
        'cross_class_peering_refused',
        'forward_in_flight',
        'not_found',
        'unknown_proposal',
    ];

    /** @return list<string> absolute paths of every scope file. */
    private function scopeFiles(): array
    {
        $base = base_path();
        $out = [];
        foreach (self::FILES as $rel) {
            $out[] = $base.'/'.$rel;
        }
        foreach (self::DIRS as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base.'/'.$dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if (strtolower($f->getExtension()) === 'php') {
                    $out[] = $f->getPathname();
                }
            }
        }
        $exclude = array_map(fn ($r) => $base.'/'.$r, self::EXCLUDE);
        $out = array_values(array_filter(array_unique($out), fn ($p) => ! in_array($p, $exclude, true)
            && str_replace('\\', '/', $p) !== '' // keep
            && ! in_array(str_replace('\\', '/', $p), array_map(fn ($e) => str_replace('\\', '/', $e), $exclude), true)));

        sort($out);

        return $out;
    }

    /** Comment-strip while preserving line numbers (blank comments keep their newlines). */
    private function codeOnly(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $tok) {
            if (is_array($tok)) {
                if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                } else {
                    $out .= $tok[1];
                }
            } else {
                $out .= $tok;
            }
        }

        return $out;
    }

    private function lineAt(string $src, int $offset): int
    {
        return substr_count(substr($src, 0, $offset), "\n") + 1;
    }

    /** Decode a PHP single/double quoted literal (with quotes) to its runtime value. */
    private function literalValue(string $lit): string
    {
        $q = $lit[0];
        $inner = substr($lit, 1, -1);

        if ($q === "'") {
            return strtr($inner, ["\\'" => "'", '\\\\' => '\\']);
        }

        // Double-quoted: unescape the common sequences; interpolation is not used
        // in this lane's wrapped strings, so a plain unescape is sufficient here.
        return strtr($inner, ['\\"' => '"', '\\\\' => '\\', '\\n' => "\n", '\\t' => "\t"]);
    }

    /** Index of the ) matching the ( at $open, respecting quotes and nested brackets. */
    private function matchingParen(string $s, int $open): int
    {
        $depth = 0;
        $n = strlen($s);
        for ($i = $open; $i < $n; $i++) {
            $c = $s[$i];
            if ($c === "'" || $c === '"') {
                $i = $this->skipString($s, $i);
                continue;
            }
            if ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $n - 1;
    }

    /** Given index of an opening quote, return the index of the matching close quote. */
    private function skipString(string $s, int $i): int
    {
        $q = $s[$i];
        $n = strlen($s);
        for ($j = $i + 1; $j < $n; $j++) {
            if ($s[$j] === '\\') {
                $j++;
                continue;
            }
            if ($s[$j] === $q) {
                return $j;
            }
        }

        return $n - 1;
    }

    /** Split a call's inner text on top-level commas (respecting quotes/brackets). */
    private function splitArgs(string $inner): array
    {
        $args = [];
        $buf = '';
        $depth = 0;
        $n = strlen($inner);
        for ($i = 0; $i < $n; $i++) {
            $c = $inner[$i];
            if ($c === "'" || $c === '"') {
                $end = $this->skipString($inner, $i);
                $buf .= substr($inner, $i, $end - $i + 1);
                $i = $end;
                continue;
            }
            if ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
                $buf .= $c;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                $depth--;
                $buf .= $c;
            } elseif ($c === ',' && $depth === 0) {
                $args[] = trim($buf);
                $buf = '';
            } else {
                $buf .= $c;
            }
        }
        if (trim($buf) !== '') {
            $args[] = trim($buf);
        }

        return $args;
    }

    // =========================================================================
    // (1) no raw display literal in shapes (a)-(d)
    // =========================================================================

    public function test_no_raw_display_literals_in_scope(): void
    {
        $base = str_replace('\\', '/', base_path());
        $offenders = [];

        foreach ($this->scopeFiles() as $path) {
            $src = $this->codeOnly((string) file_get_contents($path));
            $rel = ltrim(str_replace($base, '', str_replace('\\', '/', $path)), '/');

            $offenders = array_merge($offenders, $this->flashOffenders($src, $rel));      // (a)
            $offenders = array_merge($offenders, $this->validationOffenders($src, $rel));  // (b)
            $offenders = array_merge($offenders, $this->abortOffenders($src, $rel));       // (c)
            $offenders = array_merge($offenders, $this->jsonOffenders($src, $rel));        // (d)
        }

        $this->assertSame([], $offenders, "Raw display literals still present:\n".implode("\n", $offenders));
    }

    /** (a) ->with('status'|'error'|'success'|'warning'|'info', '<display>') not wrapped. */
    private function flashOffenders(string $src, string $rel): array
    {
        $out = [];
        $re = '/->with\(\s*[\'"](?:status|error|success|warning|info)[\'"]\s*,\s*(\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")/s';
        if (preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $hit) {
                $val = $this->literalValue($hit[0]);
                if (preg_match('/\s/', $val)) { // display text carries whitespace; machine slugs never do
                    $out[] = $rel.':'.$this->lineAt($src, $hit[1]).'  (a) raw flash: '.$val;
                }
            }
        }

        return $out;
    }

    /** (b) withErrors([... => '<msg>']) / ValidationException::withMessages([... => '<msg>']) not wrapped. */
    private function validationOffenders(string $src, string $rel): array
    {
        $out = [];
        if (preg_match_all('/\b(?:withErrors|withMessages)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $open = strpos($src, '(', $hit[1]);
                if ($open === false) {
                    continue;
                }
                $close = $this->matchingParen($src, $open);
                $inner = substr($src, $open, $close - $open + 1);
                // A raw string VALUE follows a `=>`; the field KEY sits before it.
                if (preg_match_all('/=>\s*(\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")/s', $inner, $vm, PREG_OFFSET_CAPTURE)) {
                    foreach ($vm[1] as $v) {
                        $out[] = $rel.':'.$this->lineAt($src, $open + $v[1]).'  (b) raw validation message: '.$this->literalValue($v[0]);
                    }
                }
            }
        }

        return $out;
    }

    /** (c) abort()/abort_if()/abort_unless() whose human-visible message arg is a raw literal. */
    private function abortOffenders(string $src, string $rel): array
    {
        $out = [];
        if (preg_match_all('/\babort(?:_if|_unless)?\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $open = strpos($src, '(', $hit[1]);
                if ($open === false) {
                    continue;
                }
                $close = $this->matchingParen($src, $open);
                $inner = substr($src, $open + 1, $close - $open - 1);
                $args = $this->splitArgs($inner);
                if ($args === []) {
                    continue;
                }
                $last = end($args);
                // A message is present only when the final arg is a bare string
                // literal. __('...') starts with `_`; a $variable with `$`.
                if ($last !== '' && ($last[0] === "'" || $last[0] === '"')) {
                    $out[] = $rel.':'.$this->lineAt($src, $open).'  (c) raw abort message: '.$this->literalValue($last);
                }
            }
        }

        return $out;
    }

    /** (d) response()->json() 'message'/'error' => '<display>' not wrapped (machine tokens excepted). */
    private function jsonOffenders(string $src, string $rel): array
    {
        $out = [];
        $re = '/[\'"](?:message|error)[\'"]\s*=>\s*(\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")/s';
        if (preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $hit) {
                $val = $this->literalValue($hit[0]);
                if (in_array($val, self::RAW_JSON_TOKENS, true)) {
                    continue; // peer-to-peer machine error code, left raw by design
                }
                if (preg_match('/\s/', $val)) { // display text; single-word codes carry no whitespace
                    $out[] = $rel.':'.$this->lineAt($src, $hit[1]).'  (d) raw json message: '.$val;
                }
            }
        }

        return $out;
    }

    // =========================================================================
    // (2) every __('...') literal in scope is an en.json key whose value == key
    // =========================================================================

    public function test_every_wrapped_literal_is_a_catalog_key(): void
    {
        $catalog = $this->catalog();
        $missing = [];

        foreach ($this->scopeFiles() as $path) {
            foreach ($this->wrappedLiterals((string) file_get_contents($path)) as $key) {
                // Dotted group keys (e.g. validation.required) carry English text,
                // not an identity value; none are produced by this lane, but skip
                // them defensively if one ever appears.
                if (preg_match('/^[a-z0-9_]+(?:\.[a-z0-9_]+)+$/', $key)) {
                    continue;
                }
                if (! array_key_exists($key, $catalog)) {
                    $missing[] = 'absent: '.$key;
                } elseif ($catalog[$key] !== $key) {
                    $missing[] = 'value!=key: '.$key.' => '.$catalog[$key];
                }
            }
        }

        $this->assertSame([], $missing, "en.json gaps:\n".implode("\n", $missing));
    }

    /** @return list<string> the runtime value of every __('...') single-quoted literal in $src. */
    private function wrappedLiterals(string $src): array
    {
        $keys = [];
        $toks = token_get_all($src);
        $n = count($toks);
        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            if (is_array($t) && $t[0] === T_STRING && $t[1] === '__') {
                $j = $i + 1;
                while (isset($toks[$j]) && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if (isset($toks[$j]) && $toks[$j] === '(') {
                    $k = $j + 1;
                    while (isset($toks[$k]) && is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) {
                        $k++;
                    }
                    if (isset($toks[$k]) && is_array($toks[$k]) && $toks[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $lit = $toks[$k][1];
                        if ($lit[0] === "'") {
                            $keys[$this->literalValue($lit)] = true;
                        }
                    }
                }
            }
        }

        return array_keys($keys);
    }

    // =========================================================================
    // (3) en.json parses and its keys are sorted SORT_STRING
    // =========================================================================

    public function test_catalog_parses_and_is_sorted(): void
    {
        $raw = file_get_contents(base_path('lang/en.json'));
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'lang/en.json does not parse as a JSON object');

        $keys = array_keys($decoded);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys, 'lang/en.json keys are not SORT_STRING sorted');
    }

    // =========================================================================
    // (4) the named Inertia label arrays now call __()
    // =========================================================================

    public function test_named_label_arrays_call_translate(): void
    {
        $checks = [
            // PublicSquareController::communityStandards() — the shipped card.
            ['app/Http/Controllers/Civic/PublicSquareController.php', "'headline' => __("],
            ['app/Http/Controllers/Civic/PublicSquareController.php', "'no_viewpoint_path' => __("],
            // OrganizationController::structureGloss() — the ownership-structure hints.
            ['app/Http/Controllers/Organizations/OrganizationController.php', 'Organization::STRUCTURE_STOCK => __('],
            // CoDeterminationController owner-side label.
            ['app/Http/Controllers/Organizations/CoDeterminationController.php', "? __('appointed governors') : __('shareholder-elected')"],
            // LiveRoomController chairControls[] labels.
            ['app/Http/Controllers/Rooms/LiveRoomController.php', "__('Recognize the next speaker')"],
            // RoomDirectoryController commons[] titles.
            ['app/Http/Controllers/Rooms/RoomDirectoryController.php', "'title' => __('Public square')"],
            // AtlasController privacy.rails[] and ctas[] copy.
            ['app/Http/Controllers/System/AtlasController.php', "__('Opt-in only — nobody is placed on the map without choosing to be')"],
            ['app/Http/Controllers/System/AtlasController.php', "'cta' => __('Stand for office')"],
            // MeshConsoleController meters[].label copy.
            ['app/Http/Controllers/Operator/MeshConsoleController.php', "'label' => __('Meter A — the operator board')"],
        ];

        $failures = [];
        foreach ($checks as [$rel, $needle]) {
            $src = (string) file_get_contents(base_path($rel));
            if (! str_contains($src, $needle)) {
                $failures[] = $rel.'  is missing  '.$needle;
            }
        }

        $this->assertSame([], $failures, "Label arrays not wired:\n".implode("\n", $failures));
    }

    /** @return array<string,mixed> */
    private function catalog(): array
    {
        return json_decode((string) file_get_contents(base_path('lang/en.json')), true) ?? [];
    }
}
