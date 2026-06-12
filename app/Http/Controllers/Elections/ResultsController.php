<?php

namespace App\Http\Controllers\Elections;

use App\Domain\Forms\Support\RaceFootprint;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Elections\Concerns\ResolvesBoardActor;
use App\Http\Presenters\StvRoundPresenter;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\ElectionAudit;
use App\Models\ElectionCertification;
use App\Models\ElectionRace;
use App\Models\Tabulation;
use App\Support\ElectionPhase;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FE-B6 — Results (PHASE_B_DESIGN_frontend.md §B.6 + §C).
 *
 *   GET /elections/{election}/results[?race=]      — show
 *   GET /elections/{election}/results.csv[?race=]  — csv (streaming)
 *
 * The round-by-round payload is StvRoundPresenter's §C contract: key
 * rounds carry tallies inline, mid rounds collapse to heading + transfer.
 * The CSV streams straight off `tabulation_rounds` at FULL precision —
 * no presenter, the auditor's record.
 */
class ResultsController extends Controller
{
    use ResolvesBoardActor;

    public function __construct(
        private readonly StvRoundPresenter $presenter,
    ) {
    }

    public function show(Request $request, Election $election): Response|RedirectResponse
    {
        $phase = ElectionPhase::phase($election);

        // No tabulation can exist before the window closes — the election
        // page is the right surface (§B.6 edge states).
        if (in_array($phase, ['approval', 'ranked'], true)) {
            return redirect("/elections/{$election->id}")->with(
                'status',
                'No count exists yet — results appear once the ranked window closes and tabulation runs.'
            );
        }

        $race = $this->resolveRace($request, $election);

        $initial = $this->latestComplete($race, Tabulation::KIND_INITIAL);
        $rerun = $this->latestComplete($race, Tabulation::KIND_AUDIT_RERUN);

        $running = Tabulation::query()
            ->where('race_id', (string) $race->id)
            ->where('status', Tabulation::STATUS_RUNNING)
            ->exists();

        // voting_closed/tabulating with no complete record = the instant
        // count is in flight (jobs queued on long-running) → poll state.
        $tabulationStatus = match (true) {
            $initial !== null || $rerun !== null => 'complete',
            $running,
            in_array($election->status, [Election::STATUS_VOTING_CLOSED, Election::STATUS_TABULATING], true) => 'running',
            default => 'none',
        };

        $certification = ElectionCertification::query()
            ->where('election_id', (string) $election->id)
            ->where('status', ElectionCertification::STATUS_CERTIFIED)
            ->with('board:id,jurisdiction_id,is_bootstrap')
            ->first();

        $audits = ElectionAudit::query()
            ->where('election_id', (string) $election->id)
            ->orderBy('ordered_at')
            ->get()
            ->map(fn (ElectionAudit $audit) => [
                'id'         => (string) $audit->id,
                'cause'      => $audit->cause,
                'ordered_at' => $audit->ordered_at?->toIso8601String(),
                'outcome'    => $audit->outcome,
            ])
            ->all();

        $board = $this->activeBoardFor($election->election_board_id, $election->jurisdiction_id);
        $boardActor = $this->boardActorFor($request->user(), $board);

        $allRacesCounted = ! $election->races()->get()->contains(
            fn (ElectionRace $r) => $this->latestComplete($r, null) === null
        );

        return Inertia::render('Elections/Results', [
            'surface' => SurfaceMeta::for('elections/results'),
            'election' => [
                'id'                => (string) $election->id,
                'kind'              => $election->kind,
                'status'            => $election->status,
                'phase'             => $phase,
                'certSubStep'       => ElectionPhase::certSubStep($election),
                'jurisdiction_name' => $election->jurisdiction?->name,
            ],
            'race' => [
                'id'             => (string) $race->id,
                'label'          => $this->raceLabel($race),
                'seats'          => (int) $race->seats,
                'seat_kind'      => $race->seat_kind,
                'finalist_count' => (int) $race->finalist_count,
            ],
            'races' => $election->races()->get()
                ->map(fn (ElectionRace $r) => [
                    'id'    => (string) $r->id,
                    'label' => $this->raceLabel($r),
                ])
                ->all(),
            'tabulation' => ['status' => $tabulationStatus],
            // The certified count record (§C) — null while tabulating.
            'stv' => $initial !== null ? $this->presenter->present($initial) : null,
            // Audit re-run record, badged alongside when F-ELB-006 ran (§C).
            'auditStv' => $rerun !== null ? $this->presenter->present($rerun) : null,
            'audits' => $audits,
            'observers' => $this->observers($race, $certification !== null),
            'certification' => $certification !== null ? [
                'certified_at'      => $certification->certified_at?->toIso8601String(),
                'by'                => $certification->board?->is_bootstrap
                    ? 'Bootstrap election board (system)'
                    : 'Election board',
                'count_record_hash' => $certification->count_record_hash,
            ] : null,
            'can' => [
                'certify' => $boardActor !== false
                    && in_array($election->status, [Election::STATUS_TABULATING, Election::STATUS_AUDIT_RERUN], true)
                    && $allRacesCounted,
                'recount' => $boardActor !== false && $certification !== null,
            ],
            'csvHref' => "/elections/{$election->id}/results.csv?race={$race->id}",
        ]);
    }

