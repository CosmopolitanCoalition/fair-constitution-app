<?php

namespace Tests\Constitutional;

use App\Models\Bill;
use App\Models\ChamberVote;
use App\Services\BillService;
use App\Services\ClockRederivationService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the settings path (Art. VII / WF-LEG-14): an
 * amendable setting changes ONLY through the full legislative path
 * (F-LEG-031 setting bill → peg-quorum floor vote → enactment →
 * setting_changes ledger + constitutional_settings + clock re-derivation).
 *
 * DB-free pins (established posture): the act_type → basis fix, the
 * derivation arithmetic that moves CLK-01/CLK-02 deadlines after a
 * setting change, and source-scanned invariants of the PROTECTED-path
 * delegation. The full end-to-end (Montegiardino settings bill
 * election_interval_months 60→48→60 with the armed CLK-01 timer's
 * fires_at asserted moved and restored) is the live tinker verification
 * — the phase exit criterion.
 */
class SettingEnactmentTest extends TestCase
{
    public function test_act_type_fixes_the_floor_vote_basis_at_introduction(): void
    {
        // ordinary + setting_change ride the bill_pass majority (peg);
        // supermajority/dual_supermajority fix a supermajority basis.
        $this->assertNull(BillService::basisForActType(Bill::TYPE_ORDINARY));
        $this->assertNull(BillService::basisForActType(Bill::TYPE_SETTING_CHANGE));
        $this->assertSame(ChamberVote::BASIS_SUPERMAJORITY, BillService::basisForActType(Bill::TYPE_SUPERMAJORITY));
        $this->assertSame(ChamberVote::BASIS_SUPERMAJORITY, BillService::basisForActType(Bill::TYPE_DUAL_SUPERMAJORITY));
    }

    public function test_clock_rederivation_arithmetic(): void
    {
        // CLK-01 shape: fires_at = certified anchor + interval months −
        // frozen lead_days. 60 → 48 months moves the deadline back exactly
        // 12 months; re-deriving back to 60 restores it bit-for-bit.
        $derive = [
            'anchor_at' => '2026-04-28T16:00:00+00:00',
            'unit'      => 'months',
            'lead_days' => 45,
        ];

        $at60 = ClockRederivationService::deriveFiresAt($derive, 60);
        $at48 = ClockRederivationService::deriveFiresAt($derive, 48);

        $this->assertSame('2031-03-14T16:00:00+00:00', $at60->toIso8601String());
        $this->assertSame('2030-03-14T16:00:00+00:00', $at48->toIso8601String());
        $this->assertTrue($at60->equalTo(ClockRederivationService::deriveFiresAt($derive, 48 + 12)));

        // CLK-02 shape: rolling deadline anchor + days.
        $clk02 = ClockRederivationService::deriveFiresAt(['anchor_at' => '2026-06-01T00:00:00+00:00', 'unit' => 'days'], 90);
        $this->assertSame('2026-08-30T00:00:00+00:00', $clk02->toIso8601String());

        // Unknown units / missing anchors derive NOTHING — never guess.
        $this->assertNull(ClockRederivationService::deriveFiresAt(['anchor_at' => '2026-06-01T00:00:00Z', 'unit' => 'years'], 1));
        $this->assertNull(ClockRederivationService::deriveFiresAt(['unit' => 'days'], 90));
    }

    public function test_enactment_rechecks_bounds_and_active_emergency_durations_never_rederive(): void
    {
        // Source-scanned invariants (the CountbackUniversalTest pattern):
        //
        // 1. EnactmentService re-runs the PROTECTED checkSettingChange at
        //    enactment (TOCTOU guard) — the bounds gate cannot be skipped
        //    between vote and application.
        $enactment = file_get_contents(app_path('Services/EnactmentService.php'));
        $this->assertStringContainsString('checkSettingChange', $enactment);
        $this->assertStringContainsString('TOCTOU', $enactment);

        // 2. CLK-03 timers are excluded from re-derivation: an active
        //    emergency power keeps its DECLARED duration (Art. II §7) —
        //    a lowered max binds only new declarations/renewals. And the
        //    re-derivation itself is CANCEL + RE-ARM through ClockService's
        //    only write paths — armed timers are never moved (the
        //    ElectionClockTest no-skip pin stays whole).
        $rederivation = file_get_contents(app_path('Services/ClockRederivationService.php'));
        $this->assertMatchesRegularExpression("/reject\\(.*'CLK-03'/s", $rederivation);
        $this->assertStringContainsString('->cancel(', $rederivation);
        $this->assertStringContainsString('->arm(', $rederivation);
        $this->assertStringNotContainsString('forceFill', $rederivation);

        // 3. The applied change is ledgered: no constitutional_settings
        //    mutation without its setting_changes row + enacting law.
        $this->assertStringContainsString('SettingChange::create', $enactment);
        $this->assertStringContainsString('last_amended_by_act_id', $enactment);
    }

    public function test_rederivation_only_moves_the_interval_part(): void
    {
        // lead_days is FROZEN in the derive payload at arm time: a
        // re-derivation moves only the interval — the approval/ranked
        // lead embedded in the original arm survives byte-identically.
        $anchor = Carbon::parse('2026-04-28T16:00:00+00:00');

        foreach ([15, 45, 90] as $lead) {
            $derive = ['anchor_at' => $anchor->toIso8601String(), 'unit' => 'months', 'lead_days' => $lead];

            $this->assertTrue(
                ClockRederivationService::deriveFiresAt($derive, 48)
                    ->equalTo($anchor->copy()->addMonths(48)->subDays($lead))
            );
        }
    }
}
