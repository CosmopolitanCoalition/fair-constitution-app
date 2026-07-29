<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\TranslationReviewService;
use App\Support\SurfaceMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /** Live run state, written by the translation workers themselves. */
    private const RUN_DIR = 'storage/app/i18n-run';

    /** A worker silent this long is presumed gone, not working. */
    private const WORKER_STALE_SECONDS = 120;

    public function __construct(private readonly TranslationReviewService $review)
    {
    }

    public function show(): Response
    {
        $registry = config('locales.locales', []);
        $counts   = config('locales.counts', []);
        $codes    = $this->localesWithCatalogs($registry);

        /*
         * The viewer's verifier standing. Anyone who READS a language may verify
         * its translations — the gate is TranslationReviewController::canVerify:
         * is_operator, or the language is your app locale, or it is one of the
         * languages on your account. en is the source, never reviewed. This is
         * the "Become a verifier" section's data (mockup translation-home.html).
         */
        $user = request()->user();
        $canVerify = $user?->is_operator
            ? array_values(array_filter($codes, static fn (string $c): bool => $c !== 'en'))
            : array_values(array_filter($codes, static fn (string $c): bool => $c !== 'en'
                && ((string) $user?->locale === $c || in_array($c, $user?->languages ?? [], true))));

        return Inertia::render('System/Translations', [
            'surface'  => SurfaceMeta::for('system/translations'),
            'viewer'   => [
                'authed'     => $user !== null,
                'isOperator' => (bool) $user?->is_operator,
                'canVerify'  => $canVerify,
            ],
            'coverage' => $this->coverage(),
            'live'     => $this->live(),

            /*
             * The matrix — languages × the six kinds of content
             * (mockups/v3/translation/translation-home.html).
             *
             * One percentage per language would let "Spanish is 99%" stand in
             * for "the videos are dubbed in Spanish". Six cells cannot.
             */
            'matrix'     => $this->rankedMatrix($codes),
            'modalities' => TranslationReviewService::MODALITIES,
            'states'     => TranslationReviewService::STATES,
            'registry'   => array_intersect_key($registry, array_flip($codes)),
            'totals'     => [
                'mapped'     => $counts['registered'] ?? count($registry),
                'translated' => $counts['translated'] ?? 0,
                'enabled'    => $counts['enabled'] ?? 0,
                'shown'      => count($codes),
            ],
        ]);
    }

    /**
     * The matrix, source language first and the rest most-complete first.
     *
     * @param  list<string>  $codes
     */
    private function rankedMatrix(array $codes): array
    {
        $rows = $this->review->matrix($codes);

        usort($rows, function (array $a, array $b): int {
            if (($a['code'] === 'en') !== ($b['code'] === 'en')) {
                return $a['code'] === 'en' ? -1 : 1;
            }

            return $b['overall'] <=> $a['overall'] ?: strcmp($a['code'], $b['code']);
        });

        return $rows;
    }

    /**
     * Which languages the matrix shows: every registered locale that has a
     * catalog directory on disk.
     *
     * Not all 112 — a row for a language nobody has started is a row of
     * dashes, and 105 of them would bury the seven that carry real work. The
     * totals line says how many exist so the omission is stated, not hidden.
     *
     * @param  array<string, array<string, mixed>>  $registry
     * @return list<string>
     */
    private function localesWithCatalogs(array $registry): array
    {
        $dirs = glob(base_path('resources/js/i18n/locales') . '/*', GLOB_ONLYDIR) ?: [];
        $codes = array_values(array_filter(
            array_map('basename', $dirs),
            fn (string $c): bool => isset($registry[$c]),
        ));

        // Registry order, so English (the source) leads and the rest are stable.
        return array_values(array_intersect(array_keys($registry), $codes));
    }

    /**
     * The live deck, polled every 2s by the page — the same contract the
     * district mapper's Step-3 surface uses.
     *
     * Read fresh off the workers' own heartbeat files on every request. There
     * is deliberately no cached copy and no second computation: a progress
     * view that can disagree with the work it claims to describe is worse than
     * no progress view.
     */
    public function progress(): JsonResponse
    {
        return response()->json($this->live());
    }

    /**
     * Request a halt. Workers park at their next COMMITTED chunk boundary, so
     * halting costs at most one chunk of redone work and can never leave a
     * half-written catalog. Operator-only: this stops real work.
     */
    public function halt(Request $request): JsonResponse
    {
        abort_unless($request->user()?->is_operator, 403);

        $dir = base_path(self::RUN_DIR);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/halt.request', (string) time());

        return response()->json($this->live());
    }

    /** Clear the halt flag. A new run starts fresh; this just unlatches it. */
    public function resume(Request $request): JsonResponse
    {
        abort_unless($request->user()?->is_operator, 403);

        $flag = base_path(self::RUN_DIR) . '/halt.request';
        if (is_file($flag)) {
            unlink($flag);
        }

        return response()->json($this->live());
    }

    /** @return array<string, mixed> */
    private function live(): array
    {
        $dir = base_path(self::RUN_DIR);
        $run = $this->json($dir . '/run.json');

        $workers = [];
        foreach (glob($dir . '/workers/*.json') ?: [] as $file) {
            $w = $this->json($file);
            if ($w === null) {
                continue;
            }
            $w['age']   = max(0, time() - (int) ($w['last_seen'] ?? 0));
            $w['stale'] = $w['age'] > self::WORKER_STALE_SECONDS;
            $workers[] = $w;
        }

        usort($workers, fn ($a, $b) => strcmp((string) $a['locale'], (string) $b['locale']));

        $active = array_values(array_filter(
            $workers,
            fn (array $w): bool => ! $w['stale'] && ($w['state'] ?? '') !== 'done',
        ));

        // Rate is summed across LIVE workers only: a finished worker's average
        // says nothing about how fast the run is moving now.
        $rate = 0.0;
        foreach ($active as $w) {
            $rate += (float) ($w['rate'] ?? 0);
        }

        $done  = array_sum(array_map(fn ($w) => (int) ($w['done'] ?? 0), $workers));
        $total = array_sum(array_map(fn ($w) => (int) ($w['total'] ?? 0), $workers));

        return [
            'run'            => $run,
            'halt_requested' => is_file($dir . '/halt.request'),
            'workers'        => $workers,
            'workers_active' => count($active),
            'strings_done'   => $done,
            'strings_total'  => $total,
            'rate'           => round($rate, 2),
            // Suppressed rather than guessed while nothing is moving — an ETA
            // computed from a zero rate is a number that lies.
            'eta_seconds'    => $rate > 0.05 && $total > $done
                ? (int) round(($total - $done) / $rate)
                : null,
            'polled_at'      => now()->toIso8601String(),
        ];
    }

    private function json(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
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
