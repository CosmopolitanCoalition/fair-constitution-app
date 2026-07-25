<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Support\SurfaceMeta;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase N (lane 5) — /system/translations.
 *
 *   GET /system/translations   show
 *
 * READ-ONLY BY DESIGN. The translation status board: how much of the app's
 * copy exists as translatable messages, how much of it each locale actually
 * carries, and what the gate is currently failing on.
 *
 * The numbers come from `resources/js/i18n/coverage.json`, which
 * `scripts/i18n/check.mjs` regenerates on every run — the SAME artifact the
 * gate exits non-zero on. There is deliberately no second computation here:
 * a dashboard that recomputes coverage its own way is a dashboard that can
 * disagree with the gate, and then neither number means anything.
 *
 * When the pull-engine lands (TRANSLATION_SCALING_PLAN §1-§5) this page gains
 * the live run decks — per-locale bars, the worker strip, review census —
 * polled from the progress endpoint. The static coverage half stays as-is:
 * it answers "where are we" when no run is in flight.
 */
class TranslationCoverageController extends Controller
{
    /** Written by scripts/i18n/check.mjs. */
    private const COVERAGE_PATH = 'resources/js/i18n/coverage.json';

    public function show(): Response
    {
        return Inertia::render('System/Translations', [
            'surface'  => SurfaceMeta::for('system/translations'),
            'coverage' => $this->coverage(),
        ]);
    }

    /**
     * The coverage artifact, or a null-shaped payload when the gate has never
     * been run on this box. An absent file is an honest state, not an error:
     * it means "nobody has measured yet", and the page says exactly that
     * rather than rendering zeros that look like a finding.
     */
    private function coverage(): ?array
    {
        $path = base_path(self::COVERAGE_PATH);

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
