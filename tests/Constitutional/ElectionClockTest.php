<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Handlers\ElectionSchedulingOrder;
use App\Jobs\Clocks\AdvanceElectionPhaseJob;
use App\Jobs\Clocks\FinalistCutoffJob;
use App\Jobs\Clocks\ScheduleGeneralElectionJob;
use App\Jobs\Clocks\SpecialElectionBackstopJob;
use App\Models\Election;
use App\Services\ClockService;
use App\Services\ElectionLifecycleService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * CONSTITUTIONAL PIN — elections cannot be skipped or delayed by
 * officials (Art. II §2/§5; design §B.1 "hardened no-skip"). Replaces the
 * Phase B placeholder `test_elections_cannot_be_skipped_or_delayed_by_
 * officials`, consolidating the clock pins:
 *
 *  1. ELECTIONS FIRE FROM CLOCKS — the registry handler map routes CLK-01
 *     → ScheduleGeneralElectionJob (system actor), CLK-18 →
 *     FinalistCutoffJob, CLK-04 → SpecialElectionBackstopJob, and the
 *     election-phase steps → AdvanceElectionPhaseJob. Removing a route is
 *     removing an election trigger — pinned.
 *
 *  2. NO API MOVES fires_at — ClockService's public surface is exactly
 *     {arm, fire, cancel, resolvedInt}; a "reschedule" method appearing is
 *     a constitutional violation. A source scan proves no update-shaped
 *     write of fires_at exists anywhere in app/ (armed timers can only be
 *     fired or cancelled — never moved; re-arming at certification creates
 *     a NEW audited timer).
 *
 *  3. ESM-03 HAS NO SKIP OR DELAY EDGES — the adjacency map is pinned
 *     exactly: strictly forward through the open-ballot phases, no edge
 *     back to a scheduling state, cancellation unreachable once ballots
 *     can exist (ranked_open and later), terminal states terminal.
 *
 *  4. OUT-OF-WINDOW SPECIALS REJECTED — the F-ELB-001 special window
 *     guard rejects any ranked window outside
 *     [declared_at + special_election_min_days, + max_days] with citation
 *     Art. II §5 (boards may move dates only WITHIN the window; the
 *     CLK-04 backstop force-schedules if they produce nothing).
 *
 * If an edit breaks these tests, the edit is the violation — fix the
 * edit, never the test.
 */
class ElectionClockTest extends TestCase
{
    // ======================================================================
    // 1. Elections fire from clocks, never official discretion
    // ======================================================================

    public function test_election_clocks_route_to_their_handler_jobs(): void
    {
        $this->assertSame(ScheduleGeneralElectionJob::class, ClockService::HANDLERS['CLK-01'] ?? null, 'CLK-01 must trigger the general-election scheduler.');
        $this->assertSame(SpecialElectionBackstopJob::class, ClockService::HANDLERS['CLK-04'] ?? null, 'CLK-04 must trigger the special-election backstop.');
        $this->assertSame(FinalistCutoffJob::class, ClockService::HANDLERS['CLK-18'] ?? null, 'CLK-18 must trigger the finalist cutoff.');

        $this->assertSame(AdvanceElectionPhaseJob::class, ClockService::STEP_HANDLERS['ranked_open'] ?? null);
        $this->assertSame(AdvanceElectionPhaseJob::class, ClockService::STEP_HANDLERS['ranked_close'] ?? null);
    }

    // ======================================================================
    // 2. No API moves fires_at
    // ======================================================================

    public function test_clock_service_public_surface_cannot_reschedule(): void
    {
        $reflection = new \ReflectionClass(ClockService::class);

        $public = array_map(
            fn (\ReflectionMethod $m) => $m->getName(),
            array_filter(
                $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
                fn (\ReflectionMethod $m) => ! $m->isConstructor(),
            ),
        );

        sort($public);

        $this->assertSame(
            ['arm', 'cancel', 'fire', 'resolvedInt'],
            $public,
            'ClockService grew public surface beyond {arm, fire, cancel, resolvedInt} — '
            . 'any reschedule/move API on timers is the no-skip violation.'
        );
    }

