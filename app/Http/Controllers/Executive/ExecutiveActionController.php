<?php

namespace App\Http\Controllers\Executive;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\Appropriation;
use App\Models\Department;
use App\Models\EmergencyPower;
use App\Models\Executive;
use App\Models\ExecutiveInvestigation;
use App\Models\ExecutiveMember;
use App\Models\ExecutiveOrder;
use App\Models\GrantApplication;
use App\Models\Law;
use App\Models\Organization;
use App\Models\PolicyProposal;
use App\Models\PublicRecord;
use App\Services\Executive\GrantService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-D4 — Executive/Actions (PHASE_D_DESIGN_frontend.md §B.5; surface
 * executive/executive-actions) — THE order-rejection exit surface.
 *
 *   GET  /executives/{executive}/actions — the hardened scope-validation
 *        rails banner, the order register (issued + rejected_pre_issuance
 *        rows — rejections ARE public record), policy proposals,
 *        investigations, and grants/appropriations.
 *
 * POSTs (all public-read, composer gated R-14/15/16 of THIS executive):
 *   /executives/{e}/orders          — F-EXE-005 (engine; preflight scope
 *        validation persists the rejected_pre_issuance row + record BEFORE
 *        the 422 — exit criterion 1: the rejection surfaces verbatim and
 *        the row appears at the TOP of the register).
 *   /executives/{e}/policy-proposals — F-EXE-002 (engine).
 *   /executives/{e}/investigations   — F-EXE-004 (engine).
 *   /appropriations/{a}/applications  — GrantService::apply (a direct
 *        service call: no F-* form exists for an application; the
 *        ConstitutionalViolation it may throw surfaces as 422 through the
 *        global handler, the same as any engine filing).
 *
 * Every threshold/seat/`remaining` here is a row snapshot — the engine
 * owns the arithmetic (ExecutiveOrderService preflight, GrantService FOR
 * UPDATE). This controller only reads rows and opens the door.
 */
