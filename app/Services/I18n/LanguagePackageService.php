<?php

namespace App\Services\I18n;

use InvalidArgumentException;
use Illuminate\Support\Str;

/**
 * W-0446 — language package export / import (operator ruling 2026-09-15,
 * integrated into the /system/translations board).
 *
 * PURE + DB-FREE. This service only shapes commands, paths and small JSON
 * records under one store directory. It never runs a script, touches the
 * database, or reaches the network. The queued jobs
 * (App\Jobs\I18n\ExportLanguagePackageJob / ImportLanguagePackageJob) own the
 * process execution and the audit write; this class hands them the exact
 * command line and the exact paths, and parses the importer's dry-run report.
 *
 * THE STORE. Everything lives under one base directory,
 * storage/app/i18n-packages by default. A path handed in from a request
 * (a run id, a locale) is normalised and REFUSED when it escapes the store —
 * a `..` in a run id can never reach the catalogs or anything else on disk.
 *
 * TARGET LOCALES ONLY. Export refuses a locale that is not a registry target
 * (locales.generated.js / config('locales') rows with target: true). A row
 * that is display-only is not opened for a machine draft.
 */
class LanguagePackageService
{
    /** Relative to base_path(). Both scripts read/write the real catalogs from here. */
    private const EXPORT_SCRIPT = 'scripts/i18n/export_master.py';
    private const IMPORT_SCRIPT = 'scripts/i18n/import_translated.py';

    /** The coverage refresh, run in the fc_vite container when node is reachable. */
    public const CHECK_SCRIPT = 'scripts/i18n/check.mjs';

    private string $base;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $registry;

    /**
     * @param  string|null  $baseDir   the package store (default storage/app/i18n-packages)
     * @param  array<string, array<string, mixed>>|null  $registry  the locale registry (default config('locales'))
     */
    public function __construct(?string $baseDir = null, ?array $registry = null)
    {
        $this->base = $this->normalize($baseDir ?? storage_path('app/i18n-packages'));
        $this->registry = $registry;
    }

    // ── The store ────────────────────────────────────────────────────────────

    public function baseDir(): string
    {
        return $this->base;
    }

    /**
     * A path inside the store, with `.` and `..` collapsed. A path that would
     * escape the store throws — a run id or locale from a request can never
     * reach anything outside storage/app/i18n-packages.
     */
    public function pathWithin(string $relative): string
    {
        $joined = $this->normalize($this->base . '/' . ltrim(str_replace('\\', '/', $relative), '/'));

        if ($joined !== $this->base && ! str_starts_with($joined, $this->base . '/')) {
            throw new InvalidArgumentException("path escapes the package store: {$relative}");
        }

        return $joined;
    }

    public function runDir(string $run): string
    {
        return $this->pathWithin($this->cleanSegment($run));
    }

    public function exportDir(string $run): string
    {
        return $this->pathWithin($this->cleanSegment($run) . '/export');
    }

    public function importDir(string $run): string
    {
        return $this->pathWithin($this->cleanSegment($run) . '/import');
    }

    /** The finished package zip a locale downloads: <run>/<code>-package.zip. */
    public function packageZipPath(string $run, string $locale): string
    {
        return $this->pathWithin($this->cleanSegment($run) . '/' . $this->cleanSegment($locale) . '-package.zip');
    }

    /** A fresh run id, sortable and unique. */
    public function newRunId(): string
    {
        return now()->format('Ymd-His') . '-' . Str::lower(Str::random(6));
    }

    // ── Locale registry ────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    public function registry(): array
    {
        return $this->registry ?? (array) config('locales.locales', []);
    }

    public function isTargetLocale(string $code): bool
    {
        $row = $this->registry()[$code] ?? null;

        return is_array($row) && ($row['target'] ?? false) === true;
    }

    /** @return list<string> the codes a package may be exported for. */
    public function targetLocales(): array
    {
        return array_values(array_filter(
            array_keys($this->registry()),
            fn (string $c): bool => $c !== 'en' && $this->isTargetLocale($c),
        ));
    }

    public function assertTargetLocale(string $code): void
    {
        if (! $this->isTargetLocale($code)) {
            throw new InvalidArgumentException("[{$code}] is not a translation target in the locale registry");
        }
    }

    // ── Commands (built here, run in the queued jobs) ─────────────────────────

    /**
     * The export command: python3 export_master.py --locale <code>
     * --out <run>/export. Refuses a non-target locale before returning.
     *
     * @return list<string>
     */
    public function exportCommand(string $locale, string $run): array
    {
        $this->assertTargetLocale($locale);

        return [
            'python3',
            base_path(self::EXPORT_SCRIPT),
            '--locale', $locale,
            '--out', $this->exportDir($run),
        ];
    }

    /**
     * The import command: python3 import_translated.py <target> [--dry-run].
     * The target is a file or a directory of translated export files.
     *
     * @return list<string>
     */
    public function importCommand(string $target, bool $dryRun): array
    {
        $cmd = ['python3', base_path(self::IMPORT_SCRIPT), $target];

        if ($dryRun) {
            $cmd[] = '--dry-run';
        }

        return $cmd;
    }

    // ── The importer's dry-run report ─────────────────────────────────────────

