<?php

namespace Tests\Unit;

use App\Support\SurfaceMeta;
use Tests\TestCase;

/**
 * Catalogue pin for the php-surfaces lane.
 *
 * The lane owns config/cga/surfaces.php (raw English, cached) and
 * App\Support\SurfaceMeta, which wraps the display strings with __() when it
 * builds the surface array. This pin is DB-free.
 */
class PhpCatalogue_php_surfacesTest extends TestCase
{
    /** Files the php-surfaces lane wires. */
    private const LANE_FILES = [
        'config/cga/surfaces.php',
        'app/Support/SurfaceMeta.php',
    ];

    /** (1) No literal-message ConstitutionalViolation throw sits outside __(). */
    public function test_no_literal_constitutional_violation_throws(): void
    {
        $offenders = [];

        foreach (self::LANE_FILES as $rel) {
            $path = base_path($rel);
            $tokens = token_get_all(file_get_contents($path));

            for ($i = 0, $n = count($tokens); $i < $n; $i++) {
                $t = $tokens[$i];
                if (! is_array($t) || $t[0] !== T_STRING || $t[1] !== 'ConstitutionalViolation') {
                    continue;
                }

                // Find the opening paren of the call.
                $j = $i + 1;
                while ($j < $n && $this->isSkippable($tokens[$j])) {
                    $j++;
                }
                if ($j >= $n || $tokens[$j] !== '(') {
                    continue;
                }

                // First real argument token.
                $k = $j + 1;
                while ($k < $n && $this->isSkippable($tokens[$k])) {
                    $k++;
                }
                if ($k >= $n) {
                    continue;
                }

                $arg = $tokens[$k];
                // A wrapped message is __( ... ) — the first arg is the T_STRING '__'.
                $wrapped = is_array($arg) && $arg[0] === T_STRING && $arg[1] === '__';
                $literal = is_array($arg) && $arg[0] === T_CONSTANT_ENCAPSED_STRING;

                if ($literal && ! $wrapped) {
                    $offenders[] = $rel.':'.$arg[2];
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Literal ConstitutionalViolation messages outside __():\n".implode("\n", $offenders)
        );
    }

    /** (2) Every literal __('...') first argument is a catalog key with value == key. */
    public function test_literal_translation_calls_are_catalog_keys(): void
    {
        $catalog = $this->catalog();
        $missing = [];

        foreach (self::LANE_FILES as $rel) {
            foreach ($this->literalTranslationStrings(base_path($rel)) as [$string, $line]) {
                if (! array_key_exists($string, $catalog) || $catalog[$string] !== $string) {
                    $missing[] = $rel.':'.$line.'  '.$string;
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "__() strings absent from lang/en.json (or value != key):\n".implode("\n", $missing)
        );
    }

    /** (3) lang/en.json parses, keys sorted, values equal keys. */
    public function test_catalog_parses_and_is_sorted(): void
    {
        $raw = file_get_contents(base_path('lang/en.json'));
        $data = json_decode($raw, true);

        $this->assertIsArray($data, 'lang/en.json does not parse as a JSON object.');

        $keys = array_keys($data);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys, 'lang/en.json keys are not sorted (SORT_STRING).');

        foreach ($data as $key => $value) {
            $this->assertSame($key, $value, "Catalog value differs from key for [{$key}].");
        }
    }

    /** (4) SurfaceMeta wraps title and citation via __(), and every config display string is catalogued. */
    public function test_surface_meta_wraps_display_strings(): void
    {
        $src = file_get_contents(base_path('app/Support/SurfaceMeta.php'));

        // The build array wraps the title and the footer citation with __().
        $this->assertMatchesRegularExpression("/'title'\s*=>\s*__\(/", $src, 'title is not wrapped with __().');
        $this->assertMatchesRegularExpression("/'citation'\s*=>\s*isset\([^)]*\)\s*\?\s*__\(/", $src, 'top-level citation is not wrapped with __().');

        // Behavioural: for() runs DB-free and returns the English default.
        $home = SurfaceMeta::for('civic/home');
        $this->assertSame('Civic home', $home['title']);

        // Coverage: every display string the config carries is a catalog key.
        $catalog = $this->catalog();
        $uncatalogued = [];
        foreach (config('cga.surfaces', []) as $id => $record) {
            if (! is_array($record)) {
                continue;
            }
            foreach ($this->displayStrings($record) as $string) {
                if (! array_key_exists($string, $catalog) || $catalog[$string] !== $string) {
                    $uncatalogued[] = $id.'  '.$string;
                }
            }
        }

        $this->assertSame(
            [],
            $uncatalogued,
            "Config display strings absent from lang/en.json:\n".implode("\n", $uncatalogued)
        );
    }

    /** @return array<string,string> */
    private function catalog(): array
    {
        $data = json_decode(file_get_contents(base_path('lang/en.json')), true);

        return is_array($data) ? $data : [];
    }

    /** Title, footer citation, and per-form citation — the strings SurfaceMeta wraps. */
    private function displayStrings(array $record): array
    {
        $out = [];
        if (isset($record['title']) && is_string($record['title']) && $record['title'] !== '') {
            $out[] = $record['title'];
        }
        if (isset($record['citation']) && is_string($record['citation']) && $record['citation'] !== '') {
            $out[] = $record['citation'];
        }
        foreach (($record['forms'] ?? []) as $form) {
            if (is_array($form) && isset($form['citation']) && is_string($form['citation']) && $form['citation'] !== '') {
                $out[] = $form['citation'];
            }
        }

        return $out;
    }

    /** All literal first-argument __() strings in a file, with line numbers. */
    private function literalTranslationStrings(string $path): array
    {
        $tokens = token_get_all(file_get_contents($path));
        $out = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if (! is_array($t) || $t[0] !== T_STRING || $t[1] !== '__') {
                continue;
            }

            $j = $i + 1;
            while ($j < $n && $this->isSkippable($tokens[$j])) {
                $j++;
            }
            if ($j >= $n || $tokens[$j] !== '(') {
                continue;
            }

            $k = $j + 1;
            while ($k < $n && $this->isSkippable($tokens[$k])) {
                $k++;
            }
            if ($k >= $n) {
                continue;
            }

            $arg = $tokens[$k];
            if (is_array($arg) && $arg[0] === T_CONSTANT_ENCAPSED_STRING) {
                $out[] = [$this->decodeLiteral($arg[1]), $arg[2]];
            }
        }

        return $out;
    }

    /** Whitespace and comments never break a call sequence. */
    private function isSkippable($token): bool
    {
        return is_array($token)
            && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /** Decode a PHP single- or double-quoted string literal to its value. */
    private function decodeLiteral(string $raw): string
    {
        $quote = $raw[0];
        $body = substr($raw, 1, -1);

        if ($quote === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
        }

        // Double-quoted: resolve the common escapes.
        return stripcslashes($body);
    }
}
