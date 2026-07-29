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
 * /support — intake + the lifecycle read/triage door (mockups-v3-wiring Phase 1;
 * lifecycle ruling §10 item 7, the routed six).
 *
 *   GET  /support/report          create  (public read — anyone can SEE the form)
 *   POST /support/report          store   (auth — filing is attributed)
 *   GET  /support/tickets         index   (auth — your reports; operators: all)
 *   GET  /support/ticket/{ref}    show    (auth — owner or operator)
 *   POST /support/ticket/{ref}    update  (auth: operator — triage: status/severity)
 *
 * The intake ROUTES a request; it removes nothing. `abuse` routes to the
 * moderation & legal floor, OFF the tech-support queue; content removal stays on
 * the judicial F-SOC-003 carve-out path, never this form.
 */
class SupportReportController extends Controller
{
    /** Plain-language labels for the routed six (v3 support/report.html). */
    private const CATEGORY_LABELS = [
        SupportReport::CATEGORY_BUG => 'Something is broken',
        SupportReport::CATEGORY_TRANSLATION => 'Wording or translation',
        SupportReport::CATEGORY_ACCESSIBILITY => 'Accessibility barrier',
        SupportReport::CATEGORY_CONTENT => 'Wrong information',
        SupportReport::CATEGORY_ABUSE => 'Abuse or illegal content',
        SupportReport::CATEGORY_IDEA => 'An idea',
    ];

    /** Where each subject goes, in plain words (rendered beside the picker). */
    private const ROUTE_LABELS = [
        SupportReport::ROUTE_OPERATORS => 'The operators',
        SupportReport::ROUTE_TRANSLATION => 'Translation support',
        SupportReport::ROUTE_MODERATION => 'Moderation & the legal floor',
        SupportReport::ROUTE_BACKLOG => 'The product backlog',
        SupportReport::ROUTE_COURTS => 'The courts',
    ];

    public function create(Request $request): Response
    {
        return Inertia::render('Support/Report', [
            'categories' => collect(SupportReport::CATEGORIES)
                ->map(fn (string $id) => [
                    'id' => $id,
                    'label' => self::CATEGORY_LABELS[$id],
                    'routesTo' => self::ROUTE_LABELS[SupportReport::routeFor($id)],
                    'target' => SupportReport::routeFor($id),
                ])
                ->values(),
            'ref' => $this->sanitizedRef($request),
            'submitted' => $request->session()->has('support_report_public_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', Rule::in(SupportReport::CATEGORIES)],
            'subject' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'ref' => ['nullable', 'string', 'max:300'],
        ]);

        $report = SupportReport::create([
            'category' => $data['category'],
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
            'ref' => $data['ref'] ?? null,
            'reporter_id' => $request->user()->id,
            'status' => SupportReport::STATUS_OPEN,
            'route_target' => SupportReport::routeFor($data['category']),
        ]);

        return redirect()->route('support.report')
            ->with('status', "Report filed — reference {$report->public_id}")
            ->with('support_report_public_id', $report->public_id);
    }