    public function test_no_code_path_mutates_a_timer_fires_at(): void
    {
        $violations = [];

        // Quoted KEY writes only (`'fires_at' =>`) — array reads like
        // $payload['fires_at'] are not writes.
        $forbidden = [
            '/->\s*update\s*\([^;]*[\'"]fires_at[\'"]\s*=>/s' => 'query/model update() touching fires_at',
            '/forceFill\s*\([^;]*[\'"]fires_at[\'"]\s*=>/s'   => 'forceFill() touching fires_at',
            '/->\s*fill\s*\([^;]*[\'"]fires_at[\'"]\s*=>/s'   => 'fill() touching fires_at',
            '/upsert\s*\([^;]*[\'"]fires_at[\'"]\s*=>/s'      => 'upsert() touching fires_at',
            '/->\s*fires_at\s*=[^=>]/'                        => 'property assignment to fires_at',
            '/\bUPDATE\s+[^;]*\bSET\s+[^;]*\bfires_at\b/is'   => 'raw SQL UPDATE ... SET fires_at',
        ];

        foreach ($this->appPhpFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (! str_contains($source, 'fires_at')) {
                continue;
            }

            foreach ($forbidden as $pattern => $label) {
                if (preg_match($pattern, $source) === 1) {
                    $violations[] = "{$path}: {$label}";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "No-skip violation — armed timers may only be fired or cancelled, never moved:\n"
            . implode("\n", $violations)
        );
    }

    public function test_fires_at_is_written_only_by_clock_service_arming(): void
    {
        $whitelist = [
            $this->normalize($this->appPath() . '/Services/ClockService.php'), // ClockTimer::create in arm()
            $this->normalize($this->appPath() . '/Models/ClockTimer.php'),     // fillable/casts
        ];

        $found = [];

        foreach ($this->appPhpFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('/[\'"]fires_at[\'"]\s*=>/', $source) === 1) {
                $found[] = $this->normalize($path);
            }
        }

        $rogue = array_diff($found, $whitelist);

        $this->assertSame(
            [],
            array_values($rogue),
            "fires_at written outside ClockService::arm():\n" . implode("\n", $rogue)
        );
    }

    // ======================================================================
    // 3. ESM-03 — no skip, no delay, no backward edges
    // ======================================================================

    public function test_esm03_adjacency_is_exactly_the_open_ballot_machine(): void
    {
        $this->assertSame(
            [
                Election::STATUS_SCHEDULED       => [Election::STATUS_APPROVAL_OPEN, Election::STATUS_CANCELLED],
                Election::STATUS_APPROVAL_OPEN   => [Election::STATUS_FINALIST_CUTOFF, Election::STATUS_CANCELLED],
                Election::STATUS_FINALIST_CUTOFF => [Election::STATUS_RANKED_OPEN, Election::STATUS_CANCELLED],
                Election::STATUS_RANKED_OPEN     => [Election::STATUS_VOTING_CLOSED],
                Election::STATUS_VOTING_CLOSED   => [Election::STATUS_TABULATING],
                Election::STATUS_TABULATING      => [Election::STATUS_CERTIFIED],
                Election::STATUS_CERTIFIED       => [Election::STATUS_AUDIT_RERUN, Election::STATUS_FINAL],
                Election::STATUS_AUDIT_RERUN     => [Election::STATUS_CERTIFIED, Election::STATUS_FINAL],
                Election::STATUS_FINAL           => [],
                Election::STATUS_CANCELLED       => [],
            ],
            ElectionLifecycleService::TRANSITIONS,
            'ESM-03 adjacency drifted — every edit here is a constitutional matter (Art. II §2).'
        );

        // Derived invariants, stated explicitly so the intent survives a
        // rewrite of the literal map above:
        $transitions = ElectionLifecycleService::TRANSITIONS;

        // Once the ranked window opens (ballots exist), cancellation is
        // unreachable — no discretionary abort of a live election.
        foreach ([
            Election::STATUS_RANKED_OPEN,
            Election::STATUS_VOTING_CLOSED,
            Election::STATUS_TABULATING,
            Election::STATUS_CERTIFIED,
            Election::STATUS_AUDIT_RERUN,
        ] as $phase) {
            $this->assertNotContains(
                Election::STATUS_CANCELLED,
                $transitions[$phase],
                "{$phase} must not reach cancelled — ballots exist."
            );
        }

        // No edge ever RETURNS to a pre-ballot scheduling state (no
        // "reschedule the election" path) — scheduled → approval_open is
        // the one legitimate forward entry.
        foreach ($transitions as $from => $targets) {
            $this->assertNotContains(Election::STATUS_SCHEDULED, $targets, "{$from} → scheduled is a delay path.");

            if ($from !== Election::STATUS_SCHEDULED) {
                $this->assertNotContains(Election::STATUS_APPROVAL_OPEN, $targets, "{$from} → approval_open is a delay path.");
            }
        }

        // Terminal states are terminal.
        $this->assertSame([], $transitions[Election::STATUS_FINAL]);
        $this->assertSame([], $transitions[Election::STATUS_CANCELLED]);
    }

    // ======================================================================
    // 4. Out-of-window special elections rejected with citation
    // ======================================================================

    public function test_special_election_window_is_constitutionally_bounded(): void
    {
        $declared = CarbonImmutable::parse('2026-07-01T00:00:00Z');

        // Within [declared+90d, declared+180d]: lawful.
        ElectionSchedulingOrder::assertSpecialWindow(
            $declared,
            $declared->addDays(95),
            $declared->addDays(109),
            90,
            180,
        );
        $this->addToAssertionCount(1);

        // Outside, in either direction: rejected, citation Art. II §5.
        foreach ([
            [$declared->addDays(89), $declared->addDays(103)],   // opens before the window
            [$declared->addDays(1), $declared->addDays(15)],     // far too early
            [$declared->addDays(170), $declared->addDays(181)],  // closes after the window
            [$declared->addDays(200), $declared->addDays(214)],  // entirely past it
        ] as [$opens, $closes]) {
            try {
                ElectionSchedulingOrder::assertSpecialWindow($declared, $opens, $closes, 90, 180);
                $this->fail('Out-of-window special election dates must be rejected (Art. II §5).');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §5', $e->citation);
            }
        }
    }

    // ======================================================================
    // Plumbing
    // ======================================================================

    /** @return \Generator<string> every .php file under app/ */
    private function appPhpFiles(): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->appPath(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    private function appPath(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app';
    }

    private function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
