<?php

namespace App\Http\Controllers\Executive;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\BoardSeat;
use App\Models\Department;
use App\Models\DepartmentReport;
use App\Models\DepartmentRule;
use App\Models\EmergencyPower;
use App\Models\ExecutiveOrder;
use App\Models\Law;
use App\Models\PublicRecord;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-D5 — Department reporting (PHASE_D_DESIGN_frontend.md §B.4; surface
 * executive/department-reporting).
 *
 *   GET  /departments/{department}/reporting — the rules register
 *        (F-BOG-001, each row carrying its enabling-act chip; emergency-
 *        enabled rules flagged "expires with the power · CLK-03") and the
 *        report-filings register (F-BOG-002 → public_records), both
 *        publicly readable.
 *   POST /departments/{department}/rules    — F-BOG-001 (R-18 of THIS board).
 *   POST /departments/{department}/reports  — F-BOG-002 (R-18 of THIS board).
 *
 * Public read (Art. II §2 — reports file to executive AND legislature,
 * published to the public record). FILING gates on R-18 of this board
 * (the engine is the boundary; `can.*` only drives the composer UX).
 * Every threshold/seat/version_no rendered is an engine snapshot off the
 * department_rules / department_reports / board rows — never computed here.
 */
class DepartmentReportingController extends Controller
{
    public function __construct(private readonly ConstitutionalEngine $engine) {}

    public function show(Request $request, Department $department): Response
    {
        $department->loadMissing(['executive', 'charterLaw', 'board']);

        $viewerSeat = $this->viewerSeat($department, $request->user());
        $isGovernor = $viewerSeat !== null;

        return Inertia::render('Executive/DepartmentReporting', [
            'surface' => SurfaceMeta::for('executive/department-reporting'),
            'department' => $this->departmentHeader($department),
            'machine' => config('cga.state_machines.department_board', []),
            'viewerIsGovernor' => $isGovernor,
            'rules' => $this->ruleRows($department),
            'reports' => $this->reportRows($department),
            'ruleForm' => [
                'enablingOptions' => $isGovernor ? $this->enablingOptions($department) : [],
            ],
            'can' => [
                'fileRule' => $isGovernor,
                'fileReport' => $isGovernor,
            ],
        ]);
    }