    /**
     * The lifecycle queue. A person sees the reports THEY filed (the missing
     * "track my report" door); an operator sees every report (the triage queue).
     * Filter by status (open = still needs attention, or a specific state) and
     * category.
     */
    public function index(Request $request): Response
    {
        $isOperator = (bool) $request->user()->is_operator;
        $status = (string) $request->query('status', 'open');
        $category = (string) $request->query('category', 'all');

        // Base scope: operators see every report; everyone else sees only their
        // own. A fresh builder each call so the counts never inherit the list's
        // limit/filters.
        $base = fn () => SupportReport::query()
            ->when(! $isOperator, fn ($q) => $q->where('reporter_id', $request->user()->id));

        $list = $base()->with('reporter:id,name,display_name')->latest('updated_at');
        if ($status === 'open') {
            $list->whereIn('status', SupportReport::OPEN_STATUSES);
        } elseif (in_array($status, SupportReport::STATUSES, true)) {
            $list->where('status', $status);
        }
        if (in_array($category, SupportReport::CATEGORIES, true)) {
            $list->where('category', $category);
        }
        $reports = $list->limit(200)->get();

        return Inertia::render('Support/Tickets', [
            'surface' => \App\Support\SurfaceMeta::for('support/tickets'),
            'isOperator' => $isOperator,
            'filters' => ['status' => $status, 'category' => $category],
            'statuses' => SupportReport::STATUSES,
            'categories' => $this->categoryOptions(),
            'openCount' => $base()->whereIn('status', SupportReport::OPEN_STATUSES)->count(),
            'totalCount' => $base()->count(),
            'reports' => $reports->map(fn (SupportReport $r) => $this->row($r, $isOperator))->values(),
        ]);
    }

    /** One report's detail — its own reporter, or any operator. */
    public function show(Request $request, string $ref): Response
    {
        $report = SupportReport::query()->where('public_id', $ref)->firstOrFail();

        $isOperator = (bool) $request->user()->is_operator;
        abort_unless($isOperator || (string) $report->reporter_id === (string) $request->user()->id, 404);

        $report->loadMissing('reporter:id,name,display_name');

        return Inertia::render('Support/Ticket', [
            'surface' => \App\Support\SurfaceMeta::for('support/ticket'),
            'isOperator' => $isOperator,
            'statuses' => SupportReport::STATUSES,
            'severities' => SupportReport::SEVERITIES,
            'report' => array_merge($this->row($report, $isOperator), [
                'body' => $report->body,
            ]),
        ]);
    }

    /** Operator triage — move status / set severity. Attributed, not an engine filing. */
    public function update(Request $request, string $ref): RedirectResponse
    {
        abort_unless((bool) $request->user()->is_operator, 403, 'Triage is an operator action.');

        $report = SupportReport::query()->where('public_id', $ref)->firstOrFail();

        // "— none —" posts an empty string; normalise to null so nullable+Rule::in
        // passes without depending on ConvertEmptyStringsToNull being registered.
        $request->merge(['severity' => $request->filled('severity') ? $request->input('severity') : null]);

        $data = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(SupportReport::STATUSES)],
            'severity' => ['sometimes', 'nullable', Rule::in(SupportReport::SEVERITIES)],
        ]);

        $report->fill($data)->save();

        return redirect()->route('support.ticket', ['ref' => $report->public_id])
            ->with('status', 'Report updated.');
    }

    /** @return array<int, array{id: string, label: string}> */
    private function categoryOptions(): array
    {
        return collect(SupportReport::CATEGORIES)
            ->map(fn (string $id) => ['id' => $id, 'label' => self::CATEGORY_LABELS[$id]])
            ->values()
            ->all();
    }

    /** A queue/detail row — reporter identity is shown to operators only. */
    private function row(SupportReport $r, bool $isOperator): array
    {
        return [
            'public_id' => $r->public_id,
            'category' => $r->category,
            'category_label' => self::CATEGORY_LABELS[$r->category] ?? $r->category,
            'subject' => $r->subject,
            'status' => $r->status,
            'severity' => $r->severity,
            'route_target' => $r->route_target,
            'route_label' => self::ROUTE_LABELS[$r->route_target] ?? $r->route_target,
            'off_queue' => $r->isOffQueue(),
            'ref' => $r->ref,
            'reporter' => $isOperator ? ($r->reporter?->display_name ?? $r->reporter?->name) : null,
            'created_at' => $r->created_at?->toIso8601String(),
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
    }

    /** The ?ref= page pointer — plain string, control chars stripped, capped at 300. */
    private function sanitizedRef(Request $request): string
    {
        $ref = (string) $request->query('ref', '');
        $ref = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $ref));

        return mb_substr($ref, 0, 300);
    }
}
