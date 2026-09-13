<?php

namespace App\Http\Controllers\Legislature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Forms\Support\ChamberActor;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Executive;
use App\Models\Legislature;
use App\Models\MultiJurisdictionVote;
use App\Support\InstitutionActWorkspace;
use App\Support\JurisdictionContext;
use App\Support\LegislatureWorkspace;
use App\Support\SurfaceMeta;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class InstitutionActController extends Controller
{
    public function __construct(private readonly ConstitutionalEngine $engine, private readonly InstitutionActWorkspace $workspace) {}

    public function show(Request $request, Legislature $legislature)
    {
        $request->validate(['action' => 'nullable|string|max:40']);
        $legislature->loadMissing('jurisdiction:id,name,slug,parent_id,adm_level');
        $context = $this->workspace->context($legislature, $request->user());

        return Inertia::render('Legislature/InstitutionActs', [
            'surface' => SurfaceMeta::for('legislature/institution-acts'),
            'jurisdictionContext' => $legislature->jurisdiction ? JurisdictionContext::forRoom($legislature->jurisdiction) : null,
            'workspace' => LegislatureWorkspace::for($legislature, $legislature->jurisdiction, $context['isSpeaker']),
            'legislature' => ['id' => $legislature->id, 'name' => ($legislature->jurisdiction?->name ?? 'This jurisdiction').' legislature'],
            'context' => $context, 'filingUrl' => $this->workspace->base($legislature),
            'initialAction' => array_key_exists($request->query('action', ''), InstitutionActWorkspace::ACTIONS) ? $request->query('action') : 'delegate-executive',
            'proposals' => fn () => $this->workspace->proposals($request, $legislature),
            'processes' => fn () => $this->workspace->processes($request, $legislature),
            'constituents' => fn () => $this->workspace->constituents($request, $legislature),
        ]);
    }

    public function store(Request $request, Legislature $legislature)
    {
        $action = $request->validate(['action' => ['required', Rule::in(array_keys(InstitutionActWorkspace::ACTIONS))]])['action'];
        $meta = InstitutionActWorkspace::ACTIONS[$action];
        $member = ChamberActor::member($request->user(), $legislature->id, $meta['form']);
        $rules = match ($action) {
            'delegate-executive' => ['delegated_scope' => 'required|string|max:30000', 'member_count' => 'required|integer|min:1', 'interested' => 'sometimes|boolean'],
            'elect-executive' => ['target_type' => ['required', Rule::in(['committee', 'individual'])], 'member_count' => 'nullable|integer|min:1', 'charter_text' => 'required|string|max:30000'],
            'create-department' => ['name' => 'required|string|max:255', 'kind' => ['required', Rule::in([...Department::MANDATORY_KINDS, 'other'])], 'function_text' => 'required|string|max:30000', 'powers_text' => 'nullable|string|max:30000', 'reporting_interval_months' => 'nullable|integer|min:1', 'owner_seats' => 'required|integer|min:1'],
            'create-court' => ['court_name' => 'required|string|max:255', 'function_text' => 'required|string|max:30000', 'judges_per_constituent' => 'nullable|integer|min:1', 'committee_judge_count' => 'nullable|integer|min:1'],
            'elect-court' => ['judge_count' => 'required|integer|min:1', 'charter_text' => 'required|string|max:30000'],
            'create-cgc' => ['name' => 'required|string|max:255', 'charter' => 'required|string|max:30000', 'goods_services' => 'nullable|string|max:30000', 'owner_seats' => 'required|integer|min:1|max:99'],
        };
        $payload = $request->validate($rules);
        if ($action === 'delegate-executive') {
            $payload['interest'] = ! empty($payload['interested']) ? [(string) $member->id] : [];
            unset($payload['interested']);
        }
        if (in_array($action, ['create-department', 'create-cgc'], true)) {
            $executive = Executive::query()->where('jurisdiction_id', $legislature->jurisdiction_id)->whereIn('status', ['delegated', 'elected'])->first(['id']);
            // CGC oversight can be assigned only to this place's executive.
            // Leaving it unset remains supported by the existing charter handler.
            $payload[$action === 'create-department' ? 'executive_id' : 'oversight_executive_id'] = $executive?->id;
        }
        if ($action === 'create-department') {
            $payload['charter'] = ['function_text' => $payload['function_text'], 'powers_text' => $payload['powers_text'] ?? '', 'reporting_interval_months' => $payload['reporting_interval_months'] ?? null];
            unset($payload['function_text'], $payload['powers_text'], $payload['reporting_interval_months']);
            $payload['nominees'] = [];
        }
        $this->engine->file($meta['form'], $request->user(), $payload + ['legislature_id' => (string) $legislature->id, 'jurisdiction_id' => (string) $legislature->jurisdiction_id]);

        return redirect($this->workspace->base($legislature).'?action='.$action)->with('status', 'Proposal filed. Its public vote and progress appear below.');
    }

    public function consent(Request $request, Legislature $legislature, MultiJurisdictionVote $process)
    {
        abort_unless(in_array($process->kind, ['exec_office_create', 'judiciary_convert'], true), 404);
        abort_unless($process->consents()->where('jurisdiction_id', $legislature->jurisdiction_id)->exists(), 404);
        $form = $process->kind === 'judiciary_convert' ? 'F-LEG-018' : 'F-LEG-015';
        ChamberActor::member($request->user(), $legislature->id, $form);
        $this->engine->file($form, $request->user(), ['action' => 'open_constituent_consent', 'process_id' => (string) $process->id,
            'legislature_id' => (string) $legislature->id, 'jurisdiction_id' => (string) $legislature->jurisdiction_id]);

        return redirect($this->workspace->base($legislature).'?process='.$process->id)->with('status', 'The constituent decision is open for this legislature’s votes.');
    }
}
