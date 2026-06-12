<?php

namespace App\Http\Controllers\Legislature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Legislature\Concerns\ResolvesChamber;
use App\Models\AdminOffice;
use App\Models\AuditEntry;
use App\Models\Committee;
use App\Models\ElectionBoard;
use App\Models\Law;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Vacancy;
use App\Support\SurfaceMeta;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-C2 — Chamber (PHASE_C_DESIGN_frontend.md §B.1; surface
 * legislature/legislature-home).
 *
 *   GET  /legislatures/{legislature}/chamber — the seat map, roster,
 *        peg-quorum stats, term-lockstep card, vacancy cards, and the
 *        WF-LEG-01 first-sessions checklist driven by REAL rows
 *        (speaker_id, rules/ethics laws, admin office, proper board,
 *        committees).
 *   POST /members/{member}/oath              — F-LEG-001 (own row only;
 *        the engine enforces, the route is just the door).
 *
 * Public read (legislature business is public record — Art. II §2);
 * the only action is the viewer's own oath.
 */
class ChamberController extends Controller
{
    use ResolvesChamber;

    public function __construct(private readonly ConstitutionalEngine $engine)
    {
    }

    public function show(Request $request, Legislature $legislature): Response
    {
        $legislature->loadMissing('jurisdiction');

        $forming = $legislature->status !== Legislature::STATUS_ACTIVE;
        $viewer  = $this->viewerMember($legislature, $request->user());

        $members = $forming ? collect() : LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->with(['user:id,name,display_name', 'district:id,district_number'])
            ->orderBy('seat_no')
            ->get();

        return Inertia::render('Legislature/Chamber', [
            'surface'       => SurfaceMeta::for('legislature/legislature-home'),
            'legislature'   => $this->legislatureProps($legislature),
            'members'       => $this->memberRows($legislature, $members),
            'vacancies'     => $this->vacancyRows($legislature),
            'firstSessions' => $this->firstSessionsChecklist($legislature),
            'mapperHref'    => '/legislatures/' . ($legislature->jurisdiction?->slug ?? $legislature->id),
            'can'           => [
                'takeOath'     => $viewer !== null && $viewer->status === LegislatureMember::STATUS_ELECTED,
                'oathMemberId' => $viewer !== null ? (string) $viewer->id : null,
                'isMember'     => $viewer !== null,
            ],
        ]);
    }

    /** F-LEG-001 — the viewer's own oath; everything else is the engine's. */
    public function oath(Request $request, LegislatureMember $member): RedirectResponse
    {
        abort_unless(
            $request->user() !== null && (string) $member->user_id === (string) $request->user()->getKey(),
            403,
            'The oath is taken by the seated member themself (F-LEG-001).'
        );

        $this->engine->file('F-LEG-001', $request->user(), [
            'legislature_id'  => (string) $member->legislature_id,
            'jurisdiction_id' => (string) $member->legislature?->jurisdiction_id,
        ]);

        return back()->with('status', 'Oath taken — you are seated (F-LEG-001 · Art. II §1). The public record carries the entry.');
    }

    // -------------------------------------------------------------------------

