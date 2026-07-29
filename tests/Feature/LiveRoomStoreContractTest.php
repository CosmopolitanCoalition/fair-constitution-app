<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * CONTRACT PIN — the live-room freshness store (Slice 6; contract at
 * docs/plans/scaling/LIVE_ROOM_STORE_CONTRACT.md, desk-ruled 2026-07-29:
 * Q1 = A uniform-5s + per-group-map affordance; Q2 = B status-gated with the
 * final-snapshot-before-stop pin).
 *
 * The store is a client composable (no Pinia; no JS test runner exists in this
 * repo), so — exactly like BallotSecrecyTest's rogue-writer grep — this pins the
 * contract by SCANNING THE SOURCE: the §4 guarantees must be present, the Q1/Q2
 * knobs must be implemented, and the store must remain READ-ONLY / decrypt-free.
 * A behavioural JS pin awaits a JS test runner (a fleet-level decision — NOT
 * unilaterally adding vitest to the shared package.json).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class LiveRoomStoreContractTest extends TestCase
{
    private function source(): string
    {
        // The store is split: the Vue composable + its pure policy (extracted so
        // the Q1/Q2 logic is behaviourally pinned under node — see
        // tests/js/liveRoomPolicy.test.mjs). The contract spans both.
        $store = base_path('resources/js/composables/useLiveRoom.js');
        $policy = base_path('resources/js/composables/liveRoomPolicy.js');
        $this->assertFileExists($store, 'the live-room store composable must exist');
        $this->assertFileExists($policy, 'the live-room pure policy must exist');

        return (string) file_get_contents($store)."\n".(string) file_get_contents($policy);
    }

    public function test_the_five_guarantees_are_present(): void
    {
        $src = $this->source();

        // 1. Hidden-tab pause.
        $this->assertStringContainsString('document.hidden', $src, 'guarantee 1: pause polling on a hidden tab');
        // 2. In-flight-write guard.
        $this->assertStringContainsString('busy()', $src, 'guarantee 2: skip a tick while a write is in flight');
        // 3. Snapshot-merge only (scroll/state/focus/AV preserved across a refresh).
        $this->assertStringContainsString('preserveScroll: true', $src, 'guarantee 3: preserve scroll across a merge');
        $this->assertStringContainsString('preserveState: true', $src, 'guarantee 3: preserve state across a merge');
        // (visibility regain re-arms — MatrixCommons parity)
        $this->assertStringContainsString('visibilitychange', $src, 'visibility regain fires a refresh and re-arms');
        // 5. Terminal stop exists (adjourned).
        $this->assertStringContainsString("'adjourned'", $src, 'guarantee 5: a concluded room stops');
    }

    public function test_the_store_is_read_only_and_decrypt_free(): void
    {
        $src = $this->source();

        // 4. READ-ONLY: the store may only reload a snapshot. It must never issue
        // an Inertia write, and it must never touch a ballot decrypt (the secrecy
        // boundary a live election-forum room sits near).
        // Router HTTP writes only — a bare `.delete(` would collide with the
        // internal Map.delete() used to clear timers (not an Inertia write).
        foreach (['router.post(', 'router.put(', 'router.patch(', 'router.delete(', '.post(', '.put('] as $write) {
            $this->assertStringNotContainsString($write, $src, "the store must never write ({$write})");
        }
        // A decrypt CALL (not the word in prose) — the store never reads ciphertext.
        foreach (['.decrypt(', 'decryptForCount'] as $decrypt) {
            $this->assertStringNotContainsString($decrypt, $src, "the store must never decrypt a ballot ({$decrypt})");
        }

        // The only transport is a partial reload.
        $this->assertStringContainsString('router.reload', $src, 'the merge is an Inertia partial reload');
    }

    public function test_q1_the_per_group_cadence_map_affordance_exists(): void
    {
        $src = $this->source();

        // Uniform 5s is the shipped default; the map is lane 2's load valve.
        $this->assertStringContainsString('normalizeChannels', $src, 'Q1: cadence normalises into channels');
        $this->assertMatchesRegularExpression(
            '/typeof\s+cadenceMs\s*===\s*[\'"]object[\'"]/',
            $src,
            'Q1: cadenceMs accepts a per-group map (object), not only a number',
        );
        $this->assertStringContainsString('5000', $src, 'Q1: the proven 5s default');
    }

    public function test_q2_status_gating_and_the_heartbeat_exist(): void
    {
        $src = $this->source();

        $this->assertStringContainsString("'scheduled'", $src, 'Q2: a scheduled room heartbeats');
        $this->assertStringContainsString('HEARTBEAT_MS', $src, 'Q2: a slow heartbeat constant while scheduled');
        $this->assertStringContainsString('30000', $src, 'Q2: ~30s heartbeat while scheduled');
        $this->assertStringContainsString('refresh', $src, 'Q2: refresh() is the manual check on an adjourned room');
    }

    public function test_q2_pin_the_stop_on_adjourned_never_precedes_the_closing_merge(): void
    {
        $src = $this->source();

        // The desk pin: the stop on `adjourned` must be evaluated ONLY AFTER the
        // merge of the snapshot that announced it. Structurally, the stop decision
        // (nextInterval → stopChannel when it returns null) must live INSIDE the
        // post-merge callback passed to merge(channel.keys, () => { ... }) — never
        // before the reload is issued.
        $this->assertMatchesRegularExpression(
            '/merge\(\s*channel\.keys\s*,[\s\S]{0,400}?nextInterval\([\s\S]{0,180}?stopChannel/',
            $src,
            'Q2 pin: the stop is decided POST-merge (inside the merge callback), so the closing snapshot lands first',
        );
    }
}
