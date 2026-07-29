<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Support\SurfaceMeta;
use Inertia\Inertia;
use Inertia\Response;

/**
 * mockups-v3-wiring — /system/constitutional-questions (design contract:
 * mockups/v3/shared/constitutional-questions.html).
 *
 *   GET /system/constitutional-questions   show   (public read)
 *
 * READ-ONLY, zero actions — the maintained ledger of open "why" questions
 * every `· as implemented` citation resolves to (#q1..#q7). Static content
 * (no backing store: the ledger is hand-maintained in the Vue page, the same
 * way the mockup carries it). Surface id `shared/constitutional-questions`
 * (module `shared`) matches the authored Learn copy. Public because
 * citations render before a session exists.
 */
class ConstitutionalQuestionsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('System/ConstitutionalQuestions', [
            'surface' => SurfaceMeta::for('shared/constitutional-questions'),
        ]);
    }
}