    /** F-BOG-001 — a seated governor (R-18) of THIS board files a rule. */
    public function fileRule(Request $request, Department $department): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:240'],
            'text' => ['required', 'string', 'max:20000'],
            'enabling_type' => ['required', 'string', 'in:law,charter,emergency_power'],
            'enabling_id' => ['required', 'uuid'],
            'supersedes_rule_id' => ['nullable', 'uuid'],
        ]);

        $this->engine->file('F-BOG-001', $request->user(), [
            'department_id' => (string) $department->id,
            'jurisdiction_id' => (string) $department->jurisdiction_id,
            'name' => $validated['name'],
            'text' => $validated['text'],
            'enabling_type' => $validated['enabling_type'],
            'enabling_id' => $validated['enabling_id'],
            'supersedes_rule_id' => $validated['supersedes_rule_id'] ?? null,
        ]);

        return back()->with(
            'status',
            'Rule filed (F-BOG-001) — in force, published for comment. Rules implement; they cannot exceed the charter and enabling acts. '
            .'An emergency-enabled rule expires with the power (CLK-03).'
        );
    }

    /** F-BOG-002 — a seated governor (R-18) files the due report to public record. */
    public function fileReport(Request $request, Department $department): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'string', 'in:periodic,special'],
            'period_label' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:50000'],
        ]);

        $this->engine->file('F-BOG-002', $request->user(), [
            'department_id' => (string) $department->id,
            'jurisdiction_id' => (string) $department->jurisdiction_id,
            'kind' => $validated['kind'],
            'period_label' => $validated['period_label'] ?? null,
            'body' => $validated['body'],
        ]);

        return back()->with(
            'status',
            'Report filed (F-BOG-002) — to the executive and the legislature, published to the public record (WF-SYS-03).'
        );
    }

    // -------------------------------------------------------------------------
    // Presentation internals
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function departmentHeader(Department $department): array
    {
        return [
            'id' => (string) $department->id,
            'name' => $department->name,
            'kind' => $department->kind,
            'status' => $department->status,
            'worker_count' => (int) $department->worker_count,
            'executive' => $department->executive !== null
                ? ['name' => $department->executive->type, 'href' => '/executives/'.$department->executive->id]
                : null,
            'charter' => [
                'act_number' => $department->charterLaw?->act_number,
                'href' => $this->lawHref($department->charterLaw),
                'reporting_interval_months' => $department->reporting_interval_months !== null
                    ? (int) $department->reporting_interval_months
                    : null,
            ],
            'detail_href' => '/departments/'.$department->id,
            'reporting_href' => '/departments/'.$department->id.'/reporting',
        ];
    }

    /**
     * The rules register — each row carries its enabling-act chip; an
     * emergency-enabled rule flips to `expired` when CLK-03 fires (the
     * cross-domain cascade made visible). `version_no` / `status` are
     * engine snapshots off the row.
     *
     * @return list<array<string, mixed>>
     */
    private function ruleRows(Department $department): array
    {
        $rules = DepartmentRule::query()
            ->where('department_id', $department->id)
            ->orderByDesc('created_at')
            ->get();

        if ($rules->isEmpty()) {
            return [];
        }

        // Batch-resolve enabling labels/hrefs without N+1.
        $lawIds = $rules->whereIn('enabling_type', [ExecutiveOrder::ENABLING_LAW, ExecutiveOrder::ENABLING_CHARTER])
            ->pluck('enabling_id')->filter()->unique();
        $powerIds = $rules->where('enabling_type', ExecutiveOrder::ENABLING_EMERGENCY_POWER)
            ->pluck('enabling_id')->filter()->unique();

        $laws = $lawIds->isNotEmpty()
            ? Law::query()->whereIn('id', $lawIds)->get(['id', 'act_number', 'enacting_bill_id'])->keyBy('id')
            : collect();
        $powers = $powerIds->isNotEmpty()
            ? EmergencyPower::query()->whereIn('id', $powerIds)->get(['id', 'label', 'expires_at'])->keyBy('id')
            : collect();

        return $rules->map(function (DepartmentRule $rule) use ($laws, $powers) {
            $enabling = $this->ruleEnabling($rule, $laws, $powers);

            return [
                'id' => (string) $rule->id,
                'rule_code' => $rule->rule_code,
                'name' => $rule->name,
                'status' => $rule->status,
                'version_no' => (int) $rule->version_no,
                'enabling' => $enabling,
                'note' => $rule->version_no > 1 ? "version {$rule->version_no} — supersedes a prior rule" : null,
            ];
        })->values()->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Law>  $laws
     * @param  \Illuminate\Support\Collection<string, EmergencyPower>  $powers
     * @return array<string, mixed>
     */
    private function ruleEnabling(DepartmentRule $rule, $laws, $powers): array
    {
        if ($rule->enabling_type === ExecutiveOrder::ENABLING_EMERGENCY_POWER) {
            $power = $powers->get($rule->enabling_id);

            return [
                'type' => 'emergency_power',
                'label' => $power?->label ?? 'emergency power',
                'href' => '/legislature/emergency-powers',
                'expires_with_power' => (bool) $rule->expires_with_enabling,
            ];
        }

        $law = $laws->get($rule->enabling_id);

        return [
            'type' => 'law',
            'label' => $law?->act_number ?? ($rule->enabling_type === ExecutiveOrder::ENABLING_CHARTER ? 'charter' : 'enabling act'),
            'href' => $this->lawHref($law),
            'expires_with_power' => false,
        ];
    }

    /**
     * The report-filings register. `status` (due | due_soon | filed |
     * overdue) is engine-owned: the row's persisted status, with a
     * read-time `due_soon` refinement on a still-due row inside the
     * charter reporting interval window. Filed rows link their
     * public_records entry.
     *
     * @return list<array<string, mixed>>
     */
    private function reportRows(Department $department): array
    {
        $reports = DepartmentReport::query()
            ->where('department_id', $department->id)
            ->orderByDesc('due_on')
            ->get();

        if ($reports->isEmpty()) {
            return [];
        }

        // Resolve public_record seq for filed rows (record chip target).
        $recordSeqs = PublicRecord::query()
            ->whereIn('id', $reports->pluck('record_id')->filter()->unique())
            ->pluck('seq', 'id');

        $soonWindow = now()->addDays(14)->toDateString();

        return $reports->map(function (DepartmentReport $report) use ($recordSeqs, $soonWindow) {
            $status = $report->status;

            // due_soon is a read-time refinement of a still-due row — never
            // a stored status (DepartmentReport only knows due|filed|overdue).
            if ($status === DepartmentReport::STATUS_DUE
                && $report->due_on !== null
                && $report->due_on->toDateString() <= $soonWindow) {
                $status = 'due_soon';
            }

            $seq = $report->record_id !== null ? $recordSeqs->get($report->record_id) : null;

            return [
                'id' => (string) $report->id,
                'kind' => $report->kind,
                'label' => $report->period_label ?? $report->kind,
                'recipients' => 'Executive + legislature',
                'due_on' => $report->due_on?->toDateString(),
                'filed_at' => $report->filed_at?->toIso8601String(),
                'status' => $status,
                'record_href' => $seq !== null ? '/system/public-records?seq='.$seq : null,
            ];
        })->values()->all();
    }

    /**
     * The F-BOG-001 enabling-basis options — charter law + in-force laws +
     * ACTIVE emergency powers, each one that EnablingInstruments would
     * accept (live + jurisdiction covers the executive's). Server-filtered
     * so the select never offers an instrument the engine would reject.
     *
     * @return list<array{type: string, id: string, label: string}>
     */
    private function enablingOptions(Department $department): array
    {
        $executive = $department->executive;

        if ($executive === null) {
            return [];
        }

        $chain = $this->jurisdictionChain((string) $executive->jurisdiction_id);
        $options = [];

        // The department's own charter (the canonical first basis).
        if ($department->charterLaw !== null
            && in_array($department->charterLaw->status, [Law::STATUS_IN_FORCE, Law::STATUS_AMENDED], true)) {
            $options[] = [
                'type' => ExecutiveOrder::ENABLING_CHARTER,
                'id' => (string) $department->charterLaw->id,
                'label' => 'Charter — '.($department->charterLaw->act_number ?? $department->name),
            ];
        }

        // In-force enabling laws whose jurisdiction covers the executive's.
        $laws = Law::query()
            ->whereIn('status', [Law::STATUS_IN_FORCE, Law::STATUS_AMENDED])
            ->whereIn('jurisdiction_id', $chain)
            ->where('kind', '!=', Law::KIND_CHARTER)
            ->orderByDesc('enacted_at')
            ->get(['id', 'act_number', 'title']);

        foreach ($laws as $law) {
            $options[] = [
                'type' => ExecutiveOrder::ENABLING_LAW,
                'id' => (string) $law->id,
                'label' => trim(($law->act_number ?? 'Act').' — '.($law->title ?? '')),
            ];
        }

        // ACTIVE emergency powers whose declared area covers the executive's.
        $powers = EmergencyPower::query()
            ->whereIn('status', [EmergencyPower::STATUS_ACTIVE, EmergencyPower::STATUS_RENEWED])
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->whereIn('area_jurisdiction_id', $chain)
            ->orderByDesc('starts_at')
            ->get(['id', 'label', 'expires_at']);

        foreach ($powers as $power) {
            $options[] = [
                'type' => ExecutiveOrder::ENABLING_EMERGENCY_POWER,
                'id' => (string) $power->id,
                'label' => 'Emergency power — '.$power->label.' (expires '.$power->expires_at->toDateString().')',
            ];
        }

        return $options;
    }

    /**
     * Ancestor chain (self + ancestors) of a jurisdiction — the set an
     * enabling instrument may bind to and still cover the executive
     * (mirrors EnablingInstruments::jurisdictionCovers, batched).
     *
     * @return list<string>
     */
    private function jurisdictionChain(string $jurisdictionId): array
    {
        $chain = [];
        $current = $jurisdictionId;

        for ($depth = 0; $depth < 32 && $current !== ''; $depth++) {
            $chain[] = $current;
            $parent = DB::table('jurisdictions')->where('id', $current)->value('parent_id');

            if ($parent === null) {
                break;
            }

            $current = (string) $parent;
        }

        return $chain;
    }

    /** Acts anchor on their enacting bill; direct adoptions on public records. */
    private function lawHref(?Law $law): ?string
    {
        if ($law === null) {
            return null;
        }

        return $law->enacting_bill_id !== null
            ? '/bills/'.$law->enacting_bill_id
            : '/system/public-records';
    }

    /** The viewer's SEATED seat on this department's board (R-18), or null. */
    private function viewerSeat(Department $department, $user): ?BoardSeat
    {
        if ($user === null || $department->board_id === null) {
            return null;
        }

        return BoardSeat::query()
            ->where('board_id', (string) $department->board_id)
            ->where('holder_user_id', (string) $user->getKey())
            ->where('status', BoardSeat::STATUS_SEATED)
            ->first();
    }
}
