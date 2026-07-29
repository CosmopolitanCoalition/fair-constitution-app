<?php

namespace App\Services\Rooms;

use Illuminate\Support\Facades\Cache;

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
        return Cache::get($key, ['floorHolder' => null, 'queue' => [], 'speaking' => null]);
    }

    /** A resident raises a hand — idempotent (already-queued is a no-op). */
    public function raiseHand(string $key, string $handle, ?string $reason = null): array
    {
        $s = $this->state($key);
        foreach ($s['queue'] as $q) {
            if ($q['handle'] === $handle) {
                return $s;
            }
        }
        $s['queue'][] = ['handle' => $handle, 'reason' => $reason];

        return $this->put($key, $s);
    }

    /** A resident lowers their own hand (leaves the queue). */
    public function lowerHand(string $key, string $handle): array
    {
        $s = $this->state($key);
        $s['queue'] = array_values(array_filter($s['queue'], fn ($q) => $q['handle'] !== $handle));

        return $this->put($key, $s);
    }

    /**
     * The chair recognizes a speaker → they hold the floor; the speaking clock
     * resets. With no handle, the next hand in the queue is recognized (FIFO).
     * The recognized handle leaves the queue.
     */
    public function recognize(string $key, ?string $handle = null, int $speakingSeconds = 120): array
    {
        $s = $this->state($key);
        if ($handle === null) {
            $handle = $s['queue'][0]['handle'] ?? null;
            if ($handle === null) {
                return $s; // no hands raised — nothing to recognize
            }
        }
        $s['queue'] = array_values(array_filter($s['queue'], fn ($q) => $q['handle'] !== $handle));
        $s['floorHolder'] = $handle;
        $s['speaking'] = ['seconds' => $speakingSeconds];

        return $this->put($key, $s);
    }

    /** The floor is yielded (the speaker finished or the chair moved on). */
    public function yieldFloor(string $key): array
    {
        $s = $this->state($key);
        $s['floorHolder'] = null;
        $s['speaking'] = null;

        return $this->put($key, $s);
    }

    /** Clear the whole floor (on adjournment — the live state is discarded). */
    public function clear(string $key): void
    {
        Cache::forget($key);
    }

    private function put(string $key, array $s): array
    {
        Cache::put($key, $s, self::TTL_SECONDS);

        return $s;
    }
}
