<?php

namespace App\Http\Controllers\Legislature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Legislature\Concerns\ResolvesChamber;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\Bill;
use App\Models\BillVersion;
use App\Models\ChamberVote;
use App\Models\Committee;
use App\Models\Legislature;
use App\Models\LegislatureSession;
use App\Models\PublicRecord;
use App\Models\SettingChange;
use App\Services\ConstitutionalValidator;
use App\Services\SettingsResolver;
use App\Support\SurfaceMeta;
use App\Support\TextDiff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-C4 — Bills + BillDetail (PHASE_C_DESIGN_frontend.md §B.3/§B.4;
 * surfaces legislature/bills + legislature/bill-detail).
 *
 *   GET  /legislatures/{legislature}/bills           — registry + intro FormCard
 *   POST /legislatures/{legislature}/bills           — F-LEG-003 introduction
 *   POST /legislatures/{legislature}/bills/validate  — PURE bounds pre-flight
 *        (no filing, no audit row — the live inline validator; the real
 *        rejection record is written when an out-of-range INTRODUCTION is
 *        actually filed)
 *   GET  /bills/{bill}                               — full lifecycle detail
 *   POST /bills/{bill}/refer                         — referral / direct-to-floor
 *        motion (F-LEG-007, needs the open session) or the chair's
 *        F-CHR-003 referral-to-floor after a passed committee vote
 *
 * Public read; introduction and referral gate on the engine (R-09/R-12).
 */
