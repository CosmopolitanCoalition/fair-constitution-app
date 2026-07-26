<?php

namespace App\Http\Controllers\Dev;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\ChamberVote;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * P4 — cast a chamber, for playtesting (DEV_TIME_AND_ROLE_CONTROLS.md §P4).
 *
 * Walking "a bill becomes law" by hand costs 58 persona switches. That is a
 * chore, not a playtest, and it is why the single most important journey in
 * the app has only ever been exercised by scripts. This gives that journey a
 * door.
 *
 * ══ THE RULE THAT IS THE WHOLE DESIGN ══
 *
 *   IT SUPPLIES BALLOTS. IT DOES NOT SUPPLY OUTCOMES.
 *
 * Every ballot is filed as a real seated member through the real
 * ConstitutionalEngine (F-LEG-004) — the same path `demo-d` and `demo-e`
 * already use. Quorum, supermajority and bicameral dual agreement are
 * computed by the engine exactly as they always are, and this controller
 * never touches a tally, an outcome, or a vote's status.
 *
 * **If a vote fails, it fails.** A dev control that can make an act pass is
 * worthless as a test — it would prove only that the control works — and it
 * is dangerous as a precedent, because the next person to need "just this
 * once" would find the door already open. `ChamberCastIsBallotsOnlyTest`
 * pins that a deliberately-insufficient cast does NOT carry.
 *
 * ══ WHY IT IS AUDIT-MARKED ══
 *
 * The audit log is hash-chained and is the app's record of truth. A
 * playtested timeline that reads as a lived one would be the worst outcome
 * here — worse than the control not existing — so every invocation appends
 * `dev/chamber.cast_bloc` BEFORE the ballots, recording that what follows was
 * bloc-cast by a developer. The individual F-LEG-004 filings still chain
 * normally underneath, so the detail is preserved and the context is honest.
 *
 * Gating is DevTimeControlsEnabled: local + impersonation + sandbox + its own
 * `cga.dev_time` key, and refused outright on any federated or peered node.
 */
