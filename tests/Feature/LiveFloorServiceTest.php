<?php

namespace Tests\Feature;

use App\Services\Rooms\LiveFloorService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Slice 6 — the ephemeral live FLOOR (recognition state). The floor is live
 * choreography, not a durable record, so it lives in the cache with no schema.
 * These pins fix its behaviour: a raise-hand is idempotent, recognition is FIFO
 * and moves the recognized handle out of the queue onto the floor, recognizing
 * an empty queue is a no-op, and yielding clears the floor.
 */
class LiveFloorServiceTest extends TestCase
{
    private function floor(): LiveFloorService
    {
        return app(LiveFloorService::class);
    }

    private function key(): string
    {
        return 'live_floor:test:'.Str::uuid();
    }

    public function test_a_raise_hand_is_idempotent(): void
    {
        $floor = $this->floor();
        $key = $this->key();

        $floor->raiseHand($key, '@u-alice', 'To speak');
        $floor->raiseHand($key, '@u-alice', 'again'); // same handle — no duplicate
        $state = $floor->raiseHand($key, '@u-bob');

        $this->assertCount(2, $state['queue']);
        $this->assertSame('@u-alice', $state['queue'][0]['handle']);
        $this->assertSame('@u-bob', $state['queue'][1]['handle']);
    }

    public function test_recognition_is_fifo_and_moves_the_handle_to_the_floor(): void
    {
        $floor = $this->floor();
        $key = $this->key();

        $floor->raiseHand($key, '@u-alice');
        $floor->raiseHand($key, '@u-bob');
        $state = $floor->recognize($key); // no handle → the next in the queue (alice)

        $this->assertSame('@u-alice', $state['floorHolder'], 'the first hand raised holds the floor');
        $this->assertCount(1, $state['queue'], 'the recognized handle leaves the queue');
        $this->assertSame('@u-bob', $state['queue'][0]['handle']);
        $this->assertNotNull($state['speaking'], 'the speaking clock resets on recognition');
    }

    public function test_a_named_recognition_pulls_that_handle_regardless_of_order(): void
    {
        $floor = $this->floor();
        $key = $this->key();

        $floor->raiseHand($key, '@u-alice');
        $floor->raiseHand($key, '@u-bob');
        $state = $floor->recognize($key, '@u-bob'); // the chair names bob

        $this->assertSame('@u-bob', $state['floorHolder']);
        $this->assertSame(['@u-alice'], array_column($state['queue'], 'handle'));
    }

    public function test_recognizing_an_empty_queue_is_a_no_op(): void
    {
        $floor = $this->floor();
        $key = $this->key();

        $state = $floor->recognize($key);

        $this->assertNull($state['floorHolder']);
        $this->assertSame([], $state['queue']);
    }

    public function test_yielding_clears_the_floor(): void
    {
        $floor = $this->floor();
        $key = $this->key();

        $floor->raiseHand($key, '@u-alice');
        $floor->recognize($key);
        $state = $floor->yieldFloor($key);

        $this->assertNull($state['floorHolder']);
        $this->assertNull($state['speaking']);
    }
}