class BillController extends Controller
{
    use ResolvesChamber;

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly ConstitutionalValidator $validator,
        private readonly ChamberVotePresenter $votes,
        private readonly SettingsResolver $settings,
    ) {
    }

    // =========================================================================
    // GET /legislatures/{legislature}/bills
    // =========================================================================

    public function index(Request $request, Legislature $legislature): Response
    {
        $legislature->loadMissing('jurisdiction');

        $viewer = $this->viewerMember($legislature, $request->user());

        $bills = Bill::query()
            ->where('legislature_id', $legislature->id)
            ->with(['sponsor.user:id,name,display_name', 'enactedLaw:id,act_number,enacting_bill_id'])
            ->orderByDesc('introduced_at')
            ->get();

        $committees = Committee::query()
            ->where('legislature_id', $legislature->id)
            ->whereNot('status', Committee::STATUS_DISSOLVED)
            ->get(['id', 'name', 'status'])
            ->keyBy('id');

        return Inertia::render('Legislature/Bills', [
            'surface'     => SurfaceMeta::for('legislature/bills'),
            'legislature' => $this->legislatureProps($legislature),
            'machine'     => config('cga.state_machines.bill'),
            'bills'       => $bills->map(fn (Bill $bill) => [
                'id'            => (string) $bill->id,
                'title'         => $bill->title,
                'sponsor'       => ['name' => $this->memberDisplayName($bill->sponsor)],
                'status'        => $bill->status,
                'act_type'      => $bill->act_type,
                'scale_label'   => $this->scaleLabel($bill),
                'scope_label'   => $this->scopeLabel($bill),
                'introduced_at' => $bill->introduced_at?->toIso8601String(),
                'committee'     => $bill->committee_id !== null && $committees->has((string) $bill->committee_id)
                    ? ['id' => (string) $bill->committee_id, 'name' => $committees[(string) $bill->committee_id]->name]
                    : null,
                'enacted_law'   => $bill->enactedLaw !== null
                    ? ['act_number' => $bill->enactedLaw->act_number, 'href' => "/bills/{$bill->id}"]
                    : null,
                'challenge'     => null, // Phase E feed
            ])->values()->all(),
            'filters'     => [
                'status'   => $bills->pluck('status')->unique()->values()->all(),
                'act_type' => $bills->pluck('act_type')->unique()->values()->all(),
            ],
            'introForm'   => $this->introFormOptions($legislature),
            'can'         => ['introduce' => $viewer !== null],
        ]);
    }

    /** F-LEG-003 — introduction. */
    public function store(Request $request, Legislature $legislature): RedirectResponse
    {
        $proposed = $request->input('proposed_value');

        $this->engine->file('F-LEG-003', $request->user(), [
            'legislature_id'      => (string) $legislature->id,
            'jurisdiction_id'     => (string) $legislature->jurisdiction_id,
            'title'               => (string) $request->input('title', ''),
            'law_text'            => (string) $request->input('law_text', ''),
            'act_type'            => (string) $request->input('act_type', ''),
            'scale'               => array_values(array_filter((array) $request->input('scale', []))),
            'scope_judiciary_id'  => $request->input('scope_judiciary_id') ?: null,
            'targets_setting_key' => $request->input('targets_setting_key') ?: null,
            'proposed_value'      => is_numeric($proposed) ? $proposed + 0 : $proposed,
        ]);

        return back()->with('status', 'Bill introduced (F-LEG-003) — version 1 recorded; scale & scope are fixed at introduction (Art. V §4).');
    }

    /**
     * PURE bounds pre-flight (§B.3/§B.11): validate() without filing.
     * In-range → ok; out-of-range → the PROTECTED validator's rejection
     * verbatim. Nothing is recorded — the rejected=true chain row belongs
     * to a real filing.
     */
    public function validateSetting(Request $request, Legislature $legislature): JsonResponse
    {
        $value = $request->input('value');

        try {
            $this->validator->checkSettingChange([
                'setting_key' => (string) $request->input('setting_key', ''),
                'value'       => is_numeric($value) ? $value + 0 : $value,
            ]);
        } catch (ConstitutionalViolation $violation) {
            return response()->json([
                'ok'       => false,
                'message'  => $violation->getMessage(),
                'citation' => $violation->citation,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    // =========================================================================
    // GET /bills/{bill}
    // =========================================================================

    public function show(Request $request, Bill $bill): Response
    {
        $bill->loadMissing(['legislature.jurisdiction', 'sponsor.user:id,name,display_name', 'enactedLaw']);

        $legislature = $bill->legislature;
        $viewer      = $this->viewerMember($legislature, $request->user());

        $versions = BillVersion::query()
            ->where('bill_id', $bill->id)
            ->with('changedBy.user:id,name,display_name')
            ->orderBy('version_no')
            ->get();

        $current = $versions->firstWhere('version_no', $bill->current_version_no) ?? $versions->last();

        // Latest amendment diff — SERVER-computed (TextDiff), rendered verbatim.
        $diff = null;
        if ($versions->count() >= 2) {
            $previous = $versions->firstWhere('version_no', $bill->current_version_no - 1);

            if ($previous !== null && $current !== null) {
                $diff = [
                    'from_version' => (int) $previous->version_no,
                    'to_version'   => (int) $current->version_no,
                    'segments'     => TextDiff::segments($previous->law_text, $current->law_text),
                ];
            }
        }

        $committeeVote = $this->stageVote($bill, ChamberVote::STAGE_COMMITTEE);
        $floorVote     = $this->stageVote($bill, ChamberVote::STAGE_FLOOR);

        $committee = $bill->committee_id !== null
            ? Committee::query()->find($bill->committee_id, ['id', 'name', 'chair_member_id'])
            : null;

        $openSession = LegislatureSession::query()
            ->where('legislature_id', $legislature->id)
            ->where('status', LegislatureSession::STATUS_OPEN)
            ->orderByDesc('session_no')
            ->first(['id', 'session_no']);

        $isChair = $viewer !== null && $committee !== null
            && (string) ($committee->chair_member_id ?? '') === (string) $viewer->id;

        return Inertia::render('Legislature/BillDetail', [
            'surface' => SurfaceMeta::for('legislature/bill-detail'),
            'legislature' => $this->legislatureProps($legislature),
            'bill' => [
                'id'              => (string) $bill->id,
                'title'           => $bill->title,
                'sponsor'         => ['name' => $this->memberDisplayName($bill->sponsor)],
                'status'          => $bill->status,
                'act_type'        => $bill->act_type,
                'introduced_at'   => $bill->introduced_at?->toIso8601String(),
                'scale'           => $this->scaleEntries($bill),
                'scope'           => ['label' => $this->scopeLabel($bill)],
                'committee'       => $committee !== null
                    ? ['id' => (string) $committee->id, 'name' => $committee->name, 'href' => "/committees/{$committee->id}"]
                    : null,
                'targets_setting' => $this->targetsSetting($bill),
            ],
            'machine'  => config('cga.state_machines.bill'),
            'versions' => $versions->map(fn (BillVersion $version) => [
                'version_no'  => (int) $version->version_no,
                'change_kind' => $version->change_kind,
                'changed_by'  => $this->memberDisplayName($version->changedBy),
                'created_at'  => $version->created_at?->toIso8601String(),
            ])->values()->all(),
            'diff'    => $diff,
            'lawText' => $current?->law_text ?? '',
            'committeeVote' => $committeeVote !== null ? [
                'tally' => $this->votes->tallyProps($committeeVote),
                'casts' => $this->votes->casts($committeeVote),
            ] : null,
            'floorVote' => $floorVote !== null ? [
                'tally' => $this->votes->tallyProps($floorVote),
                'casts' => $this->votes->casts($floorVote),
            ] : null,
            'constituentProcess' => $this->constituentProcess($bill),
            'enactment'          => $this->enactment($bill),
            'openSession'        => $openSession !== null
                ? ['id' => (string) $openSession->id, 'session_no' => (int) $openSession->session_no]
                : null,
            'committees' => Committee::query()
                ->where('legislature_id', $legislature->id)
                ->whereNot('status', Committee::STATUS_DISSOLVED)
                ->get(['id', 'name'])
                ->map(fn (Committee $c) => ['id' => (string) $c->id, 'name' => $c->name])
                ->values()
                ->all(),
            'can' => [
                'castCommittee' => $viewer !== null
                    && $committeeVote !== null
                    && $committeeVote->status === ChamberVote::STATUS_OPEN,
                'castFloor'     => $viewer !== null
                    && $floorVote !== null
                    && $floorVote->status === ChamberVote::STATUS_OPEN,
                'refer'         => $viewer !== null
                    && in_array($bill->status, [Bill::STATUS_INTRODUCED, Bill::STATUS_REFERRED], true),
                'referToFloor'  => $isChair && $bill->status === Bill::STATUS_REPORTED,
            ],
        ]);
    }

    /**
     * POST /bills/{bill}/refer — three engine doors:
     *   mode=committee → F-LEG-007 referral motion (names the committee)
     *   mode=floor     → F-LEG-007 direct_to_floor motion
     *   mode=chair     → F-CHR-003 (after a passed committee vote — the
     *                    engine enforces `reported`)
     */
    public function refer(Request $request, Bill $bill): RedirectResponse
    {
        $mode = (string) $request->input('mode', 'floor');

        if ($mode === 'chair') {
            $this->engine->file('F-CHR-003', $request->user(), [
                'bill_id'         => (string) $bill->id,
                'jurisdiction_id' => (string) $bill->jurisdiction_id,
            ]);

            return back()->with('status', 'Referred to the floor (F-CHR-003) — the floor vote is open.');
        }

        $session = LegislatureSession::query()
            ->where('legislature_id', $bill->legislature_id)
            ->where('status', LegislatureSession::STATUS_OPEN)
            ->orderByDesc('session_no')
            ->first();

        if ($session === null) {
            throw new ConstitutionalViolation(
                'Referral moves in an open session — call one first (F-SPK-001).',
                'Art. II §2'
            );
        }

        $this->engine->file('F-LEG-007', $request->user(), [
            'session_id'      => (string) $session->id,
            'jurisdiction_id' => (string) $bill->jurisdiction_id,
            'kind'            => $mode === 'committee' ? 'referral' : 'direct_to_floor',
            'text'            => $mode === 'committee'
                ? "Refer “{$bill->title}” to committee"
                : "Move “{$bill->title}” directly to the floor",
            'bill_id'         => (string) $bill->id,
            'committee_id'    => $request->input('committee_id'),
        ]);

        return back()->with('status', 'Motion submitted (F-LEG-007) — adoption applies the referral in the same transaction as the closing vote.');
    }

    // =========================================================================
    // Props assembly
    // =========================================================================

    private function stageVote(Bill $bill, string $stage): ?ChamberVote
    {
        return ChamberVote::query()
            ->where('votable_type', 'bill')
            ->where('votable_id', $bill->id)
            ->where('stage', $stage)
            ->orderByDesc('opened_at')
            ->with('tallies')
            ->first();
    }

    private function introFormOptions(Legislature $legislature): array
    {
        $jid = (string) $legislature->jurisdiction_id;

        // Scale: the legislature's own jurisdiction + its direct children
        // (the engine validates the full subtree on filing; the picker
        // stays shallow for legibility — deeper ids may be typed by API).
        $scaleOptions = collect([[
            'id'   => $jid,
            'name' => ($legislature->jurisdiction?->name ?? 'Own jurisdiction') . ' (whole jurisdiction)',
        ]])->concat(
            DB::table('jurisdictions')
                ->where('parent_id', $jid)
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->limit(200)
                ->get(['id', 'name'])
                ->map(fn ($row) => ['id' => (string) $row->id, 'name' => $row->name])
        )->values()->all();

        $scopeOptions = DB::table('judiciaries as jd')
            ->join('jurisdictions as j', 'j.id', '=', 'jd.jurisdiction_id')
            ->whereNull('jd.deleted_at')
            ->where(function ($q) use ($jid) {
                $q->where('jd.jurisdiction_id', $jid)
                    ->orWhereIn('jd.jurisdiction_id', function ($sub) use ($jid) {
                        // ancestors — an encompassing court may hear
                        $sub->selectRaw('j2.id')
                            ->fromRaw("(WITH RECURSIVE chain AS (
                                SELECT id, parent_id FROM jurisdictions WHERE id = ?
                                UNION ALL
                                SELECT p.id, p.parent_id FROM chain c
                                JOIN jurisdictions p ON p.id = c.parent_id
                            ) SELECT id FROM chain) AS j2", [$jid]);
                    });
            })
            ->orderBy('j.adm_level', 'desc')
            ->limit(20)
            ->get(['jd.id', 'jd.status', 'j.name'])
            ->map(fn ($row) => [
                'id'    => (string) $row->id,
                'label' => $row->status === 'forming' ? "{$row->name} judiciary (forming · Phase E)" : "{$row->name} judiciary",
                'phase' => $row->status === 'forming' ? 'E' : null,
            ])
            ->values()
            ->all();

        $bounds = ConstitutionalValidator::SETTING_BOUNDS;

        $settingKeys = collect(SettingsController::REGISTER_KEYS)
            ->map(fn (string $key) => [
                'key'     => $key,
                'current' => $this->settings->resolve($jid, $key),
                'bounds'  => isset($bounds[$key])
                    ? array_intersect_key($bounds[$key], array_flip(['min', 'max', 'allowed', 'citation']))
                    : null,
            ])
            ->values()
            ->all();

        return [
            'scaleOptions' => $scaleOptions,
            'scopeOptions' => $scopeOptions,
            'actTypes'     => [
                ['value' => Bill::TYPE_ORDINARY, 'label' => 'Ordinary act', 'threshold_gloss' => 'majority of all serving · Art. II §2'],
                ['value' => Bill::TYPE_SETTING_CHANGE, 'label' => 'Amendable setting change', 'threshold_gloss' => 'majority + pre-vote bounds validation · Art. VII · WF-LEG-14'],
                ['value' => Bill::TYPE_SUPERMAJORITY, 'label' => 'Supermajority act', 'threshold_gloss' => 'ceil(serving × 2/3) of all serving · Art. VII'],
                ['value' => Bill::TYPE_DUAL_SUPERMAJORITY, 'label' => 'Dual-supermajority act', 'threshold_gloss' => 'chamber supermajority + 2/3 of constituent jurisdictions · Art. V §6'],
            ],
            'settingKeys'  => $settingKeys,
        ];
    }

    private function targetsSetting(Bill $bill): ?array
    {
        if ($bill->targets_setting_key === null) {
            return null;
        }

        $bounds = ConstitutionalValidator::SETTING_BOUNDS[$bill->targets_setting_key] ?? null;

        return [
            'key'      => $bill->targets_setting_key,
            'current'  => $this->settings->resolve((string) $bill->jurisdiction_id, $bill->targets_setting_key),
            'proposed' => $bill->proposed_value,
            'bounds'   => $bounds !== null
                ? array_intersect_key($bounds, array_flip(['min', 'max', 'allowed', 'citation']))
                : null,
        ];
    }

    private function constituentProcess(Bill $bill): ?array
    {
        $process = DB::table('multi_jurisdiction_votes')
            ->where('subject_type', 'bill')
            ->where('subject_id', (string) $bill->id)
            ->whereNull('deleted_at')
            ->first();

        if ($process === null) {
            return null;
        }

        $consents = DB::table('constituent_consents as cc')
            ->join('jurisdictions as j', 'j.id', '=', 'cc.jurisdiction_id')
            ->where('cc.process_id', $process->id)
            ->get(['j.name', 'cc.result']);

        return [
            'total'    => (int) $process->constituent_total,
            'required' => (int) $process->required,
            'yes'      => (int) $process->yes_count,
            'status'   => $process->status,
            'consents' => $consents->map(fn ($row) => [
                'jurisdiction' => $row->name,
                'result'       => $row->result,
                'act_href'     => null,
            ])->values()->all(),
        ];
    }

    private function enactment(Bill $bill): ?array
    {
        $law = $bill->enactedLaw;

        if ($law === null) {
            return null;
        }

        $change = SettingChange::query()
            ->where('law_id', $law->id)
            ->orderByDesc('applied_at')
            ->first();

        $recordSeq = PublicRecord::query()
            ->where('subject_type', 'law')
            ->where('subject_id', (string) $law->id)
            ->value('audit_seq');

        return [
            'law' => [
                'act_number' => $law->act_number,
                'href'       => '/system/public-records',
            ],
            'effective_at'   => $law->effective_at?->toIso8601String(),
            'setting_change' => $change !== null ? [
                'key' => $change->setting_key,
                'old' => $change->old_value,
                'new' => $change->new_value,
            ] : null,
            'record_href' => $recordSeq !== null ? "/system/audit-chain?seq={$recordSeq}" : '/system/public-records',
        ];
    }

    private function scaleLabel(Bill $bill): string
    {
        $entries = $this->scaleEntries($bill);

        return $entries === []
            ? 'own jurisdiction'
            : implode(', ', array_column($entries, 'name'));
    }

    /** @return list<array{id: string, name: string}> */
    private function scaleEntries(Bill $bill): array
    {
        $ids = array_values(array_map('strval', $bill->scale ?? []));

        if ($ids === []) {
            return [];
        }

        return DB::table('jurisdictions')
            ->whereIn('id', $ids)
            ->get(['id', 'name'])
            ->map(fn ($row) => ['id' => (string) $row->id, 'name' => $row->name])
            ->values()
            ->all();
    }

    private function scopeLabel(Bill $bill): string
    {
        if ($bill->scope_judiciary_id === null) {
            return 'default judiciary (forming · Phase E)';
        }

        $name = DB::table('judiciaries as jd')
            ->join('jurisdictions as j', 'j.id', '=', 'jd.jurisdiction_id')
            ->where('jd.id', (string) $bill->scope_judiciary_id)
            ->value('j.name');

        return $name !== null ? "{$name} judiciary" : 'named judiciary';
    }
}
