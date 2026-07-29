<?php

namespace App\Services\Dev;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * D5 — scenario presets: the mockups' named situations mapped to the REAL
 * demo seeders. (Ruling 2026-07-28 §10 item 10: BUILD; desk green-lit the
 * full-async shape 2026-07-28. Design note:
 * docs/plans/playtest/DEMO_MODE_D4_D5_NOTES.md.)
 *
 * A preset is (command, args, precondition) and NOTHING more. No new
 * capability, no bypass: the buttons queue the exact artisan commands the
 * terminal runs today, and every one of those commands carries
 * GuardsSyntheticData server-side. UI↔CLI parity holds by construction —
 * the CLI half of every preset EXISTS FIRST (it IS the seeder).
 *
 * THE PROBE IS ADVISORY, THE SEEDER IS THE TRUTH. Each precondition here
 * is a cheap read mirroring the seeder's own refusal checks, so the
 * flyout can gray a button and say WHY ("needs a seated legislature —
 * run the election preset first") — the dependency ladder teaching
 * itself. The seeder still runs its own checks and its refusal text is
 * what the run tail shows; the probe never overrides it.
 *
 * Run state lives in CACHE, deliberately: a preset run is ephemeral
 * dev-ops state. The REAL records — and the audit chain — are written by
 * the seeder itself through the engine, exactly as a terminal run writes
 * them. One run at a time (the lock), because the seeders share worlds.
 */
class ScenarioPresetService
{
    public const LOCK_KEY = 'dev:scenario:running';

    /** Lock TTL — outlives the child's own 3900s cap, so a hard-killed
     *  worker cannot orphan the lock forever. */
    public const LOCK_TTL_SECONDS = 4200;

    public const STATE_TTL = 86_400; // one day of tail history

    private const SM = 'smr-1-san-marino';

    /**
     * The preset table. `lights` names the mockup scenario-vocabulary flags
     * (demo-state.js) the preset makes true — the spec-side join.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function presets(): array
    {
        return [
            'election' => [
                'label' => 'Run a live election (San Marino, compressed phases)',
                'command' => 'elections:demo',
                'args' => ['slug' => self::SM],
                'lights' => ['election:approval', 'election:ranked', 'election:certifying', 'bicameral'],
                'detail' => 'Approval → finalists → ranked → certified, ~2 minutes per phase, real ballots, real count. San Marino seats both chambers.',
            ],
            'election-instant' => [
                'label' => 'Land a finished election (certified + successor open)',
                'command' => 'elections:demo',
                'args' => ['slug' => self::SM, '--instant' => true],
                'lights' => ['election:certifying', 'bicameral'],
                'detail' => 'Fires the armed phase timers through the real ClockService — ends certified with the next cycle open.',
            ],
            'lawmaking' => [
                'label' => 'Fill the chamber\'s desk (bills, petitions, statements)',
                'command' => 'institutions:demo-lawmaking',
                'args' => [],
                'lights' => [],
                'detail' => 'Four bills, a petition gathering signatures, public statements — engine refusals reported verbatim.',
            ],
            'executive-orgs' => [
                'label' => 'Stand up the executive + organizations (Phase D)',
                'command' => 'institutions:demo-d',
                'args' => [],
                'lights' => [],
                'detail' => 'Delegated executive, a department with a governor, executive orders, a 100-worker co-determination org, a CGC.',
            ],
            'judiciary' => [
                'label' => 'Seat a court with live cases + challenges (Phase E)',
                'command' => 'institutions:demo-e',
                'args' => [],
                'lights' => ['challenge'],
                'detail' => 'An appointed court, two criminal cases, an advocate, and two Art. IV §5 constitutional challenges.',
            ],
            'economy' => [
                'label' => 'Open the economy (currency, stipend run, marketplace)',
                'command' => 'institutions:demo-treasury',
                'args' => [],
                'lights' => ['marketplace', 'ubiRun'],
                'detail' => 'A currency, wallets, a real stipend run, a marketplace with a settled sale, a labor posting, mutual aid.',
            ],
            'social' => [
                'label' => 'Open the civic commons (square + halls + testimony)',
                'command' => 'social:demo',
                'args' => [],
                'lights' => [],
                'detail' => 'The public square, halls, posts and one sealed testimony on San Marino.',
            ],
            'federation' => [
                'label' => 'Stand up a demo federation peer (sync history + a flipped partition)',
                'command' => 'federation:demo',
                'args' => [],
                'lights' => [],
                'detail' => 'A browsable demo peer with Full-Faith-&-Credit sync history and one authority-flipped '
                    .'partition — the /federation console comes alive. Class-scoped: a demo peers only with demos.',
            ],
            'matrix-commons' => [
                'label' => 'Seed the Matrix commons (topology + testimony + the legitimacy flip)',
                'command' => 'matrix:demo',
                'args' => ['--offline' => true],
                'lights' => [],
                'detail' => 'San Marino\'s civic commons on Matrix — #square + #halls topology, a sealed testimony, '
                    .'and the legitimacy flip. Runs --offline (Plane-A artifacts only) so it never blocks on a homeserver.',
            ],
        ];
    }

    /**
     * The mockup scenario flags NO seeder can light today — rendered as
     * honest absence, never a fake button. (Survey 2026-07-28.)
     *
     * @return array<string, string>
     */
    public static function unbacked(): array
    {
        return [
            'emergency' => 'the emergency-powers engine exists; no seeder puts a live emergency on a world',
            'quorumFails' => 'peg-quorum voting exists; nothing seeds a quorum-failing vote',
            'countbackFailed' => 'vacancy:declare reaches the branch, but exhaustion is not guaranteed',
            'restoration' => 'RestorationService exists; no seeder',
            'unionDrill' => 'UnionService exists; no seeder',
            'liveSession' => 'no mid-session room state exists anywhere',
            'groupForming' => 'informal orgs exist only as a type; no formation seeder',
            'tradeTalk' => 'no trade-talk artifacts exist',
        ];
    }

    /**
     * Advisory precondition per preset: [ok, whyNot]. Cheap reads only —
     * the seeder's own refusal is the final word.
     *
     * @return array{0: bool, 1: ?string}
     */
    public function probe(string $preset): array
    {
        $sm = fn () => DB::table('jurisdictions')->where('slug', self::SM)->whereNull('deleted_at')->value('id');

        return match ($preset) {
            'election', 'election-instant' => ($sm)() !== null
                ? [true, null]
                : [false, 'San Marino is not on this world — found a world with real geodata first.'],

            'lawmaking' => $this->smServingMembers() >= 3
                ? [true, null]
                : [false, 'Needs at least 3 seated members in San Marino — run the election preset first.'],

            'executive-orgs' => $this->smServingMembers() > 5
                ? [true, null]
                : [false, 'Needs more than 5 seated members in San Marino — run the election preset first.'],

            'judiciary' => $this->smServingMembers() < 5
                ? [false, 'Needs at least 5 seated members in San Marino — run the election preset first.']
                : ($this->activeConfirmations() < 20
                    ? [false, 'Needs at least 20 confirmed residents — the election preset mints its voters.']
                    : [true, null]),

            'economy' => $this->activeConfirmations() >= 2
                ? [true, null]
                : [false, 'Needs at least 2 confirmed residents — run the election preset first.'],

            'social' => ($sm)() !== null
                ? [true, null]
                : [false, 'San Marino is not on this world — found a world with real geodata first.'],

            // federation:demo mints its own demo peer + flip; it needs nothing seeded
            // first. The command self-checks and its --fresh retires prior demo peers.
            'federation' => [true, null],

            // matrix:demo seeds San Marino's commons; without the place there is
            // nothing to seed it onto.
            'matrix-commons' => ($sm)() !== null
                ? [true, null]
                : [false, 'San Marino is not on this world — found a world with real geodata first.'],

            default => [false, 'Unknown preset.'],
        };
    }

    /** Cache-backed run state for one preset (null = never run). */
    public function runState(string $preset): ?array
    {
        return Cache::get("dev:scenario:{$preset}");
    }

    public function putRunState(string $preset, array $state): void
    {
        Cache::put("dev:scenario:{$preset}", $state, self::STATE_TTL);
    }

    /** True while ANY preset run holds the one-at-a-time lock. */
    public function running(): ?string
    {
        return Cache::get(self::LOCK_KEY);
    }

    private function smServingMembers(): int
    {
        return (int) DB::table('legislature_members as m')
            ->join('legislatures as l', 'l.id', '=', 'm.legislature_id')
            ->join('jurisdictions as j', 'j.id', '=', 'l.jurisdiction_id')
            ->where('j.slug', self::SM)
            ->whereIn('m.status', ['elected', 'seated'])
            ->whereNull('m.deleted_at')
            ->count();
    }

    private function activeConfirmations(): int
    {
        return (int) DB::table('residency_confirmations')->where('is_active', true)->count();
    }
}
