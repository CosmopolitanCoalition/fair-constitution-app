<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Catalogue pin for lane php-machinery.
 *
 * This lane wires the i18n tooling, not PHP page code. Its files are the three
 * translation scripts. The scripts are Python, so they carry no
 * ConstitutionalViolation throws and no __() calls. Checks 1 and 2 therefore
 * confirm the absence and pass with an empty offender list. Check 3 pins the
 * shared lang/en.json catalog. Check 5 is the lane's real work: the export and
 * import scripts run their built-in self-test with the lang/<locale>.json
 * namespace included.
 */
class PhpCatalogue_php_machineryTest extends TestCase
{
    /** Files this lane wired, relative to the worktree root. */
    private const LANE_FILES = [
        'scripts/i18n/export_master.py',
        'scripts/i18n/import_translated.py',
        'scripts/i18n/translate_catalog.py',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function catalogPath(): string
    {
        return $this->root().'/lang/en.json';
    }

    private function catalog(): array
    {
        $data = json_decode((string) @file_get_contents($this->catalogPath()), true);

        return is_array($data) ? $data : [];
    }

    /** 1) No literal-message ConstitutionalViolation throw sits outside __(). */
    public function test_no_unwrapped_constitutional_violation_messages(): void
    {
        $offenders = [];
        foreach (self::LANE_FILES as $rel) {
            $path = $this->root().'/'.$rel;
            $this->assertFileExists($path);
            foreach (file($path, FILE_IGNORE_NEW_LINES) as $i => $line) {
                if (preg_match('/ConstitutionalViolation\s*\(\s*[\'"]/', $line)
                    && ! preg_match('/ConstitutionalViolation\s*\(\s*__\(/', $line)) {
                    $offenders[] = $rel.':'.($i + 1);
                }
            }
        }
        $this->assertSame([], $offenders,
            'literal-message ConstitutionalViolation throws outside __(): '.implode(', ', $offenders));
    }

    /** 2) Every __('...') literal in the lane files is a key in lang/en.json, value equal to key. */
    public function test_every_wrapped_string_is_a_catalog_key(): void
    {
        $catalog = $this->catalog();
        $missing = [];
        foreach (self::LANE_FILES as $rel) {
            $src = (string) file_get_contents($this->root().'/'.$rel);
            if (preg_match_all('/__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $src, $m)) {
                foreach ($m[1] as $raw) {
                    $key = stripcslashes($raw);
                    if (! array_key_exists($key, $catalog)) {
                        $missing[] = $rel.' -> absent: '.$key;
                    } elseif ($catalog[$key] !== $key) {
                        $missing[] = $rel.' -> value != key: '.$key;
                    }
                }
            }
        }
        $this->assertSame([], $missing,
            '__() strings absent or mis-valued in lang/en.json: '.implode(', ', $missing));
    }

    /** 3) lang/en.json parses, keys are sorted, and each value equals its key. */
    public function test_catalog_parses_and_keys_sorted(): void
    {
        $path = $this->catalogPath();
        $this->assertFileExists($path, 'lang/en.json is the shared PHP catalog');
        $catalog = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($catalog, 'lang/en.json parses to a JSON object');

        $keys = array_keys($catalog);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys, 'lang/en.json keys are sorted');

        foreach ($catalog as $k => $v) {
            $this->assertSame($k, $v, "lang/en.json value equals key for [$k]");
        }
    }

    /** 5) The export and import scripts self-test green with the lang namespace included. */
    public function test_python_self_tests_pass_with_lang_namespace(): void
    {
        $python = $this->python();
        $this->assertNotNull($python, 'python3 is required to run the i18n script self-tests');

        foreach (['scripts/i18n/export_master.py', 'scripts/i18n/import_translated.py'] as $rel) {
            $cmd = escapeshellarg($python).' '.escapeshellarg($this->root().'/'.$rel).' --self-test 2>&1';
            $out = [];
            $code = 1;
            exec($cmd, $out, $code);
            $joined = implode("\n", $out);
            $this->assertSame(0, $code, "$rel --self-test failed:\n$joined");
            $this->assertStringContainsString('lang', $joined,
                "$rel --self-test did not exercise the lang namespace:\n$joined");
            $this->assertMatchesRegularExpression('/\bpassed\b/', $joined,
                "$rel --self-test produced no pass line:\n$joined");
        }
    }

    private function python(): ?string
    {
        foreach (['python3', 'python'] as $bin) {
            $which = trim((string) shell_exec('command -v '.escapeshellarg($bin).' 2>/dev/null'));
            if ($which !== '') {
                return $which;
            }
        }

        return null;
    }
}