    /**
     * GET /elections/{election}/results.csv — the full-precision count
     * record, streamed straight off tabulation_rounds (§C "CSV"). One row
     * per transfer destination; transfer-less rounds (shortcut fills)
     * stream one row for the acted-on candidacy.
     */
    public function csv(Request $request, Election $election): StreamedResponse
    {
        $race = $this->resolveRace($request, $election);
        $tabulation = $this->latestComplete($race, null);

        abort_if($tabulation === null, 404, 'No complete tabulation exists for this race.');

        $names = $this->presenter->candidateRefs((string) $race->id);
        $name = fn (?string $id): string => $id !== null ? ($names[$id]['name'] ?? $id) : '';

        $filename = "{$race->id}-count-record.csv";

        return response()->streamDownload(function () use ($tabulation, $names, $name) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'round_no', 'action', 'candidacy', 'votes',
                'transfer_from', 'transfer_value', 'transfer_votes', 'exhausted',
            ]);

            foreach ($tabulation->rounds()->cursor() as $round) {
                $tallies = $round->tallies['candidates'] ?? [];
                $transfer = $round->transfer;
                $actor = $round->candidacy_id !== null ? (string) $round->candidacy_id : null;

                if (! is_array($transfer) || ($transfer['to'] ?? []) === []) {
                    fputcsv($out, [
                        $round->round_no,
                        $round->action,
                        $name($actor),
                        self::full((int) ($tallies[$actor] ?? 0)),
                        '', '', '',
                        self::full((int) ($round->tallies['exhausted_micro'] ?? 0)),
                    ]);

                    continue;
                }

                $exhausted = self::full((int) ($transfer['exhausted_micro'] ?? 0));
                $value = ($transfer['kind'] ?? null) === 'surplus' && isset($transfer['value_micro'])
                    ? self::full((int) $transfer['value_micro'])
                    : '';

                foreach ($transfer['to'] as $pair) {
                    $destination = (string) $pair[0];

                    fputcsv($out, [
                        $round->round_no,
                        $round->action,
                        $name($destination),
                        self::full((int) ($tallies[$destination] ?? 0)),
                        $name($actor),
                        $value,
                        self::full((int) $pair[1]),
                        $exhausted,
                    ]);
                }
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** ?race= override → viewer's footprint race → the election's first race. */
    private function resolveRace(Request $request, Election $election): ElectionRace
    {
        $raceId = $request->query('race');

        if (is_string($raceId)) {
            $race = ElectionRace::query()
                ->where('election_id', (string) $election->id)
                ->whereKey($raceId)
                ->first();

            if ($race !== null) {
                return $race;
            }
        }

        $user = $request->user();

        if ($user !== null) {
            $binding = RaceFootprint::bestRaceForUser((string) $user->getKey(), (string) $election->id);

            if ($binding !== null) {
                return ElectionRace::query()->findOrFail((string) $binding->race_id);
            }
        }

        return $election->races()->orderBy('created_at')->firstOrFail();
    }

    /**
     * Latest complete tabulation of one kind. Null kind = the certified
     * record line (initial or audit_rerun) — NEVER a countback, which
     * belongs to its vacancy page, not the race results.
     */
    private function latestComplete(ElectionRace $race, ?string $kind): ?Tabulation
    {
        return Tabulation::query()
            ->where('race_id', (string) $race->id)
            ->when(
                $kind !== null,
                fn ($q) => $q->where('kind', $kind),
                fn ($q) => $q->whereIn('kind', [Tabulation::KIND_INITIAL, Tabulation::KIND_AUDIT_RERUN]),
            )
            ->complete()
            ->whereNotNull('record_hash')
            ->orderByDesc('completed_at')
            ->first();
    }

    /**
     * Chain-of-custody observers: endorsing organizations + the race's
     * candidates (no faction layer — design §B.6).
     *
     * @return list<array{name: string, standing: string, href: string|null, attested: bool}>
     */
    private function observers(ElectionRace $race, bool $certified): array
    {
        $orgs = DB::table('endorsements as e')
            ->join('candidacies as c', 'c.id', '=', 'e.candidate_id')
            ->join('organizations as o', 'o.id', '=', 'e.endorser_id')
            ->where('c.race_id', (string) $race->id)
            ->where('e.endorser_type', 'organization')
            ->where('e.is_active', true)
            ->whereNull('e.withdrawn_at')
            ->distinct()
            ->orderBy('o.name')
            ->get(['o.id', 'o.name', 'o.type'])
            ->map(fn ($row) => [
                'name'     => $row->name,
                'standing' => (string) $row->type,
                'href'     => null,
                'attested' => $certified,
            ]);

        $candidates = Candidacy::query()
            ->where('race_id', (string) $race->id)
            ->with('user:id,name,display_name')
            ->orderBy('created_at')
            ->get(['id', 'user_id'])
            ->map(fn (Candidacy $candidacy) => [
                'name'     => ($candidacy->user?->display_name ?: $candidacy->user?->name) . ' (candidate)',
                'standing' => 'candidate standing',
                'href'     => '/candidates/' . $candidacy->id,
                'attested' => $certified,
            ]);

        return $orgs->concat($candidates)->values()->all();
    }

    private function raceLabel(ElectionRace $race): string
    {
        $jurisdiction = $race->jurisdiction?->name ?? 'Race';

        if ($race->isAtLarge()) {
            return "{$jurisdiction} at-large — {$race->seats} seats";
        }

        $number = $race->district?->district_number;

        return $number !== null
            ? "{$jurisdiction} — district {$number} · {$race->seats} seats"
            : "{$jurisdiction} — {$race->seats} seats";
    }

    /** µv → full-precision vote string (up to 6 dp, trailing zeros trimmed). */
    private static function full(int $micro): string
    {
        $whole = intdiv($micro, 1_000_000);
        $frac = $micro % 1_000_000;

        if ($frac === 0) {
            return (string) $whole;
        }

        return rtrim(sprintf('%d.%06d', $whole, $frac), '0');
    }
}
