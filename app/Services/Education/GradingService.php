<?php

namespace App\Services\Education;

use Illuminate\Support\Facades\DB;

/**
 * Server-side grading — the ONLY reader of `correct_keys` (K2_ENGINE_PLAN
 * §2, the architectural layer of the answer-key rail).
 *
 * The comparison happens here and the result is a boolean plus a score —
 * never a diff, never the key. What leaves this class can be shown to a
 * browser: pass/fail, the percentage, and the EXPLAIN text for the
 * questions answered wrongly (§8.1's explain-on-fail — teaching prose that
 * never names the correct choice outright). Wrong answers are not
 * persisted anywhere (§3.5: an error history is a composite score waiting
 * to be computed); the caller throttles the endpoint so the item bank
 * cannot be brute-forced.
 *
 * This file is deliberately on EducationAnswerKeySecrecyTest's allowlist:
 * grading code that READS the catalog may join; anything that would SHIP
 * the key may not.
 */
class GradingService
{
    /**
     * Grade a learner's answers for a module.
     *
     * @param  array<string, string>  $answers  question key => chosen choice key
     * @return array{passed: bool, score_pct: int, explain: array<string, string>}
     */
    public function grade(string $trackKey, string $moduleKey, array $answers): array
    {
        $questions = DB::table('education_questions as q')
            ->join('education_modules as m', 'm.id', '=', 'q.module_id')
            ->join('education_tracks as t', 't.id', '=', 'm.track_id')
            ->where('t.key', $trackKey)->whereNull('t.deleted_at')
            ->where('m.key', $moduleKey)->whereNull('m.deleted_at')
            ->whereNull('q.deleted_at')
            ->orderBy('q.ordering')
            ->get(['q.key', 'q.correct_keys', 'q.weight', 'q.prompt']);

        if ($questions->isEmpty()) {
            return ['passed' => false, 'score_pct' => 0, 'explain' => []];
        }

        $totalWeight = 0;
        $earned = 0;
        $explain = [];

        foreach ($questions as $q) {
            $weight = max(1, (int) $q->weight);
            $totalWeight += $weight;

            $correct = array_map('strval', (array) json_decode((string) $q->correct_keys, true));
            $chosen = (string) ($answers[$q->key] ?? '');

            if ($chosen !== '' && in_array($chosen, $correct, true)) {
                $earned += $weight;

                continue;
            }

            // Explain-on-fail: the module's explain key rides back for the
            // missed question. The correct choice itself never does.
            $explain[$q->key] = $this->explainKeyFor($moduleKey, $q->key);
        }

        $score = (int) round(100 * $earned / $totalWeight);
        $threshold = (int) config('cga.education.pass_threshold_pct', 80);

        return ['passed' => $score >= $threshold, 'score_pct' => $score, 'explain' => $explain];
    }

    /**
     * The explain i18n key follows the content catalog's naming convention;
     * resolving it from config keeps the DB row free of a second copy.
     */
    private function explainKeyFor(string $moduleKey, string $questionKey): string
    {
        return 'c_education.learn.q.'.str_replace('-', '_', $moduleKey).'.'.$questionKey.'.explain';
    }
}