class ChamberCastController extends Controller
{
    public function __construct(
        private ConstitutionalEngine $engine,
        private AuditService $audit,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vote_id' => ['required', 'uuid'],
            'yes'     => ['nullable', 'integer', 'min:0', 'max:5000'],
            'no'      => ['nullable', 'integer', 'min:0', 'max:5000'],
            'abstain' => ['nullable', 'integer', 'min:0', 'max:5000'],
            // Optional: restrict the bloc to one chamber, so a playtester can
            // watch dual agreement fail on purpose (type_a agrees, type_b does
            // not). That is a legitimate thing to want to see.
            'lane'    => ['nullable', Rule::in(['type_a', 'type_b'])],
        ]);

        $vote = ChamberVote::query()->find($data['vote_id']);

        if ($vote === null) {
            return response()->json(['error' => 'Unknown vote.'], 404);
        }

        if ($vote->status !== ChamberVote::STATUS_OPEN) {
            return response()->json([
                'error' => "That vote is {$vote->status}, not open — ballots can only be cast while a vote is open.",
            ], 409);
        }

        $wanted = [
            'yes'     => (int) ($data['yes'] ?? 0),
            'no'      => (int) ($data['no'] ?? 0),
            'abstain' => (int) ($data['abstain'] ?? 0),
        ];

        if (array_sum($wanted) === 0) {
            return response()->json(['error' => 'Nothing to cast — give at least one of yes/no/abstain.'], 422);
        }

        $members = $this->eligibleMembers($vote, $data['lane'] ?? null);

        if ($members->isEmpty()) {
            return response()->json(['error' => 'No seated members are eligible to cast on that vote.'], 409);
        }

        // The dev marker goes down FIRST, so the chain records the context
        // before the ballots it explains. If the run dies halfway, the log
        // still says a developer was bloc-casting.
        $this->audit->append(
            module: 'dev',
            event: 'chamber.cast_bloc',
            payload: [
                'vote_id'   => (string) $vote->id,
                'vote_type' => (string) $vote->vote_type,
                'requested' => $wanted,
                'lane'      => $data['lane'] ?? 'all',
                'note'      => 'Bloc-cast through the dev playtest control. Ballots only — the engine computed the outcome.',
            ],
            ref: 'DEV-CHAMBER-CAST',
            actorId: $this->uuidActor($request),
            jurisdictionId: $vote->jurisdiction_id ? (string) $vote->jurisdiction_id : null,
        );

        $cast = ['yes' => 0, 'no' => 0, 'abstain' => 0];
        $refusals = [];
        $i = 0;

        foreach (['yes', 'no', 'abstain'] as $value) {
            for ($n = 0; $n < $wanted[$value]; $n++) {
                // The engine closes a vote the moment it can be decided; once
                // closed, stop rather than push ballots at a settled question.
                if (ChamberVote::query()->whereKey($vote->id)->value('status') !== ChamberVote::STATUS_OPEN) {
                    break 2;
                }

                if (! isset($members[$i])) {
                    break 2;
                }

                $member = $members[$i];
                $i++;

                try {
                    $this->engine->file('F-LEG-004', User::query()->find($member->user_id), [
                        'vote_id' => (string) $vote->id,
                        'value'   => $value,
                    ]);
                    $cast[$value]++;
                } catch (Throwable $e) {
                    // A refusal is information, not an error to swallow: a
                    // member may already have voted, or be the Speaker, or be
                    // barred from this vote. Report it and keep going.
                    $refusals[] = trim(explode("\n", $e->getMessage())[0]);
                }
            }
        }

        $vote->refresh();

        return response()->json([
            'vote_id'      => (string) $vote->id,
            'vote_type'    => (string) $vote->vote_type,
            'requested'    => $wanted,
            'cast'         => $cast,
            'eligible'     => $members->count(),
            // Whatever the ENGINE decided. Not influenced by this controller.
            'outcome'      => $vote->outcome,
            'status'       => $vote->status,
            'serving'      => (int) $vote->serving_snapshot,
            'bicameral'    => (bool) $vote->bicameral,
            'tallies'      => $this->talliesByLane((string) $vote->id),
            'refusals'     => array_values(array_unique($refusals)),
            'ballots_only' => 'The engine computed this outcome. This control supplied ballots and nothing else.',
        ]);
    }

    /**
     * Seated members who have not already cast on this vote, ordered
     * deterministically so a repeated call behaves the same way twice.
     *
     * @return \Illuminate\Support\Collection<int, LegislatureMember>
     */
    private function eligibleMembers(ChamberVote $vote, ?string $lane)
    {
        $already = DB::table('vote_casts')->where('vote_id', $vote->id)->pluck('member_id')->all();

        return LegislatureMember::query()
            ->where('legislature_id', $vote->legislature_id)
            // CURRENT_STATUSES, not merely un-vacated: an election cycle
            // term_ends the previous cohort and leaves those rows in place.
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->whereNull('deleted_at')
            ->whereNull('vacated_at')
            ->whereNotNull('user_id')
            ->when($already !== [], fn ($q) => $q->whereNotIn('id', $already))
            ->when($lane !== null, fn ($q) => $q->where('seat_type', $lane === 'type_a' ? 'a' : 'b'))
            ->orderBy('seat_type')
            ->orderBy('seat_no')
            ->orderBy('id')
            ->get()
            ->values();
    }

    /** @return array<string, array<string, int>> */
    private function talliesByLane(string $voteId): array
    {
        $out = [];

        foreach (DB::select(
            'select lane, value, count(*) as c from vote_casts where vote_id = ? group by 1, 2 order by 1, 2',
            [$voteId]
        ) as $row) {
            $out[$row->lane][$row->value] = (int) $row->c;
        }

        return $out;
    }

    private function uuidActor(Request $request): ?string
    {
        $key = $request->user()?->getKey();

        return $key !== null && \Illuminate\Support\Str::isUuid((string) $key) ? (string) $key : null;
    }
}
