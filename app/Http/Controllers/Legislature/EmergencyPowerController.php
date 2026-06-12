<?php

namespace App\Http\Controllers\Legislature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Legislature\Concerns\ResolvesChamber;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\EmergencyPower;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\PublicRecord;
use App\Models\VoteCast;
use App\Services\EmergencyPowerService;
use App\Services\SettingsResolver;
use App\Support\SurfaceMeta;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-C9 — Emergency powers (PHASE_C_DESIGN_frontend.md §B.10).
 *
 *   GET  /legislatures/{legislature}/emergency-powers   index
 *   POST /legislatures/{legislature}/emergency-powers   store  F-LEG-024
 *   POST /emergency-powers/{power}/renewals             renew  F-LEG-025
 *
 * PUBLIC read (citizens must see active powers); invoke/renew gated R-09
 * by the engine. ALL validation is PRE-VOTE in EmergencyPowerService:
 * closed cause enum, 1..min(90, resolved max) duration, area ≤ this
 * legislature's authority, methods non-empty — rejections come back as
 * the verbatim ConstitutionalViolation (errors.constitution).
 */
class EmergencyPowerController extends Controller
{
    use ResolvesChamber;

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly ChamberVotePresenter $votes,
        private readonly SettingsResolver $settings,
    ) {
    }

    public function index(Request $request, Legislature $legislature): Response
    {
        $legislature->loadMissing('jurisdiction:id,name,slug');

        $viewer     = $this->viewerMember($legislature, $request->user());
        $windowDays = (int) config('cga.emergency_renewal_window_days', 14);

        return Inertia::render('Legislature/EmergencyPowers', [
            'surface'     => SurfaceMeta::for('legislature/emergency-powers'),
            'legislature' => $this->legislatureProps($legislature),
            'machine'     => config('cga.state_machines.emergency_powers'),
            'pending'     => $this->pendingProposalRows($legislature, $viewer),
            'active'      => $this->activeRows($legislature, $windowDays),
            'expired'     => $this->expiredRows($legislature),
            'invokeForm'  => [
                'maxDays' => min(
                    EmergencyPower::HARD_MAX_DAYS,
                    $this->settings->resolveInt(
                        (string) $legislature->jurisdiction_id,
                        'emergency_powers_max_days',
                        EmergencyPower::HARD_MAX_DAYS
                    )
                ),
                'windowDays'  => $windowDays,
                'areaOptions' => $this->areaOptions($legislature),
                'causes'      => EmergencyPower::CAUSES, // the closed enum — the form renders NO third option
            ],
            'can' => [
                'invoke' => $viewer !== null,
                'renew'  => $viewer !== null,
            ],
            'urls' => [
                'invoke' => "/legislatures/{$legislature->id}/emergency-powers",
            ],
        ]);
    }

    /** F-LEG-024 — Emergency Powers Declaration Vote (validated pre-vote). */
    public function store(Request $request, Legislature $legislature): RedirectResponse
    {
        $validated = $request->validate([
            'cause'                => ['required', 'string'],
            'label'                => ['required', 'string', 'max:200'],
            'duration_days'        => ['required', 'integer'],
            'area_jurisdiction_id' => ['nullable', 'uuid'],
            'methods'              => ['required', 'string', 'max:5000'],
        ]);

        $this->engine->file('F-LEG-024', $request->user(), [
            'legislature_id'       => (string) $legislature->id,
            'jurisdiction_id'      => (string) $legislature->jurisdiction_id,
            'cause'                => $validated['cause'],
            'label'                => $validated['label'],
            'duration_days'        => (int) $validated['duration_days'],
            'area_jurisdiction_id' => $validated['area_jurisdiction_id'] ?? (string) $legislature->jurisdiction_id,
            'methods'              => $validated['methods'],
        ]);

        return back()->with(
            'status',
            'Invocation validated and sent to a supermajority vote (F-LEG-024) — the power activates '
            . 'only on adoption; CLK-03 auto-expiry arms with it (Art. II §7).'
        );
    }

    /** F-LEG-025 — Emergency Powers Renewal Vote (fresh supermajority). */
    public function renew(Request $request, EmergencyPower $power): RedirectResponse
    {
        $validated = $request->validate([
            'extension_days' => ['required', 'integer'],
        ]);

        $this->engine->file('F-LEG-025', $request->user(), [
            'emergency_power_id' => (string) $power->id,
            'jurisdiction_id'    => (string) $power->jurisdiction_id,
            'extension_days'     => (int) $validated['extension_days'],
        ]);

        return back()->with(
            'status',
            'Renewal filed (F-LEG-025) — a fresh supermajority with its own ≤ 90-day ceiling; '
            . 'nothing rolls over silently (Art. II §7 · CLK-03).'
        );
    }

    // =========================================================================
    // Presentation internals
    // =========================================================================

    /** Open F-LEG-024 / F-LEG-025 proposals — the live supermajority votes. */
    private function pendingProposalRows(Legislature $legislature, ?LegislatureMember $viewer): array
    {
        return ChamberVoteProposal::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('proposal_kind', [
                ChamberVoteProposal::KIND_EMERGENCY_INVOCATION,
                ChamberVoteProposal::KIND_EMERGENCY_RENEWAL,
            ])
            ->where('status', ChamberVoteProposal::STATUS_OPEN)
            ->orderBy('created_at')
            ->get()
            ->map(function (ChamberVoteProposal $proposal) use ($viewer) {
                $vote    = $proposal->vote_id !== null
                    ? ChamberVote::query()->with('tallies')->find($proposal->vote_id)
                    : null;
                $payload = (array) $proposal->payload;

                $invocation = $proposal->proposal_kind === ChamberVoteProposal::KIND_EMERGENCY_INVOCATION;

                return [
                    'id'      => (string) $proposal->id,
                    'kind'    => $proposal->proposal_kind,
                    'label'   => $invocation
                        ? (string) ($payload['label'] ?? 'Emergency declaration')
                        : 'Renewal — extension of ' . (int) ($payload['extension_days'] ?? 0) . ' day(s)',
                    'summary' => $invocation
                        ? sprintf(
                            'cause: %s · duration %d day(s) · methods stated',
                            (string) ($payload['cause'] ?? ''),
                            (int) ($payload['duration_days'] ?? 0)
                        )
                        : 'fresh supermajority · fresh ≤ 90-day maximum',
                    'vote' => $vote !== null ? [
                        'tally'    => $this->votes->tallyProps($vote),
                        'casts'    => $this->votes->casts($vote),
                        'open'     => $vote->status === ChamberVote::STATUS_OPEN,
                        'my_cast'  => $this->memberHasCast($viewer, $vote),
                        'cast_url' => "/votes/{$vote->id}/cast",
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    private function activeRows(Legislature $legislature, int $windowDays): array
    {
        return EmergencyPower::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', EmergencyPower::LIVE_STATUSES)
            ->with('areaJurisdiction:id,name,slug')
            ->orderBy('starts_at')
            ->get()
            ->map(function (EmergencyPower $power) use ($windowDays) {
                $now     = CarbonImmutable::now();
                $starts  = CarbonImmutable::parse($power->starts_at);
                $expires = CarbonImmutable::parse($power->expires_at);

                $maxDays = max(1, (int) $starts->diffInDays($expires));
                $day     = min($maxDays, max(1, (int) $starts->diffInDays($now) + 1));
                $opensAt = EmergencyPowerService::renewalWindowOpensAt($expires, $windowDays);

                $invokeVote = $power->invoke_vote_id !== null
                    ? ChamberVote::query()->with('tallies')->find($power->invoke_vote_id)
                    : null;

                return [
                    'id'         => (string) $power->id,
                    'label'      => $power->label,
                    'cause'      => $power->cause,
                    'status'     => $power->status,
                    'day'        => $day,
                    'max_days'   => $maxDays,
                    'expires_at' => $expires->toIso8601String(),
                    'area'       => [
                        'label'     => $power->areaJurisdiction?->name ?? 'Whole jurisdiction',
                        'geom_href' => $power->areaJurisdiction?->slug !== null
                            ? "/jurisdictions/{$power->areaJurisdiction->slug}"
                            : null,
                    ],
                    'methods'     => $power->methods,
                    'invoke_vote' => $invokeVote !== null ? $this->votes->tallyProps($invokeVote) : null,
                    'renewals'    => $power->renewals()->orderBy('created_at')->get()
                        ->map(fn ($renewal) => [
                            'extension_days' => (int) $renewal->extension_days,
                            'vote_summary'   => sprintf(
                                'fresh supermajority · %s → %s',
                                CarbonImmutable::parse($renewal->previous_expires_at)->toDateString(),
                                CarbonImmutable::parse($renewal->new_expires_at)->toDateString()
                            ),
                        ])->all(),
                    'renewal_window' => [
                        'opens_day' => max(1, $maxDays - max(1, $windowDays)),
                        'opens_at'  => $opensAt->toDateString(),
                        'open_now'  => $now->gte($opensAt),
                    ],
                    'judicial_review' => $power->status === EmergencyPower::STATUS_UNDER_REVIEW ? 'pending' : 'none',
                    'renew_url'       => "/emergency-powers/{$power->id}/renewals",
                ];
            })
            ->values()
            ->all();
    }

    private function expiredRows(Legislature $legislature): array
    {
        $expired = EmergencyPower::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', [EmergencyPower::STATUS_EXPIRED, EmergencyPower::STATUS_STRUCK])
            ->orderByDesc('updated_at')
            ->get();

        if ($expired->isEmpty()) {
            return [];
        }

        // The CLK-03 auto-expiry public record per power (full audit record).
        $records = PublicRecord::query()
            ->where('subject_type', 'emergency_power')
            ->whereIn('subject_id', $expired->map(fn ($p) => (string) $p->id)->all())
            ->where('via_clock', 'CLK-03')
            ->get()
            ->keyBy('subject_id');

        return $expired
            ->map(function (EmergencyPower $power) use ($records) {
                $record = $records->get((string) $power->id);

                return [
                    'id'         => (string) $power->id,
                    'label'      => $power->label,
                    'status'     => $power->status,
                    'expired_at' => $power->updated_at?->toDateString(),
                    'record_href' => $record !== null && $record->audit_seq !== null
                        ? '/system/audit-chain?seq=' . (int) $record->audit_seq
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Own + descendant jurisdictions only — "≤ this legislature's
     * authority" (Art. II §7). Bounded recursive walk; the engine
     * re-validates the subtree at filing regardless.
     */
    private function areaOptions(Legislature $legislature): array
    {
        $rows = DB::select(
            'WITH RECURSIVE downs AS (
                SELECT j.id, j.name, j.adm_level, 0 AS depth
                FROM jurisdictions j WHERE j.id = ? AND j.deleted_at IS NULL
                UNION ALL
                SELECT c.id, c.name, c.adm_level, d.depth + 1
                FROM downs d
                JOIN jurisdictions c ON c.parent_id = d.id AND c.deleted_at IS NULL
                WHERE d.depth < 2
            )
            SELECT id, name, adm_level FROM downs ORDER BY depth, name LIMIT 200',
            [(string) $legislature->jurisdiction_id]
        );

        return array_map(fn (object $row) => [
            'id'   => (string) $row->id,
            'name' => $row->name,
        ], $rows);
    }

    private function memberHasCast(?LegislatureMember $member, ?ChamberVote $vote): bool
    {
        return $member !== null && $vote !== null && VoteCast::query()
            ->where('vote_id', $vote->id)
            ->where('member_id', (string) $member->id)
            ->exists();
    }
}