class ExecutiveActionController extends Controller
{
    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly GrantService $grants,
    ) {}

    // =========================================================================
    // GET /executives/{executive}/actions
    // =========================================================================

    public function index(Request $request, Executive $executive): Response
    {
        $executive->loadMissing('jurisdiction', 'delegationLaw');

        $viewerMember = $this->viewerMember($executive, $request->user());
        $isPrincipal = $viewerMember !== null && $viewerMember->role === ExecutiveMember::ROLE_PRINCIPAL;

        $departments = Department::query()
            ->where('executive_id', $executive->id)
            ->whereNot('status', Department::STATUS_DISSOLVED)
            ->orderBy('name')
            ->get();

        $canCompose = $isPrincipal && in_array(
            $executive->status,
            [Executive::STATUS_DELEGATED, Executive::STATUS_ELECTED],
            true
        );

        return Inertia::render('Executive/Actions', [
            'surface' => SurfaceMeta::for('executive/executive-actions'),
            'executive' => $this->executiveHeader($executive),
            'orderMachine' => $this->orderMachine(),
            'scopeBanner' => $this->scopeBanner($executive),
            'orders' => $this->orderRows($executive),
            'orderForm' => [
                'departmentOptions' => $departments
                    ->map(fn (Department $d) => ['id' => (string) $d->id, 'name' => $d->name])
                    ->values()
                    ->all(),
                'enablingOptions' => $this->enablingOptions($executive),
            ],
            'proposals' => $this->proposalRows($executive),
            'investigations' => $this->investigationRows($executive),
            'appropriations' => $this->appropriationRows($executive),
            'applications' => $this->applicationRows($executive),
            'grantForm' => [
                'orgOptions' => $this->orgOptions($executive),
            ],
            'can' => [
                'issueOrder' => $canCompose,
                'propose' => $canCompose,
                'investigate' => $canCompose,
                'administerGrants' => $isPrincipal && $viewerMember?->status === ExecutiveMember::STATUS_SEATED,
            ],
        ]);
    }

    // =========================================================================
    // POSTs
    // =========================================================================

    /**
     * F-EXE-005 — issue an order. On an out-of-scope order the engine's
     * validator stage (ExecutiveOrderService::preflight) has already
     * committed the rejected_pre_issuance row + its public record before
     * rethrowing; the 422 surfaces as errors.constitution (the citation
     * verbatim) and the rejected row reloads at the top of the register.
     */
    public function storeOrder(Request $request, Executive $executive): RedirectResponse
    {
        $this->engine->file('F-EXE-005', $request->user(), [
            'action' => 'issue',
            'executive_id' => (string) $executive->id,
            'jurisdiction_id' => (string) $executive->jurisdiction_id,
            'department_id' => $request->input('department_id') ?: null,
            'title' => (string) $request->input('title', ''),
            'body' => (string) $request->input('body', ''),
            'enabling_type' => (string) $request->input('enabling_type', ''),
            'enabling_id' => (string) $request->input('enabling_id', ''),
            'target_domain' => (string) $request->input('target_domain', ''),
        ]);

        return back()->with(
            'status',
            'Order issued — scope validated pre-issuance; judicially reviewable at any time (F-EXE-005 · Art. IV §5).'
        );
    }

    /** F-EXE-002 — propose a policy; the department BOARD decides. */
    public function storeProposal(Request $request, Executive $executive): RedirectResponse
    {
        $this->engine->file('F-EXE-002', $request->user(), [
            'executive_id' => (string) $executive->id,
            'jurisdiction_id' => (string) $executive->jurisdiction_id,
            'department_id' => (string) $request->input('department_id', ''),
            'title' => (string) $request->input('title', ''),
            'text' => (string) $request->input('text', ''),
        ]);

        return back()->with(
            'status',
            'Policy proposed (F-EXE-002) — the board adopts, amends, or declines; proposals never bypass the board.'
        );
    }

    /** F-EXE-004 — order an investigation over an overseen department. */
    public function storeInvestigation(Request $request, Executive $executive): RedirectResponse
    {
        $this->engine->file('F-EXE-004', $request->user(), [
            'action' => 'open',
            'executive_id' => (string) $executive->id,
            'jurisdiction_id' => (string) $executive->jurisdiction_id,
            'department_id' => $request->input('department_id') ?: null,
            'scope' => (string) $request->input('scope', ''),
            'records_access' => (array) $request->input('records_access', []),
        ]);

        return back()->with(
            'status',
            'Investigation opened (F-EXE-004) — full and equal investigative power; findings publish to the public record.'
        );
    }

    /**
     * Grant application against an appropriation line. There is no F-*
     * form for an application — GrantService::apply runs directly and any
     * ConstitutionalViolation surfaces as 422 through the global handler.
     */
    public function storeApplication(Request $request, Appropriation $appropriation): RedirectResponse
    {
        $org = Organization::query()->findOrFail((string) $request->input('applicant_org_id'));

        $this->grants->apply(
            $appropriation,
            $org,
            (float) $request->input('amount', 0),
            (string) $request->input('purpose', ''),
        );

        return back()->with('status', 'Grant application submitted against '.$appropriation->line.'.');
    }

    // =========================================================================
    // Props assembly
    // =========================================================================

    /** The viewer's member row of THIS executive (any status), or null. */
    private function viewerMember(Executive $executive, $user): ?ExecutiveMember
    {
        if ($user === null) {
            return null;
        }

        return ExecutiveMember::query()
            ->where('executive_id', $executive->id)
            ->where('user_id', (string) $user->getKey())
            ->whereIn('status', [ExecutiveMember::STATUS_SEATED])
            ->first();
    }

    /** §B.1 header shape (carried by every Executive page). */
    private function executiveHeader(Executive $executive): array
    {
        $jurisdiction = $executive->jurisdiction;

        return [
            'id' => (string) $executive->id,
            'type' => $executive->type,
            'status' => $executive->status,
            'jurisdiction' => $jurisdiction !== null ? [
                'id' => (string) $jurisdiction->id,
                'name' => $jurisdiction->name,
                'href' => '/jurisdictions/'.($jurisdiction->slug ?? $jurisdiction->id),
            ] : null,
            'delegated_scope_text' => $executive->delegated_scope,
        ];
    }

    /** ESM-* — the ORDER lifecycle (model constants), PHP-owned. */
    private function orderMachine(): array
    {
        return [
            ExecutiveOrder::STATUS_DRAFTED,
            ExecutiveOrder::STATUS_SCOPE_VALIDATED,
            ExecutiveOrder::STATUS_ISSUED,
            ExecutiveOrder::STATUS_REJECTED_PRE_ISSUANCE,
            ExecutiveOrder::STATUS_UNDER_REVIEW,
            ExecutiveOrder::STATUS_STRUCK,
            ExecutiveOrder::STATUS_REVOKED,
        ];
    }

    /**
     * The delegation act + the LIVE emergency powers whose declared area
     * covers this executive's jurisdiction (the only instruments that
     * widen delegated scope — Art. II §7).
     */
    private function scopeBanner(Executive $executive): array
    {
        $delegationAct = null;

        if ($executive->delegationLaw !== null) {
            $law = $executive->delegationLaw;
            $delegationAct = [
                'label' => $law->act_number.' — delegation',
                'href' => $this->lawHref($law),
            ];
        }

        $activePowers = EmergencyPower::query()
            ->whereIn('status', [EmergencyPower::STATUS_ACTIVE, EmergencyPower::STATUS_RENEWED])
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->with('areaJurisdiction:id,name')
            ->get()
            ->filter(fn (EmergencyPower $power) => \App\Services\Executive\EnablingInstruments::jurisdictionCovers(
                (string) $power->area_jurisdiction_id,
                (string) $executive->jurisdiction_id,
            ))
            ->map(fn (EmergencyPower $power) => [
                'label' => $power->label,
                'area' => $power->areaJurisdiction?->name,
                'expires_at' => $power->expires_at?->toDateString(),
                'href' => '/legislatures/'.$power->legislature_id.'/emergency-powers',
            ])
            ->values()
            ->all();

        return [
            'delegation_act' => $delegationAct,
            'active_powers' => $activePowers,
        ];
    }

    /**
     * The order register — OrderScopeCard props, newest first so a freshly
     * rejected_pre_issuance row lands at the top (exit criterion 1).
     *
     * @return list<array<string, mixed>>
     */
    private function orderRows(Executive $executive): array
    {
        $orders = ExecutiveOrder::query()
            ->where('executive_id', $executive->id)
            ->with('department:id,name')
            ->orderByDesc('created_at')
            ->get();

        // Resolve enabling labels (law/charter act_number or power label)
        // and the public-record seq for the chip, in batch.
        $lawIds = $orders->whereIn('enabling_type', [ExecutiveOrder::ENABLING_LAW, ExecutiveOrder::ENABLING_CHARTER])
            ->pluck('enabling_id')->filter()->unique();
        $powerIds = $orders->where('enabling_type', ExecutiveOrder::ENABLING_EMERGENCY_POWER)
            ->pluck('enabling_id')->filter()->unique();
        $recordIds = $orders->pluck('record_id')->filter()->unique();

        $laws = Law::query()->whereIn('id', $lawIds)->get()->keyBy('id');
        $powers = EmergencyPower::query()->whereIn('id', $powerIds)->get()->keyBy('id');
        $seqs = PublicRecord::query()->whereIn('id', $recordIds)->pluck('seq', 'id');

        return $orders->map(function (ExecutiveOrder $order) use ($laws, $powers, $seqs) {
            $enabling = $this->orderEnabling($order, $laws, $powers);

            $seq = $order->record_id !== null ? ($seqs[$order->record_id] ?? null) : null;

            return [
                'id_display' => $order->order_no ?? 'EO — pending',
                'title' => $order->title,
                'department' => $order->department !== null ? ['name' => $order->department->name] : null,
                'issued_at_display' => $order->issued_at?->format('Y-m-d H:i')
                    ?? $order->created_at?->format('Y-m-d H:i'),
                'status' => $order->status,
                'enabling' => $enabling,
                'rejection_citation' => $order->rejection_citation,
                'note' => $order->status === ExecutiveOrder::STATUS_ISSUED
                    ? 'Within delegated scope · validated pre-issuance'
                    : null,
                'public_record' => $seq !== null
                    ? ['seq' => (int) $seq, 'href' => '/system/public-records?seq='.$seq]
                    : null,
                'review' => null,
            ];
        })->values()->all();
    }

    /** @return array{type: string, label: string, href: string}|null */
    private function orderEnabling(ExecutiveOrder $order, $laws, $powers): ?array
    {
        if ($order->enabling_type === ExecutiveOrder::ENABLING_EMERGENCY_POWER) {
            $power = $powers[$order->enabling_id] ?? null;

            return [
                'type' => 'emergency_power',
                'label' => $power?->label ?? 'Emergency power',
                'href' => $power !== null
                    ? '/legislatures/'.$power->legislature_id.'/emergency-powers'
                    : '#',
            ];
        }

        $law = $laws[$order->enabling_id] ?? null;

        if ($law === null) {
            return null;
        }

        $suffix = $order->enabling_type === ExecutiveOrder::ENABLING_CHARTER ? ' — charter function' : ' — delegation';

        return [
            'type' => 'law',
            'label' => $law->act_number.$suffix,
            'href' => $this->lawHref($law),
        ];
    }

    /**
     * Order composer enabling options: the delegation/charter laws this
     * executive may cite + LIVE emergency powers covering its jurisdiction.
     *
     * @return list<array{type: string, id: string, label: string}>
     */
    private function enablingOptions(Executive $executive): array
    {
        $options = [];

        if ($executive->delegationLaw !== null) {
            $options[] = [
                'type' => ExecutiveOrder::ENABLING_LAW,
                'id' => (string) $executive->delegationLaw->id,
                'label' => $executive->delegationLaw->act_number.' — delegation act',
            ];
        }

        // Department charters this executive oversees.
        $charters = Law::query()
            ->where('kind', Law::KIND_CHARTER)
            ->whereIn('status', [Law::STATUS_IN_FORCE, Law::STATUS_AMENDED])
            ->whereIn('id', Department::query()
                ->where('executive_id', $executive->id)
                ->whereNotNull('charter_law_id')
                ->pluck('charter_law_id'))
            ->get();

        foreach ($charters as $charter) {
            $options[] = [
                'type' => ExecutiveOrder::ENABLING_CHARTER,
                'id' => (string) $charter->id,
                'label' => $charter->act_number.' — charter',
            ];
        }

        $powers = EmergencyPower::query()
            ->whereIn('status', [EmergencyPower::STATUS_ACTIVE, EmergencyPower::STATUS_RENEWED])
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->get()
            ->filter(fn (EmergencyPower $power) => \App\Services\Executive\EnablingInstruments::jurisdictionCovers(
                (string) $power->area_jurisdiction_id,
                (string) $executive->jurisdiction_id,
            ));

        foreach ($powers as $power) {
            $options[] = [
                'type' => ExecutiveOrder::ENABLING_EMERGENCY_POWER,
                'id' => (string) $power->id,
                'label' => $power->label.' — emergency power',
            ];
        }

        return $options;
    }

    /** @return list<array<string, mixed>> */
    private function proposalRows(Executive $executive): array
    {
        return PolicyProposal::query()
            ->where('executive_id', $executive->id)
            ->with('department:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PolicyProposal $proposal) => [
                'title' => $proposal->title,
                'department' => $proposal->department !== null
                    ? ['name' => $proposal->department->name, 'href' => '/departments/'.$proposal->department->id]
                    : null,
                'status' => $proposal->decision,
                'decided_at' => $proposal->decided_at?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function investigationRows(Executive $executive): array
    {
        return ExecutiveInvestigation::query()
            ->where('executive_id', $executive->id)
            ->with('department:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (ExecutiveInvestigation $investigation) {
                $findingsHref = null;

                if ($investigation->findings_record_id !== null) {
                    $seq = PublicRecord::query()->where('id', $investigation->findings_record_id)->value('seq');
                    $findingsHref = $seq !== null ? '/system/public-records?seq='.$seq : '/system/public-records';
                }

                return [
                    'title' => 'Investigation — '.\Illuminate\Support\Str::limit($investigation->scope, 60),
                    'department' => $investigation->department?->name,
                    'scope' => $investigation->scope,
                    'status' => $investigation->outcome,
                    'outcome' => $investigation->outcome === ExecutiveInvestigation::OUTCOME_OPEN
                        ? null
                        : $investigation->outcome,
                    'findings_record_href' => $findingsHref,
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function appropriationRows(Executive $executive): array
    {
        return Appropriation::query()
            ->where('executive_id', $executive->id)
            ->with('law:id,act_number,enacting_bill_id')
            ->orderBy('line')
            ->get()
            ->map(fn (Appropriation $appropriation) => [
                'id' => (string) $appropriation->id,
                'line' => $appropriation->line,
                'act' => $appropriation->law !== null
                    ? ['act_number' => $appropriation->law->act_number, 'href' => $this->lawHref($appropriation->law)]
                    : null,
                'appropriated' => (float) $appropriation->amount,
                'remaining' => (float) $appropriation->remaining,
                'status' => $appropriation->status,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function applicationRows(Executive $executive): array
    {
        return GrantApplication::query()
            ->whereIn('appropriation_id', Appropriation::query()
                ->where('executive_id', $executive->id)
                ->pluck('id'))
            ->with(['applicant:id,name,slug', 'appropriation:id,line', 'disbursements'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (GrantApplication $application) => [
                'id' => (string) $application->id,
                'org' => $application->applicant !== null ? [
                    'name' => $application->applicant->name,
                    'href' => '/organizations/'.($application->applicant->slug ?? $application->applicant->id),
                ] : null,
                'line' => $application->appropriation?->line,
                'amount' => (float) $application->amount,
                'purpose' => $application->purpose,
                'status' => $application->status,
                'disbursements' => $application->disbursements
                    ->map(fn ($disbursement) => [
                        'amount' => (float) $disbursement->amount,
                        'at' => $disbursement->disbursed_at?->toDateString(),
                        'audit_seq' => $this->disbursementSeq($disbursement),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /** The audit_seq sealing a disbursement's public record (chip text). */
    private function disbursementSeq($disbursement): ?int
    {
        $record = PublicRecord::query()
            ->where('subject_type', 'grant_disbursements')
            ->where('subject_id', (string) $disbursement->id)
            ->first();

        return $record?->audit_seq;
    }

    /**
     * Organizations registered in this executive's jurisdiction chain that
     * may apply (the registry — name/id only).
     *
     * @return list<array{id: string, name: string}>
     */
    private function orgOptions(Executive $executive): array
    {
        return Organization::query()
            ->whereNot('type', Organization::TYPE_COMMON_GOOD_CORP)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name'])
            ->map(fn (Organization $org) => ['id' => (string) $org->id, 'name' => $org->name])
            ->all();
    }

    /** Acts anchor on their enacting bill; direct adoptions on public records. */
    private function lawHref(?Law $law): string
    {
        if ($law === null) {
            return '/system/public-records';
        }

        return $law->enacting_bill_id !== null
            ? '/bills/'.$law->enacting_bill_id
            : '/system/public-records';
    }
}