    /**
     * SeatMap + roster rows: serving members plus one synthetic row per
     * open vacancy ("vacancies join at the junior-most position").
     *
     * @return list<array<string, mixed>>
     */
    private function memberRows(Legislature $legislature, $members): array
    {
        if ($members->isEmpty()) {
            return [];
        }

        // Org endorsements of the candidacy each member was elected on.
        $candidacies = DB::table('candidacies as c')
            ->join('endorsements as en', 'en.candidate_id', '=', 'c.id')
            ->join('organizations as o', 'o.id', '=', 'en.endorser_id')
            ->where('en.endorser_type', 'organization')
            ->where('en.is_active', true)
            ->whereIn('c.race_id', $members->pluck('elected_in_race_id')->filter())
            ->whereIn('c.user_id', $members->pluck('user_id'))
            ->get(['c.race_id', 'c.user_id', 'o.name', 'o.type as organization_type']);

        $speakerId = $legislature->speaker_id !== null ? (string) $legislature->speaker_id : null;
        $today     = CarbonImmutable::now();

        $rows = $members->map(function (LegislatureMember $member) use ($candidacies, $speakerId, $today) {
            $since = $member->seated_at ?? $member->seated_on;

            return [
                'id'              => (string) $member->id,
                'seat_no'         => (int) $member->seat_no,
                'name'            => $this->memberDisplayName($member),
                'speaker'         => $speakerId !== null && (string) $member->id === $speakerId,
                'vacant'          => false,
                'seat_kind'       => $member->seatKind(),
                'days_served'     => $since !== null ? max(0, (int) CarbonImmutable::parse($since)->diffInDays($today)) : 0,
                'vote_share_norm' => $member->vote_share_norm !== null ? (float) $member->vote_share_norm : null,
                'district_label'  => $member->district?->district_number !== null
                    ? "District {$member->district->district_number}"
                    : null,
                'status'          => $member->status,
                'note'            => $member->status === LegislatureMember::STATUS_ELECTED
                    ? 'elected — oath pending (F-LEG-001)'
                    : null,
                'endorsements'    => $candidacies
                    ->where('race_id', $member->elected_in_race_id)
                    ->where('user_id', (string) $member->user_id)
                    ->map(fn ($row) => ['name' => $row->name, 'org_type' => $row->organization_type])
                    ->values()
                    ->all(),
            ];
        });

        // Synthetic vacant dots — one per unresolved vacancy.
        $taken = $members->pluck('seat_no')->all();

        $vacantSeats = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', [LegislatureMember::STATUS_VACATED, LegislatureMember::STATUS_REMOVED])
            ->whereNotIn('seat_no', $taken)
            ->orderBy('seat_no')
            ->get()
            ->unique('seat_no')
            ->map(fn (LegislatureMember $member) => [
                'id'              => 'vacant-' . $member->seat_no,
                'seat_no'         => (int) $member->seat_no,
                'name'            => null,
                'speaker'         => false,
                'vacant'          => true,
                'seat_kind'       => $member->seatKind(),
                'days_served'     => 0,
                'vote_share_norm' => null,
                'district_label'  => null,
                'status'          => 'vacant',
                'note'            => 'vacant — ' . ($member->vacancy_reason ?? 'vacated'),
                'endorsements'    => [],
            ]);

        return $rows->concat($vacantSeats)->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function vacancyRows(Legislature $legislature): array
    {
        return Vacancy::query()
            ->where('legislature_id', $legislature->id)
            ->whereNot('status', Vacancy::STATUS_FILLED)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Vacancy $vacancy) {
                $member = $vacancy->seat_type === 'legislature_members'
                    ? LegislatureMember::query()->with('user:id,name,display_name')->find($vacancy->seat_id)
                    : null;

                return [
                    'id'           => (string) $vacancy->id,
                    'seat_no'      => $member?->seat_no,
                    'member_name'  => $this->memberDisplayName($member),
                    'status'       => $vacancy->status,
                    'declared_via' => $vacancy->declared_via_form,
                    'href'         => "/vacancies/{$vacancy->id}",
                ];
            })
            ->values()
            ->all();
    }

    /**
     * WF-LEG-01 — the first-sessions checklist, each step DONE only when
     * its real row exists (speaker pointer, law rows, office/board rows,
     * committees).
     *
     * @return list<array<string, mixed>>
     */
    private function firstSessionsChecklist(Legislature $legislature): array
    {
        $serving = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES);

        $servingCount = (clone $serving)->count();
        $seatedCount  = (clone $serving)->where('status', LegislatureMember::STATUS_SEATED)->count();

        $rules  = $this->lawOfKind($legislature, Law::KIND_RULES_OF_ORDER);
        $ethics = $this->lawOfKind($legislature, Law::KIND_ETHICS_CODE);

        $adminOffice = AdminOffice::query()
            ->where('legislature_id', $legislature->id)
            ->whereNot('status', 'dissolved')
            ->first();

        $properBoard = ElectionBoard::query()
            ->where('jurisdiction_id', $legislature->jurisdiction_id)
            ->where('is_bootstrap', false)
            ->whereNot('status', 'retired')
            ->first();

        $committees = Committee::query()
            ->where('legislature_id', $legislature->id)
            ->whereNot('status', Committee::STATUS_DISSOLVED)
            ->count();

        $speakerSeatedAt = $legislature->speaker_id !== null
            ? AuditEntry::query()
                ->where('module', 'legislature')
                ->where('event', 'speaker.seated')
                ->where('payload->legislature_id', (string) $legislature->id)
                ->orderByDesc('seq')
                ->value('occurred_at')
            : null;

        return [
            [
                'form_id' => 'F-LEG-001',
                'name'    => 'Oath of office / seating acceptance',
                'desc'    => 'Every elected member takes the oath; the seat flips elected → seated.',
                'basis'   => 'Art. II §1',
                'done_at' => $servingCount > 0 && $seatedCount === $servingCount ? 'all-seated' : null,
                'note'    => "{$seatedCount} of {$servingCount} serving members seated",
                'act_href' => null,
            ],
            [
                'form_id' => 'F-LEG-008',
                'name'    => 'Speaker election — supermajority RCV',
                'desc'    => 'The chamber elects its politically neutral presiding officer.',
                'basis'   => 'Art. II §3',
                'done_at' => $speakerSeatedAt !== null ? (string) $speakerSeatedAt : ($legislature->speaker_id !== null ? 'done' : null),
                'note'    => $legislature->speaker_id === null ? 'no Speaker yet — first order of the first session' : null,
                'act_href' => null,
            ],
            [
                'form_id' => 'F-LEG-032',
                'name'    => 'Rules of order adoption',
                'desc'    => 'Adopted by ordinary majority; versioned as a law.',
                'basis'   => 'Art. II §2',
                'done_at' => $rules?->enacted_at?->toIso8601String(),
                'note'    => $rules?->act_number,
                'act_href' => $this->lawHref($rules),
            ],
            [
                'form_id' => 'F-LEG-033',
                'name'    => 'Ethics code adoption',
                'desc'    => 'Binds all elected officials and civil officers.',
                'basis'   => 'Art. II §2',
                'done_at' => $ethics?->enacted_at?->toIso8601String(),
                'note'    => $ethics?->act_number,
                'act_href' => $this->lawHref($ethics),
            ],
            [
                'form_id' => 'F-LEG-013',
                'name'    => 'Administrative office creation act',
                'desc'    => 'The independent admin office (I-ADM) — oversight intake and record-keeping.',
                'basis'   => 'Art. II §2',
                'done_at' => $adminOffice?->created_at?->toIso8601String(),
                'note'    => $adminOffice !== null ? "office {$adminOffice->status}" : null,
                'act_href' => null,
            ],
            [
                'form_id' => 'F-LEG-012',
                'name'    => 'Election board creation act',
                'desc'    => 'The proper board retires the bootstrap board and takes custody of elections.',
                'basis'   => 'Art. II §2',
                'done_at' => $properBoard?->created_at?->toIso8601String(),
                'note'    => $properBoard !== null ? "board {$properBoard->status}" : 'bootstrap board still authoritative',
                'act_href' => null,
            ],
            [
                'form_id' => 'F-LEG-009',
                'name'    => 'Committee creation acts',
                'desc'    => 'Committees by supermajority; faction-independent assignment follows (F-SPK-005).',
                'basis'   => 'Art. II §4',
                'done_at' => $committees > 0 ? 'done' : null,
                'note'    => $committees > 0 ? "{$committees} committee(s)" : null,
                'act_href' => $committees > 0 ? "/legislatures/{$legislature->id}/committees" : null,
            ],
        ];
    }

    private function lawOfKind(Legislature $legislature, string $kind): ?Law
    {
        return Law::query()
            ->where('legislature_id', $legislature->id)
            ->where('kind', $kind)
            ->where('status', Law::STATUS_IN_FORCE)
            ->orderByDesc('enacted_at')
            ->first();
    }

    /** Acts anchor on their enacting bill page; direct adoptions on public records. */
    private function lawHref(?Law $law): ?string
    {
        if ($law === null) {
            return null;
        }

        return $law->enacting_bill_id !== null
            ? "/bills/{$law->enacting_bill_id}"
            : '/system/public-records';
    }
}
