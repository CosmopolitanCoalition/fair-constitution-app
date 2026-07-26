<?php

namespace Tests\Constitutional;

use App\Http\Controllers\Dev\ChamberCastController;
use App\Http\Middleware\DevTimeControlsEnabled;
use ReflectionClass;
use Tests\TestCase;

/**
 * P4's one non-negotiable property: the playtest chamber cast supplies
 * BALLOTS, never OUTCOMES.
 *
 * A dev control that can make an act pass is worthless as a test — it would
 * prove only that the control works — and it is dangerous as a precedent,
 * because the next person needing "just this once" finds the door open. The
 * whole value of walking "a bill becomes law" is that the law might not pass.
 *
 * These are structural pins rather than a live vote: they assert the
 * controller cannot reach an outcome even if someone later wanted it to.
 * The behavioural half is proven on the founded world — the committee vote
 * carried 31 type_a / 27 type_b through this exact engine path — and the
 * insufficient-cast case is covered by the engine's own quorum and
 * supermajority pins, which this control cannot circumvent by construction.
 */
class ChamberCastIsBallotsOnlyTest extends TestCase
{
    private function controllerSource(): string
    {
        return file_get_contents((new ReflectionClass(ChamberCastController::class))->getFileName());
    }

    /** It must file through the real engine, as real members. */
    public function test_it_casts_through_the_constitutional_engine(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString(
            "file('F-LEG-004'",
            $source,
            'Ballots must go through the engine as F-LEG-004 filings, exactly as demo-d and demo-e do.'
        );
    }

    /**
     * THE PIN. It must never write a tally, an outcome, or a vote status —
     * those belong to the engine alone.
     */
    public function test_it_never_writes_a_tally_an_outcome_or_a_status(): void
    {
        $source = $this->controllerSource();

        $forbidden = [
            "table('chamber_votes')" => 'the vote row is the engine\'s to write',
            'outcome =>'             => 'an outcome may never be supplied',
            "->update(['outcome"     => 'an outcome may never be supplied',
            "'outcome' =>"           => 'an outcome may never be supplied',
            'STATUS_ADOPTED'         => 'adoption is computed, never asserted',
            'tally_for'              => 'tallies are the engine\'s',
            'tally_against'          => 'tallies are the engine\'s',
        ];

        foreach ($forbidden as $needle => $why) {
            $this->assertStringNotContainsString(
                $needle,
                $source,
                "ChamberCastController must not contain [{$needle}] — {$why}."
            );
        }
    }

    /**
     * It must READ the outcome the engine reached rather than decide one, and
     * stop pushing ballots once the engine has closed the vote.
     */
    public function test_it_reads_the_engines_outcome_and_stops_when_the_vote_closes(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString('$vote->refresh();', $source,
            'It must re-read the vote to report what the ENGINE decided.');

        $this->assertStringContainsString('STATUS_OPEN', $source,
            'It must stop casting once the engine closes the vote.');
    }

    /** The gate: its own key, on top of the dev toolbox gate. */
    public function test_the_gate_requires_its_own_key_on_top_of_the_dev_gate(): void
    {
        $gate = file_get_contents((new ReflectionClass(DevTimeControlsEnabled::class))->getFileName());

        foreach (["environment('local')", 'cga.impersonation', 'cga.dev_time', 'isSandbox'] as $required) {
            $this->assertStringContainsString($required, $gate, "The gate must check [{$required}].");
        }

        $this->assertSame(false, config('cga.dev_time'), 'cga.dev_time must default to OFF.');
    }

    /**
     * Full Faith & Credit: a peer takes this node's records ON TRUST rather
     * than re-deriving them, so a node others trust must never be able to
     * manufacture a vote. The check must also FAIL CLOSED.
     */
    public function test_the_gate_refuses_on_any_federated_or_peered_node(): void
    {
        $gate = file_get_contents((new ReflectionClass(DevTimeControlsEnabled::class))->getFileName());

        $this->assertStringContainsString('federation_peers', $gate,
            'Any peer must disable the control.');
        $this->assertStringContainsString('mirror_of_server_id', $gate,
            'A node that mirrors another is authoritative for nothing and must be refused.');
        $this->assertStringContainsString('catch (\Throwable) {'."\n".'            return true;', $gate,
            'The connectivity check must FAIL CLOSED — an uncertain read must count as connected.');
    }

    /**
     * A ballot supply that can INVENT VOTERS is as useless as one that can
     * invent results — same family as the outcome pin. Eligibility is drawn
     * from seated members of the vote's own legislature, and CURRENT ones:
     * an election cycle `term_ends` the previous cohort and leaves those rows
     * in place, so filtering on `vacated_at` alone would let the control cast
     * as ex-legislators. (That exact bug bit institutions:demo-lawmaking.)
     */
    public function test_it_cannot_cast_for_anyone_who_is_not_currently_seated(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString(
            'CURRENT_STATUSES',
            $source,
            'Eligibility must be current seats, not merely un-vacated rows — a term-ended member is not a voter.'
        );

        $this->assertStringContainsString(
            "where('legislature_id', \$vote->legislature_id)",
            $source,
            'Ballots may only come from the legislature the vote belongs to.'
        );

        $this->assertStringContainsString(
            "whereNotNull('user_id')",
            $source,
            'A seat with no person behind it cannot cast.'
        );
    }

    /**
     * One member, one ballot. The control must exclude anyone who has already
     * cast on this vote — otherwise a bloc could be stacked by re-running it,
     * which is outcome-forcing wearing a different hat.
     */
    public function test_it_cannot_cast_twice_for_the_same_member(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString(
            "DB::table('vote_casts')->where('vote_id', \$vote->id)->pluck('member_id')",
            $source,
            'It must read who has already cast on this vote.'
        );

        $this->assertStringContainsString(
            "whereNotIn('id', \$already)",
            $source,
            'Members who have already cast must be excluded — one member, one ballot, even across repeated calls.'
        );
    }

    /** Every invocation must be distinguishable in the audit chain forever. */
    public function test_every_cast_is_audit_marked_as_a_dev_action(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString("'chamber.cast_bloc'", $source,
            'Each bloc cast must append its own audit event.');
        $this->assertStringContainsString("module: 'dev'", $source,
            'The audit entry must be marked as a DEV action so a playtested timeline never reads as a lived one.');
    }
}
