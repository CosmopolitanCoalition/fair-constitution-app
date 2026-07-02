<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\SupportReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /support/report — the intake surface (mockups-v3-wiring Phase 1).
 *
 *   GET  /support/report   create  (public read — anyone can SEE the form)
 *   POST /support/report   store   (auth — filing is attributed)
 *
 * The intake ROUTES a request; it removes nothing. Conduct/legal reports
 * feed the constitutional carve-out machinery (the judicial F-SOC-003
 * path) — the page copy says so in plain words.
 */
class SupportReportController extends Controller
{
    /** Plain-language labels for the category select. */
    private const CATEGORY_LABELS = [
        SupportReport::CATEGORY_BUG => 'Bug — something is broken',
        SupportReport::CATEGORY_QUESTION => 'Question — how does this work?',
        SupportReport::CATEGORY_CONDUCT => 'Conduct — someone\'s behaviour needs review',
        SupportReport::CATEGORY_LEGAL => 'Legal — content that may be illegal',
        SupportReport::CATEGORY_APPEAL => 'Appeal — challenge a decision',
        SupportReport::CATEGORY_OTHER => 'Something else',
    ];

    public function create(Request $request): Response
    {
        return Inertia::render('Support/Report', [
            'categories' => collect(SupportReport::CATEGORIES)
                ->map(fn (string $id) => ['id' => $id, 'label' => self::CATEGORY_LABELS[$id]])
                ->values(),
            'ref' => $this->sanitizedRef($request),
            'submitted' => $request->session()->has('support_report_public_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', Rule::in(SupportReport::CATEGORIES)],
            'body' => ['required', 'string', 'max:5000'],
            'ref' => ['nullable', 'string', 'max:300'],
        ]);

        $report = SupportReport::create([
            'category' => $data['category'],
            'body' => $data['body'],
            'ref' => $data['ref'] ?? null,
            'reporter_id' => $request->user()->id,
            'status' => SupportReport::STATUS_OPEN,
        ]);

        return redirect()->route('support.report')
            ->with('status', "Report filed — reference {$report->public_id}")
            ->with('support_report_public_id', $report->public_id);
    }

    /** The ?ref= page pointer — plain string, control chars stripped, capped at 300. */
    private function sanitizedRef(Request $request): string
    {
        $ref = (string) $request->query('ref', '');
        $ref = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $ref));

        return mb_substr($ref, 0, 300);
    }
}
