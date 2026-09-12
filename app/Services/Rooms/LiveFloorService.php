<?php

namespace App\Services\Rooms;

use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Validation\ValidationException;

/**
 * The live-room FLOOR — ephemeral recognition state (who holds the floor, the
 * hands-raised queue, the speaking clock). Slice 6, step 4.
 *
 * This is LIVE CHOREOGRAPHY, not a durable governance record. The durable acts
 * of a room — the vote, sealed testimony — file through the engine and persist;
 * the floor is transient and lives in the cache, keyed per room, with a
 * session-length TTL. So it needs NO schema (no migration slot — the desk's
 * queue is 1→15→2): a committee_meeting/session carries no floor column, and it
 * should not — a concluded proceeding's durable record is its minutes + the
 * sealed record, never who was mid-sentence.
 *
 * Poll-first (store contract): the controller READS this into the room's props
 * and the useLiveRoom poll refreshes it — there is no push. Chair recognition
 * writes it (a human act); a raise-hand is any resident's.
 */
class LiveFloorService
{
    /** A live session's lifespan — the floor is meaningless once the room adjourns. */
    private const TTL_SECONDS = 60 * 60 * 6;

    public function key(string $entityType, string $entityId): string
    {
        return "live_floor:{$entityType}:{$entityId}";
    }

    /** @return array{floorHolder: ?string, queue: list<array{handle:string, reason:?string}>, speaking: ?array{seconds:int}} */
    public function state(string $key): array
    {
        return array_merge(['floorHolder' => null, 'queue' => [], 'speaking' => null, 'activeWitness' => null], Cache::get($key, []));
    }

    /** A resident raises a hand — idempotent (already-queued is a no-op). */
    public function raiseHand(string $key, string $handle, ?string $reason = null): array
    {
        return $this->mutate($key, function (array $s) use ($handle, $reason) {
            foreach ($s['queue'] as $q) {
                if ($q['handle'] === $handle) return $s;
            }
            // An operational payload bound, not a limit on institutional membership.
            if (count($s['queue']) >= 200) {
                throw ValidationException::withMessages(['floor' => 'The speaking queue is full. Please try again after a speaker is recognized.']);
            }
            $s['queue'][] = ['handle' => $handle, 'reason' => $reason];
            return $s;
        });
    }

    /** A resident lowers their own hand (leaves the queue). */
    public function lowerHand(string $key, string $handle): array
    {
        return $this->mutate($key, function (array $s) use ($handle) {
            $s['queue'] = array_values(array_filter($s['queue'], fn ($q) => $q['handle'] !== $handle));
            return $s;
        });
    }

    /**
     * The chair recognizes a speaker → they hold the floor; the speaking clock
     * resets. With no handle, the next hand in the queue is recognized (FIFO).
     * The recognized handle leaves the queue.
     */
    public function recognize(string $key, ?string $handle = null, int $speakingSeconds = 120): array
    {
        return $this->mutate($key, fn (array $s) => $this->recognition($s, $handle, $speakingSeconds, false));
    }

    /** Court room positioning only; this does not file or seal testimony. */
    public function recognizeWitness(string $key, string $handle): array
    {
        return $this->mutate($key, fn (array $s) => $this->recognition($s, $handle, 0, true));
    }

    /** The floor is yielded (the speaker finished or the chair moved on). */
    public function yieldFloor(string $key): array
    {
        return $this->mutate($key, function (array $s) {
            $s['floorHolder'] = null;
            $s['speaking'] = null;
            $s['activeWitness'] = null;
            return $s;
        });
    }

    /** Clear the whole floor (on adjournment — the live state is discarded). */
    public function clear(string $key): void
    {
        $this->locked($key, fn () => Cache::forget($key));
    }

    private function recognition(array $s, ?string $handle, int $seconds, bool $witness): array
    {
        $handle ??= $s['queue'][0]['handle'] ?? null;
        if ($handle === null) return $s;
        if (! in_array($handle, array_column($s['queue'], 'handle'), true)) {
            throw ValidationException::withMessages(['floor' => 'That person is no longer waiting in this room. Refresh the queue and try again.']);
        }
        $s['queue'] = array_values(array_filter($s['queue'], fn ($q) => $q['handle'] !== $handle));
        $s['floorHolder'] = $handle;
        $s['speaking'] = $seconds > 0 ? ['seconds' => $seconds] : null;
        // A witness remains on the stand while counsel or a judge speaks.
        // The presider dismisses that position by yielding the floor.
        if ($witness) $s['activeWitness'] = $handle;
        return $s;
    }

    private function mutate(string $key, callable $change): array
    {
        return $this->locked($key, function () use ($key, $change) {
            $state = $change($this->state($key));
            Cache::put($key, $state, self::TTL_SECONDS);
            return $state;
        });
    }

    private function locked(string $key, callable $change): mixed
    {
        try {
            // One shared-cache lock per room: concurrent hands cannot overwrite each other.
            return Cache::lock($key.':lock', 5)->block(2, $change);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['floor' => 'The room is updating its speaking queue. Please try again.']);
        }
    }
}
