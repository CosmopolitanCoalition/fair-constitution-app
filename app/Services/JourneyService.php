<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\JourneyProgress;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The journeys engine (mockups-v3-wiring Phase 3c; design contract
 * mockups/v3/journeys/journey.html + config/cga/journeys.php).
 *
 * Two stores, two postures:
 *  - journey_progress — NODE-LOCAL mutable lesson state (steps ticked off).
 *  - achievements    — the append-only earned ledger, sealed to the audit
 *    chain. Completion is a LEDGER EVENT: once a journey completes, its
 *    steps freeze and the medal is permanent.
 *
 * Soft-gate rule: journeys nudge, they NEVER block — nothing here grants
 * or denies any capability. A medal never changes a vote, a seat, or what
 * you are allowed to do.
 */
class JourneyService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /** The journey_progress row for a user+journey, or null. */
    public function progress(User $user, string $journeyId): ?JourneyProgress
    {
        return JourneyProgress::query()
            ->where('user_id', (string) $user->id)
            ->where('journey_id', $journeyId)
            ->first();
    }

    /**
     * Mark one 0-based step done. Validates the journey is live and the
     * step in range, unions the step into steps_done, and — when the whole
     * arc is done and not yet completed — sets completed_at AND appends the
     * achievement row idempotently, all in one transaction.
     */
    public function markStep(User $user, string $journeyId, int $step): JourneyProgress
    {
        $journey = $this->liveJourneyOrFail($journeyId);

        if ($step < 0 || $step >= count($journey['steps'])) {
            throw ValidationException::withMessages([
                'step' => "Journey [{$journeyId}] has no step {$step}.",
            ]);
        }

        return DB::transaction(function () use ($user, $journeyId, $step, $journey) {
            $progress = $this->lockedProgress($user, $journeyId);

            $done = array_values(array_unique(array_map('intval', $progress->steps_done ?? [])));
            if (! in_array($step, $done, true)) {
                $done[] = $step;
                sort($done);
                $progress->steps_done = $done;
            }

            if ($progress->completed_at === null && count($done) === count($journey['steps'])) {
                $progress->completed_at = now();
                $this->recordAchievement($user, $journeyId, $journey['title']);
            }

            $progress->save();

            return $progress;
        });
    }

    /**
     * Un-mark a step — allowed only while the journey is NOT completed.
     * Completion is a ledger event, never undone; after it, steps stay
     * frozen (422 on any attempt).
     */
    public function unmarkStep(User $user, string $journeyId, int $step): ?JourneyProgress
    {
        $this->liveJourneyOrFail($journeyId);

        return DB::transaction(function () use ($user, $journeyId, $step) {
            $progress = $this->progress($user, $journeyId);

            if ($progress === null) {
                return null; // nothing marked — no-op
            }

            if ($progress->completed_at !== null) {
                throw ValidationException::withMessages([
                    'step' => 'This journey is complete — its steps are frozen on your record.',
                ]);
            }

            $progress->steps_done = array_values(array_filter(
                array_map('intval', $progress->steps_done ?? []),
                fn (int $done) => $done !== $step,
            ));
            $progress->save();

            return $progress;
        });
    }

    /** Every medal the user has earned, newest first (plain arrays). */
    public function achievementsFor(User $user): array
    {
        return Achievement::query()
            ->where('user_id', (string) $user->id)
            ->orderByDesc('earned_at')
            ->get()
            ->map(fn (Achievement $achievement) => [
                'id'         => (string) $achievement->id,
                'journey_id' => $achievement->journey_id,
                'title'      => $achievement->title,
                'earned_at'  => $achievement->earned_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    // ─────────────────────────────────────────────────────────── internals

    /** @return array{title: string, steps: list<string>, status: string, cls: string} */
    private function liveJourneyOrFail(string $journeyId): array
    {
        $journey = config("cga.journeys.{$journeyId}");

        if (! is_array($journey)) {
            throw ValidationException::withMessages([
                'journey' => "Unknown journey [{$journeyId}].",
            ]);
        }

        if (($journey['status'] ?? null) !== 'live') {
            throw ValidationException::withMessages([
                'journey' => "Journey [{$journeyId}] is not live yet — planned journeys cannot be marked.",
            ]);
        }

        return $journey;
    }

    /** The progress row, created if missing, locked for the transaction. */
    private function lockedProgress(User $user, string $journeyId): JourneyProgress
    {
        $progress = JourneyProgress::query()
            ->where('user_id', (string) $user->id)
            ->where('journey_id', $journeyId)
            ->lockForUpdate()
            ->first();

        return $progress ?? new JourneyProgress([
            'user_id'    => (string) $user->id,
            'journey_id' => $journeyId,
            'steps_done' => [],
        ]);
    }

    /**
     * Append the medal to the earned ledger, idempotently, sealed to the
     * audit chain. `insertOrIgnore` (ON CONFLICT DO NOTHING) is the
     * unique-violation catch: the partial-unique (user_id, journey_id)
     * index guarantees at most one row per person per journey, and a
     * concurrent duplicate simply inserts nothing — the transaction is
     * never aborted mid-flight.
     */
    private function recordAchievement(User $user, string $journeyId, string $title): void
    {
        $exists = Achievement::query()
            ->where('user_id', (string) $user->id)
            ->where('journey_id', $journeyId)
            ->exists();

        if ($exists) {
            return; // already on the ledger — completion re-runs are no-ops
        }

        // Seal the earn into the hash chain in the same transaction (the
        // public_records posture: no row without its chain entry).
        $entry = $this->audit->append(
            module: 'journeys',
            event: 'achievement/earned',
            payload: ['journey_id' => $journeyId, 'title' => $title],
            ref: null,
            actorId: (string) $user->id,
        );

        DB::table('achievements')->insertOrIgnore([
            'user_id'    => (string) $user->id,
            'journey_id' => $journeyId,
            'title'      => $title,
            'audit_seq'  => $entry->seq,
            'earned_at'  => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
