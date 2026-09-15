<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Flatten the published Laravel language arrays (lang/en/*.php) into
 * lang/en.json as dotted keys, so the translation pass carries validation,
 * auth, pagination and password messages like every other PHP string.
 *
 * The framework consults the JSON lines for a locale before the PHP arrays
 * (Translator::get), so a translated "validation.required" line in
 * lang/<locale>.json is what a resident reads. The English JSON line equals
 * the English PHP value, so nothing changes for the English reader.
 *
 * validation.custom and validation.attributes are per-app placeholders, not
 * user text; they stay in the PHP file only.
 */
class I18nLangFlattenCommand extends Command
{
    protected $signature = 'i18n:lang-flatten {--check : report only, write nothing}';

    protected $description = 'Merge the string leaves of lang/en/*.php into lang/en.json as dotted keys';

    /** @var list<string> */
    public const SKIP = ['validation.custom', 'validation.attributes'];

    public function handle(): int
    {
        $langDir = base_path('lang');
        $jsonPath = $langDir.'/en.json';
        $leaves = self::leaves($langDir.'/en');
        $current = is_file($jsonPath)
            ? (json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR) ?: [])
            : [];

        $added = 0;
        $changed = 0;
        foreach ($leaves as $key => $text) {
            if (! array_key_exists($key, $current)) {
                $added++;
            } elseif ($current[$key] !== $text) {
                $changed++;
            }
            $current[$key] = $text;
        }
        ksort($current, SORT_STRING);

        $this->line(sprintf('leaves %d, added %d, changed %d, total %d', count($leaves), $added, $changed, count($current)));
        if ($this->option('check')) {
            return ($added + $changed) === 0 ? self::SUCCESS : self::FAILURE;
        }
        file_put_contents(
            $jsonPath,
            json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
        $this->line('wrote lang/en.json');

        return self::SUCCESS;
    }

    /**
     * Dotted key => string for every string leaf under lang/<locale>/*.php,
     * minus the SKIP subtrees. Pure: no app state, so tests read it directly.
     *
     * @return array<string, string>
     */
    public static function leaves(string $dir): array
    {
        $out = [];
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $group = basename($file, '.php');
            $array = require $file;
            if (! is_array($array)) {
                continue;
            }
            self::walk($group, $array, $out);
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /** @param array<string, string> $out */
    private static function walk(string $prefix, array $array, array &$out): void
    {
        foreach ($array as $k => $v) {
            $key = $prefix.'.'.$k;
            foreach (self::SKIP as $skip) {
                if ($key === $skip || str_starts_with($key, $skip.'.')) {
                    continue 2;
                }
            }
            if (is_array($v)) {
                self::walk($key, $v, $out);
            } elseif (is_string($v) && $v !== '') {
                $out[$key] = $v;
            }
        }
    }
}
