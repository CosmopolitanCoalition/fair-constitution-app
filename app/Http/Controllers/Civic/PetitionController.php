<?php

namespace App\Http\Controllers\Civic;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\Jurisdiction;
use App\Models\Petition;
use App\Models\PetitionSignature;
use App\Models\PublicRecord;
use App\Models\ReferendumQuestion;
use App\Models\User;
use App\Services\PetitionService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use App\Support\CivicPopulation;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-C10 — Petitions + PetitionDetail (PHASE_C_DESIGN_frontend.md
 * §B.12/§B.13).
 *
 *   GET    /civic/petitions                 index
 *   POST   /civic/petitions                 store   F-IND-009
 *   GET    /civic/petitions/{petition}      show
 *   POST   /petitions/{petition}/signatures sign    F-IND-010
 *   DELETE /petitions/{petition}/signatures revoke  F-IND-010 (revocable)
 *
 * Create requires R-03/R-05 — ENGINE-enforced; the page explains and
 * never 403s (the un-associated viewer sees the residency CTA instead of
 * the form). Thresholds are SNAPSHOTS (CLK-17) — every number rendered
 * comes off the petition row, never recomputed client-side.
 */
class PetitionController extends Controller
{
    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly RoleService $roles,
        private readonly SettingsResolver $settings,
    ) {
    }

    public function index(Request $request): Response
    {
        $user         = $request->user();
        $associations = $this->roles->associationsFor($user);
        $chainIds     = array_column($associations, 'id');

        // Scoped to the association chain; an un-associated viewer still
        // reads (public record) — the instance-wide recent list.
        $petitions = Petition::query()
            ->when($chainIds !== [], fn ($q) => $q->whereIn('jurisdiction_id', $chainIds))
            ->with('jurisdiction:id,name,slug,adm_level')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $thresholdPct = $this->resolvedPct($associations);

        return Inertia::render('Civic/Petitions', [
            'surface'   => SurfaceMeta::for('civic/petitions'),
            'petitions' => $petitions
                ->map(fn (Petition $petition) => $this->listRow($petition, $user))
                ->values()
                ->all(),
            'machine'          => config('cga.state_machines.petition'),
            'thresholdSetting' => [
                'pct'   => number_format($thresholdPct, 2),
                'key'   => 'initiative_petition_threshold_pct',
                'clock' => 'CLK-17',
            ],
            'createForm' => [
                // The viewer's chain — you can petition at any level you
                // belong to; live threshold preview per option (CIVIC
                // population, the same basis the snapshot will use).
                'scaleOptions' => array_map(function (array $association) {
                    $population = CivicPopulation::of($association['id']);
                    $pct        = (float) ($this->settings->resolve($association['id'], 'initiative_petition_threshold_pct') ?? 5.00);

                    return [
                        'id'                => $association['id'],
                        'name'              => $association['name'],
                        'adm_level'         => $association['adm_level'],
                        'population'        => $population,
                        'threshold_pct'     => number_format($pct, 2),
                        'threshold_preview' => PetitionService::thresholdCount($population, $pct),
                    ];
                }, $associations),
                'actTypes' => Petition::ACT_TYPES,
            ],
            'isAssociated' => $chainIds !== [],
        ]);
    }

    /** F-IND-009 — Petition Creation (Created → Gathering atomic at filing). */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'jurisdiction_id'     => ['required', 'uuid'],
            'title'               => ['required', 'string', 'max:300'],
            'law_text'            => ['required', 'string', 'max:50000'],
            'act_type'            => ['nullable', 'string'],
            'targets_setting_key' => ['nullable', 'string', 'max:64'],
            'proposed_value'      => ['nullable'],
        ]);

        $result = $this->engine->file('F-IND-009', $request->user(), [
            'jurisdiction_id'     => $validated['jurisdiction_id'],
            'title'               => $validated['title'],
            'law_text'            => $validated['law_text'],
            'act_type'            => $validated['act_type'] ?? 'ordinary',
            'scale'               => [$validated['jurisdiction_id']],
            'targets_setting_key' => $validated['targets_setting_key'] ?? null,
            'proposed_value'      => $validated['proposed_value'] ?? null,
        ]);

        $petitionId = $result->recorded['petition_id'] ?? null;
        $threshold  = $result->recorded['threshold_count'] ?? null;

        return back()->with(
            'status',
            'Petition registered (F-IND-009) — signature gathering opens immediately.'
            . ($threshold !== null ? " Threshold snapshot: {$threshold} signatures (CLK-17)." : '')
            . ($petitionId !== null ? " Track it at /civic/petitions/{$petitionId}." : '')
        );
    }

    public function show(Request $request, Petition $petition): Response
    {
        $user = $request->user();
        $petition->loadMissing('jurisdiction:id,name,slug,adm_level', 'creator:id,name,display_name');

        $auditRecord = PublicRecord::query()
            ->where('subject_type', 'petition')
            ->where('subject_id', (string) $petition->id)
            ->where('via_form', 'F-ELB-005')
            ->first();

        $question = $petition->referendum_question_id !== null
            ? ReferendumQuestion::query()->find($petition->referendum_question_id)
            : null;

        $election = $question?->election_id !== null
            ? Election::query()->find($question->election_id)
            : null;

        return Inertia::render('Civic/PetitionDetail', [
            'surface'  => SurfaceMeta::for('civic/petition-detail'),
            'petition' => [
                'id'           => (string) $petition->id,
                'title'        => $petition->title,
                'creator'      => $petition->creator?->display_name ?: $petition->creator?->name,
                'jurisdiction' => [
                    'name'      => $petition->jurisdiction?->name,
                    'adm_level' => $petition->jurisdiction?->adm_level,
                ],
                'state'           => $petition->status,
                'law_text'        => $petition->law_text,
                'act_type'        => $petition->act_type,
                'scale'           => $this->scaleNames($petition),
                'scope_label'     => $this->scopeLabel($petition),
                'signatures'      => $petition->liveSignatureCount(),
                'threshold_count' => (int) $petition->threshold_count,
                'pct'             => number_format((float) $petition->threshold_pct, 2),
                'population_basis' => (int) $petition->population_basis,
                'signed_by_me'    => $this->signedByMe($petition, $user),
                'signable'        => in_array($petition->status, Petition::SIGNABLE_STATUSES, true),
            ],
            'machine'      => config('cga.state_machines.petition'),
            'currentState' => $petition->status,
            'audit'        => $petition->audit_result !== null ? [
                'result' => [
                    'checked'     => (int) ($petition->audit_result['checked'] ?? 0),
                    'valid'       => (int) ($petition->audit_result['valid'] ?? 0),
                    'pct_valid'   => (string) ($petition->audit_result['pct'] ?? '0.0'),
                    'still_above' => (bool) ($petition->audit_result['passed'] ?? false),
                ],
                'board_name'   => ($petition->jurisdiction?->name ?? 'The') . ' election board',
                'completed_at' => $auditRecord?->published_at?->toDayDateTimeString(),
                'record_href'  => $auditRecord?->audit_seq !== null
                    ? '/system/audit-chain?seq=' . (int) $auditRecord->audit_seq
                    : null,
            ] : null,
            'review' => $this->reviewProps($petition),
            'ballot' => $question !== null && $election !== null ? [
                'election_id' => (string) $election->id,
                'label'       => ucfirst((string) $election->kind) . ' election · ' . $election->status,
                'href'        => "/elections/{$election->id}",
            ] : null,
            'urls' => [
                'signatures' => "/petitions/{$petition->id}/signatures",
            ],
        ]);
    }

    /** F-IND-010 — sign (the only gate is association — Art. I). */
    public function sign(Request $request, Petition $petition): RedirectResponse
    {
        $this->engine->file('F-IND-010', $request->user(), [
            'petition_id'     => (string) $petition->id,
            'jurisdiction_id' => (string) $petition->jurisdiction_id,
            'revoke'          => false,
        ]);

        return back()->with('status', 'Signature appended to the record (F-IND-010) — revocable while the petition gathers.');
    }

    /** F-IND-010 — revoke the live signature (same form, revoke: true). */
    public function revoke(Request $request, Petition $petition): RedirectResponse
    {
        $this->engine->file('F-IND-010', $request->user(), [
            'petition_id'     => (string) $petition->id,
            'jurisdiction_id' => (string) $petition->jurisdiction_id,
            'revoke'          => true,
        ]);

        return back()->with('status', 'Signature revoked (F-IND-010) — signatures stay revocable until the audited count freezes.');
    }

    // =========================================================================
    // Presentation internals
    // =========================================================================

    private function listRow(Petition $petition, User $user): array
    {
        $signatures = $petition->liveSignatureCount();

        return [
            'id'           => (string) $petition->id,
            'title'        => $petition->title,
            'jurisdiction' => [
                'name'      => $petition->jurisdiction?->name,
                'adm_level' => $petition->jurisdiction?->adm_level,
            ],
            'state'           => $petition->status,
            'signatures'      => $signatures,
            'threshold_count' => (int) $petition->threshold_count,
            'pct'             => number_format((float) $petition->threshold_pct, 2),
            'scale_label'     => implode(' · ', $this->scaleNames($petition)),
            'scope_label'     => $this->scopeLabel($petition),
            'signed_by_me'    => $this->signedByMe($petition, $user),
            'signable'        => in_array($petition->status, Petition::SIGNABLE_STATUSES, true),
            'href'            => "/civic/petitions/{$petition->id}",
            'sign_url'        => "/petitions/{$petition->id}/signatures",
        ];
    }

    /** @return list<string> */
    private function scaleNames(Petition $petition): array
    {
        $scale = array_map('strval', (array) ($petition->scale ?? []));

        if ($scale === []) {
            return array_filter([$petition->jurisdiction?->name]);
        }

        return Jurisdiction::query()
            ->whereIn('id', $scale)
            ->orderBy('adm_level')
            ->pluck('name')
            ->all();
    }

    private function scopeLabel(Petition $petition): string
    {
        // No judiciary exists in Phase C (scope_judiciary_id stays null) —
        // the honest default: disputes go to the scale's own judiciary
        // once Phase E seats one.
        return ($petition->jurisdiction?->name ?? 'The jurisdiction')
            . ' judiciary hears disputes (forming · Phase E)';
    }

    private function signedByMe(Petition $petition, ?User $user): bool
    {
        return $user !== null && PetitionSignature::query()
            ->where('petition_id', $petition->id)
            ->where('user_id', (string) $user->getKey())
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * §B.13 review card — Phase C renders the F-JDG-008 stub HONESTLY:
     * petitions HOLD at constitutional_review; the kill-path is
     * constitutional, not skippable.
     */
    private function reviewProps(Petition $petition): ?array
    {
        $reached = ! in_array($petition->status, [
            Petition::STATUS_CREATED,
            Petition::STATUS_GATHERING,
            Petition::STATUS_THRESHOLD_REACHED,
            Petition::STATUS_SIGNATURE_AUDIT,
        ], true);

        $auditPassed = (bool) ($petition->audit_result['passed'] ?? false);

        if (! $reached || ($petition->status === Petition::STATUS_INVALIDATED && ! $auditPassed)) {
            // Never reached review (or died at the audit kill-path).
            return null;
        }

        $status = match (true) {
            $petition->status === Petition::STATUS_CONSTITUTIONAL_REVIEW => 'pending',
            $petition->status === Petition::STATUS_INVALIDATED           => 'invalidated',
            default                                                      => 'validated',
        };

        return [
            'status'              => $status,
            'court_label'         => ($petition->jurisdiction?->name ?? 'The') . ' judiciary (forming)',
            'opinion_record_href' => null,
            'stubbed'             => $status === 'pending' || (bool) $petition->review_stub,
        ];
    }

    private function resolvedPct(array $associations): float
    {
        $deepest = $associations === [] ? null : end($associations);

        return (float) ($deepest !== null
            ? ($this->settings->resolve($deepest['id'], 'initiative_petition_threshold_pct') ?? 5.00)
            : 5.00);
    }
}
