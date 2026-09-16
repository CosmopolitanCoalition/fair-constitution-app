<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Jobs\I18n\ExportLanguagePackageJob;
use App\Jobs\I18n\ImportLanguagePackageJob;
use App\Services\I18n\LanguagePackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * W-0446 — language packages, on the /system/translations board (operator
 * ruling 2026-09-15). Export a target locale's outstanding strings as a zip,
 * import a translated zip or JSON file back, and record a request for a
 * language nobody has opened yet.
 *
 * OPERATOR-ONLY. Every action and the download are gated exactly the way
 * halt/resume are gated in TranslationCoverageController: is_operator or 403.
 * These queue real work against the real catalogs.
 *
 * THE SCRIPTS NEVER RUN HERE. Export and import are always queued onto the
 * default queue (Horizon); this controller only gates, records the run and
 * dispatches. The card polls status() every 2s, the same contract the live
 * deck uses.
 */
class TranslationPackageController extends Controller
{
    public function __construct(private readonly LanguagePackageService $packages)
    {
    }

    /** The coverage artifact the board reads; the card's truth about what is present. */
    private const COVERAGE_PATH = 'resources/js/i18n/coverage.json';

    /** Queue an export: a target locale's outstanding English strings, or the English master. */
    public function export(Request $request): JsonResponse
    {
        $this->operatorOnly($request);

        $data = $request->validate([
            'locale' => ['required', 'string', 'max:16'],
        ]);
        $locale = $data['locale'];

        if (! $this->packages->isExportable($locale)) {
            return response()->json([
                'error' => __('[:locale] is neither the source catalogue nor a translation target', ['locale' => $locale]),
            ], 422);
        }

        $run = $this->packages->newRunId();
        $this->packages->writeRun($run, [
            'kind' => 'export',
            'locale' => $locale,
            'status' => 'queued',
            'actor' => (string) $request->user()?->getKey(),
        ]);

        ExportLanguagePackageJob::dispatch($run, $locale);

        return response()->json(['run' => $run, 'status' => 'queued']);
    }

    /** Upload a translated zip or JSON file and queue the dry run. */
    public function import(Request $request): JsonResponse
    {
        $this->operatorOnly($request);

        $data = $request->validate([
            'locale' => ['required', 'string', 'max:16'],
            'package' => ['required', 'file', 'max:65536'],
        ]);
        $locale = $data['locale'];

        $file = $request->file('package');
        $ext = Str::lower((string) $file->getClientOriginalExtension());
        if (! in_array($ext, ['zip', 'json'], true)) {
            return response()->json([
                'error' => __('A language package is a .zip or a single translated .json file.'),
            ], 422);
        }

        if ($this->packages->isSourceLocale($locale)) {
            return response()->json([
                'error' => __('English is the source catalogue. It is edited in code, never imported.'),
            ], 422);
        }
        if (! $this->packages->isTargetLocale($locale)) {
            return response()->json([
                'error' => __('[:locale] is not a translation target', ['locale' => $locale]),
            ], 422);
        }

        $run = $this->packages->newRunId();
        $importDir = $this->packages->importDir($run);
        $this->packages->ensureDir($importDir);
        $file->move($importDir, $file->getClientOriginalName());

        $this->packages->writeRun($run, [
            'kind' => 'import',
            'locale' => $locale,
            'status' => 'queued',
            'actor' => (string) $request->user()?->getKey(),
        ]);

        ImportLanguagePackageJob::dispatch($run, confirm: false, actorId: (string) $request->user()?->getKey(), locale: $locale);

        return response()->json(['run' => $run, 'status' => 'queued']);
    }

    /** Confirm a dry run: queue the real import. */
    public function confirm(Request $request, string $run): JsonResponse
    {
        $this->operatorOnly($request);

        $record = $this->packages->readRun($run);
        if ($record === null) {
            return response()->json(['error' => __('Unknown run.')], 404);
        }

        $this->packages->writeRun($run, ['status' => 'confirm_queued']);

        ImportLanguagePackageJob::dispatch(
            $run,
            confirm: true,
            actorId: (string) $request->user()?->getKey(),
            locale: $record['locale'] ?? null,
        );

        return response()->json(['run' => $run, 'status' => 'confirm_queued']);
    }