    /**
     * Parse import_translated.py stdout into structured counts. The importer
     * prints a summary line "files N   accepted N   rejected N" and, for each
     * refused string, a line "    <locale>/<ns>  <key>: <reason>".
     *
     * @return array{accepted:int, rejected:int, files:int,
     *               rejections: list<array{locale:string, namespace:string, key:string, reason:string}>}
     */
    public function parseDryRunReport(string $stdout): array
    {
        $accepted = 0;
        $rejected = 0;
        $files = 0;
        $rejections = [];

        foreach (preg_split('/\r?\n/', $stdout) ?: [] as $line) {
            if (preg_match('/\bfiles\s+(\d+)\s+accepted\s+(\d+)\s+rejected\s+(\d+)/', $line, $m)) {
                $files = (int) $m[1];
                $accepted = (int) $m[2];
                $rejected = (int) $m[3];

                continue;
            }

            if (preg_match('/^\s+([^\s\/]+)\/(\S+)\s+(.+?):\s(.+)$/', $line, $m)) {
                $rejections[] = [
                    'locale' => $m[1],
                    'namespace' => $m[2],
                    'key' => $m[3],
                    'reason' => $m[4],
                ];
            }
        }

        return [
            'accepted' => $accepted,
            'rejected' => $rejected,
            'files' => $files,
            'rejections' => $rejections,
        ];
    }

    // ── Zipping ────────────────────────────────────────────────────────────────

    /**
     * Zip every file under $dir into $zipPath, flat-named by their path
     * relative to $dir. Returns the file count. Used by the export job after
     * the script writes the chunk files.
     */
    public function zipDir(string $dir, string $zipPath): int
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ext-zip is required to build a language package');
        }
        if (! is_dir($dir)) {
            throw new InvalidArgumentException("export directory not found: {$dir}");
        }

        $this->ensureDir(dirname($zipPath));

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("could not open zip for writing: {$zipPath}");
        }

        $count = 0;
        $dirLen = strlen(rtrim(str_replace('\\', '/', realpath($dir) ?: $dir), '/')) + 1;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            $abs = str_replace('\\', '/', $file->getPathname());
            $zip->addFile($file->getPathname(), substr($abs, $dirLen));
            $count++;
        }

        $zip->close();

        return $count;
    }

    // ── Run records ────────────────────────────────────────────────────────────

    private function runRecordPath(string $run): string
    {
        return $this->pathWithin($this->cleanSegment($run) . '/package.json');
    }

    /** @param array<string, mixed> $data */
    public function writeRun(string $run, array $data): array
    {
        $path = $this->runRecordPath($run);
        $this->ensureDir(dirname($path));

        $record = array_merge($this->readRun($run) ?? [], $data, [
            'run' => $run,
            'updated_at' => now()->toIso8601String(),
        ]);

        file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $record;
    }

    /** @return array<string, mixed>|null */
    public function readRun(string $run): ?array
    {
        $path = $this->runRecordPath($run);
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Every run record on disk, newest first (run ids sort chronologically).
     *
     * @return list<array<string, mixed>>
     */
    public function listRuns(): array
    {
        $runs = [];
        foreach (glob($this->base . '/*/package.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $runs[] = $decoded;
            }
        }
        usort($runs, fn ($a, $b) => strcmp((string) ($b['run'] ?? ''), (string) ($a['run'] ?? '')));

        return $runs;
    }

    // ── Language requests ────────────────────────────────────────────────────────

    public function requestsPath(): string
    {
        return $this->pathWithin('requests.json');
    }

    /**
     * Record a request for a language nobody has opened yet. Stored as a flat
     * JSON list in the store, not the database — a request is a note, not an
     * audited act, and it never touches the catalogs.
     *
     * @return array<string, mixed> the recorded request
     */
    public function recordRequest(string $locale, string $requester, string $note = ''): array
    {
        $this->ensureDir($this->base);

        $entry = [
            'id' => (string) Str::uuid(),
            'locale' => $locale,
            'requester' => $requester,
            'note' => $note,
            'at' => now()->toIso8601String(),
        ];

        $list = $this->listRequests();
        $list[] = $entry;
        file_put_contents(
            $this->requestsPath(),
            json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        return $entry;
    }

    /** @return list<array<string, mixed>> */
    public function listRequests(): array
    {
        $path = $this->requestsPath();
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    // ── internals ─────────────────────────────────────────────────────────────

    public function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    /** One path segment only — no slashes, no `..`, no NUL. */
    private function cleanSegment(string $segment): string
    {
        if ($segment === '' || str_contains($segment, '/') || str_contains($segment, '\\')
            || str_contains($segment, "\0") || $segment === '.' || $segment === '..') {
            throw new InvalidArgumentException("illegal path segment: {$segment}");
        }

        return $segment;
    }

    /** Collapse `.`/`..` textually (no filesystem hit — paths may not exist yet). */
    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        $drive = '';
        if (preg_match('#^([A-Za-z]:)/#', $path, $m)) {
            $drive = $m[1];
            $path = substr($path, 2);
        }
        $isAbs = str_starts_with($path, '/');

        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $seg;
        }

        return $drive . ($isAbs ? '/' : '') . implode('/', $parts);
    }
}
