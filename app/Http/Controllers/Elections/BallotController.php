<?php

namespace App\Http\Controllers\Elections;

use App\Domain\Ballots\BallotReceiptHolder;
use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Handlers\BallotSubmission;
use App\Domain\Forms\Support\RaceFootprint;
use App\Http\Controllers\Controller;
use App\Models\Ballot;
use App\Models\BallotEnvelope;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Support\ElectionPhase;
use App\Support\SurfaceMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-B5 — RankedBallot (PHASE_B_DESIGN_frontend.md §B.5 + §D).
 *
 *   GET  /elections/{election}/ranked-ballot[?race=]   — show
 *   POST /elections/{election}/races/{race}/ballots    — store (F-IND-007)
 *   POST .../referendum-ballots                        — storeReferendum (F-IND-008, Phase C)
 *   POST /receipt-check                                — receiptCheck (public)
 *
 * Receipt flow (§D.3/§D.4): the engine files F-IND-007; BallotBox stashes
 * the {ballot_hash, salt} receipt in the request-scoped
 * BallotReceiptHolder; this controller reads it AFTER file() returns and
 * flashes it — session flash is single-pull by construction: the
 * redirect-back response is the only place the hash ever crosses the wire
 * tied to this session. Nothing voter-linked persists it.
 *
 * Write-in search is search-driven through an Inertia partial reload of
 * THIS route (`only: ['writeInMatches'], data: { wq }`) — Earth races can
 * hold thousands of validated non-finalists; the page never enumerates.
 */