    /**
     * The escape hatch, part one: run a failed or stale run again. An export
     * becomes a NEW run for the same locale; an import re-queues its own dry
     * run (the uploaded file is still under the run). Never blocked by the
     * state it recovers from.
     */
    public function retry(Request $request, string $run): JsonResponse
    {
        $this->operatorOnly($request);

        $record = $this->packages->readRun($run);
        if ($record === null) {
            return response()->json(['error' => __('Unknown run.')], 404);
        }

        $actor = (string) $request->user()?->getKey();
        $locale = (string) ($record['locale'] ?? '');

        if (($record['kind'] ?? '') === 'export') {
            if (! $this->packages->isExportable($locale)) {
                return response()->json(['error' => __('[:locale] is not exportable', ['locale' => $locale])], 422);
            }
            $new = $this->packages->newRunId();
            $this->packages->writeRun($new, [
                'kind' => 'export',
                'locale' => $locale,
                'status' => 'queued',
                'actor' => $actor,
                'retry_of' => $run,
            ]);
            $this->packages->writeRun($run, ['retried_as' => $new]);
            ExportLanguagePackageJob::dispatch($new, $locale);

            return response()->json(['run' => $new, 'status' => 'queued']);
        }

        $this->packages->writeRun($run, ['status' => 'queued', 'error' => null, 'retries' => (int) ($record['retries'] ?? 0) + 1]);
        ImportLanguagePackageJob::dispatch($run, confirm: false, actorId: $actor, locale: $locale !== '' ? $locale : null);

        return response()->json(['run' => $run, 'status' => 'queued']);
    }

    /** The escape hatch, part two: remove a run and its files. */
    public function discard(Request $request, string $run): JsonResponse
    {
        $this->operatorOnly($request);

        if (! $this->packages->discardRun($run)) {
            return response()->json(['error' => __('Unknown run.')], 404);
        }

        return response()->json(['run' => $run, 'status' => 'discarded']);
    }

    /** Download a finished package zip. */
    public function download(Request $request, string $run, string $locale): BinaryFileResponse|JsonResponse
    {
        $this->operatorOnly($request);

        $zip = $this->packages->packageZipPath($run, $locale);
        if (! is_file($zip)) {
            return response()->json(['error' => __('Package not ready.')], 404);
        }

        return response()->download($zip, $locale . '-package.zip');
    }

    /**
     * The card's poll: every run record, every language request, and what is
     * actually in the app: the English source with its counts, and one row
     * per target with its measured coverage or `present: false`. The same 2s
     * contract TranslationCoverageController::progress uses.
     */
    public function status(Request $request): JsonResponse
    {
        $this->operatorOnly($request);

        $coverage = $this->coverage();
        $source = $this->packages->sourceFiles();

        return response()->json([
            'runs' => $this->packages->listRuns(),
            'requests' => $this->packages->listRequests(),
            'targets' => $this->packages->targetLocales(),
            'languages' => $this->packages->languageRows($coverage),
            'source' => [
                'code' => LanguagePackageService::SOURCE_LOCALE,
                'keys' => (int) ($coverage['source_keys'] ?? 0),
                'namespaces' => (int) ($coverage['namespaces'] ?? 0),
                'files' => count($source),
                'measured_at' => $coverage['generated_at'] ?? null,
            ],
        ]);
    }

    /** The decoded coverage artifact, or null when the gate has never run on this box. */
    private function coverage(): ?array
    {
        $path = base_path(self::COVERAGE_PATH);
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Record a request for a language nobody has opened yet. */
    public function requestLanguage(Request $request): JsonResponse
    {
        $this->operatorOnly($request);

        $data = $request->validate([
            'locale' => ['required', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->packages->recordRequest(
            $data['locale'],
            (string) ($request->user()?->getKey() ?? ''),
            $data['note'] ?? '',
        );

        return response()->json(['requests' => $this->packages->listRequests()]);
    }

    /** is_operator or 403 — the halt/resume gate. */
    private function operatorOnly(Request $request): void
    {
        abort_unless($request->user()?->is_operator, 403);
    }
}