class BallotController extends Controller
{
    /** Ballot (Ranked) machine — display contract (§B.5; ESM-05). */
    public const BALLOT_MACHINE = ['Issued', 'Marked', 'Committed', 'Counted', 'Anonymized-Published'];

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly BallotReceiptHolder $receipts,
    ) {
    }

    public function show(Request $request, Election $election): Response|RedirectResponse
    {
        $user = $request->user();
        $userId = (string) $user->getKey();

        // Race resolution (§B conventions): the viewer's association
        // resolved into this election's race footprints; ?race= narrows
        // multi-race matches but can never bind a foreign race.
        $binding = RaceFootprint::bestRaceForUser($userId, (string) $election->id, $request->query('race'))
            ?? RaceFootprint::bestRaceForUser($userId, (string) $election->id);

        if ($binding === null) {
            // Non-R-04 viewers (or viewers outside every footprint) → the
            // open ballot, with the rights chain spelled out (§B.5).
            return redirect("/elections/{$election->id}/open-ballot")->with(
                'status',
                'The ranked ballot requires a jurisdictional association inside this race — voting and '
                . 'candidacy unlock together at residency verification (Art. I). You can browse the open ballot.'
            );
        }

        $race = ElectionRace::query()->findOrFail((string) $binding->race_id);

        $envelope = BallotEnvelope::query()
            ->where('race_id', (string) $race->id)
            ->where('user_id', $userId)
            ->where('kind', BallotEnvelope::KIND_RANKED)
            ->first(['committed_at']);

        return Inertia::render('Elections/RankedBallot', [
            'surface' => SurfaceMeta::for('elections/ranked-ballot'),
            'race' => [
                'id'               => (string) $race->id,
                'election_id'      => (string) $election->id,
                'label'            => $this->raceLabel($race),
                'seats'            => (int) $race->seats,
                'finalist_count'   => (int) $race->finalist_count,
                'phase'            => ElectionPhase::phase($election),
                'ranked_closes_at' => $election->ranked_closes_at?->toIso8601String(),
            ],
            'finalists'         => $this->finalists($race),
            'writeInsAvailable' => $this->writeInsAvailable($race),
            'alreadyVoted'      => $envelope !== null
                ? ['committed_at' => $envelope->committed_at?->toIso8601String()]
                : null,
            // Referendum content arrives with Phase C (F-LEG-023 pipeline);
            // the slot ships wired but empty — render nothing, not a fake.
            'referendum'        => null,
            'referendumVoted'   => false,
            // Live first-preference aggregate: null until its backend WI
            // lands — the page renders nothing (§B.5).
            'liveAggregate'     => null,
            'machine'           => self::BALLOT_MACHINE,
            // Search-driven write-in lookup (partial reload only).
            'writeInMatches'    => Inertia::optional(
                fn () => $this->searchWriteIns($race, (string) $request->query('wq', ''))
            ),
        ]);
    }

    /** F-IND-007 — commit the ranked ballot through the engine (§D.3). */
    public function store(Request $request, Election $election, ElectionRace $race): RedirectResponse
    {
        abort_unless((string) $race->election_id === (string) $election->id, 404);

        $validated = $request->validate([
            'rankings'   => ['required', 'array', 'min:1'],
            'rankings.*' => ['required', 'uuid'],
        ]);

        $this->engine->file('F-IND-007', $request->user(), [
            'race_id'         => (string) $race->id,
            'rankings'        => array_values($validated['rankings']),
            'jurisdiction_id' => (string) $race->jurisdiction_id,
        ]);

        // The out-of-band receipt (BallotBoxDelegate contract): read once,
        // flash once. After this redirect renders, the hash is gone — no
        // GET endpoint returns it, ballots carry no user link, envelopes
        // carry no hash.
        $receipt = $this->receipts->take();

        $redirect = redirect("/elections/{$election->id}/ranked-ballot")
            ->with('status', 'Ballot committed — your participation is recorded; your choices are sealed.');

        if ($receipt !== null) {
            $redirect->with('receipt_hash', $receipt->ballotHash)
                ->with('receipt_salt', $receipt->salt);
        }

        return $redirect;
    }

    /**
     * F-IND-008 — referendum ballots. The commitment scheme accepts only
     * ranked ballots in Phase B (BallotBox::write pins it); the route
     * ships so the contract is visible, and the refusal carries its
     * citation rather than a 404.
     */
    public function storeReferendum(Request $request, Election $election, ElectionRace $race): never
    {
        throw new ConstitutionalViolation(
            'Referendum ballots (F-IND-008) arrive with Phase C — no referendum question is attached to this race.',
            'Art. II §6'
        );
    }

    /**
     * POST /receipt-check {hash} — public, unauthenticated-OK (§D): the
     * lookup is anonymized by design; anyone may check any hash against
     * ballots.ballot_hash. Returns found/cast_bucket/counted only — a
     * ballot row carries nothing else linkable.
     */
    public function receiptCheck(Request $request): JsonResponse
    {
        $raw = (string) $request->input('hash', '');
        $hash = strtolower(preg_replace('/\s+/', '', $raw) ?? '');

        if (! preg_match('/^[0-9a-f]{64}$/', $hash)) {
            return response()->json([
                'found'   => false,
                'invalid' => true,
                'message' => 'Not a receipt hash — receipts are 64 hexadecimal characters.',
            ]);
        }

        $ballot = Ballot::query()->where('ballot_hash', $hash)->first(['cast_bucket', 'counted']);

        if ($ballot === null) {
            return response()->json([
                'found'   => false,
                'invalid' => false,
                'message' => 'Not found — check for typos; hashes are 64 characters.',
            ]);
        }

        return response()->json([
            'found'       => true,
            'cast_bucket' => $ballot->cast_bucket?->toIso8601String(),
            'counted'     => (bool) $ballot->counted,
        ]);
    }

    // -------------------------------------------------------------------------
    // Props builders
    // -------------------------------------------------------------------------

    /**
     * The finalist roster — top X in frozen cutoff order (frozen standings
     * rollup, registration seniority on ties — the same ordering the
     * cutoff applied).
     *
     * @return list<array{candidacy_id: string, name: string, profile_href: string}>
     */
    private function finalists(ElectionRace $race): array
    {
        return DB::table('candidacies as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('approval_standings as s', function ($join) {
                $join->on('s.candidacy_id', '=', 'c.id')->where('s.is_frozen', true);
            })
            ->where('c.race_id', (string) $race->id)
            ->where('c.status', Candidacy::STATUS_FINALIST)
            ->whereNull('c.deleted_at')
            ->orderByRaw('s.approvals_count DESC NULLS LAST')
            ->orderBy('c.validated_at')
            ->orderBy('c.id')
            ->get(['c.id', 'u.name', 'u.display_name'])
            ->map(fn ($row) => [
                'candidacy_id' => (string) $row->id,
                'name'         => $row->display_name ?: $row->name,
                'profile_href' => '/candidates/' . $row->id,
            ])
            ->all();
    }

    /** Count only — the list is search-driven, never enumerated (§B.5). */
    private function writeInsAvailable(ElectionRace $race): int
    {
        return Candidacy::query()
            ->where('race_id', (string) $race->id)
            ->whereIn('status', BallotSubmission::RANKABLE_STATUSES)
            ->where('status', '!=', Candidacy::STATUS_FINALIST)
            ->count();
    }

    /**
     * Server-side write-in search over the race's rankable non-finalists.
     *
     * @return list<array{candidacy_id: string, name: string, status: string}>
     */
    private function searchWriteIns(ElectionRace $race, string $q): array
    {
        $q = trim($q);

        if (mb_strlen($q) < 2) {
            return [];
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);

        return DB::table('candidacies as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.race_id', (string) $race->id)
            ->whereIn('c.status', BallotSubmission::RANKABLE_STATUSES)
            ->where('c.status', '!=', Candidacy::STATUS_FINALIST)
            ->whereNull('c.deleted_at')
            ->where(function ($query) use ($escaped) {
                $query->where('u.name', 'ilike', "%{$escaped}%")
                    ->orWhere('u.display_name', 'ilike', "%{$escaped}%");
            })
            ->orderBy('u.name')
            ->limit(10)
            ->get(['c.id', 'c.status', 'u.name', 'u.display_name'])
            ->map(fn ($row) => [
                'candidacy_id' => (string) $row->id,
                'name'         => $row->display_name ?: $row->name,
                'status'       => $row->status,
            ])
            ->all();
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
}
