<?php

namespace Tests\Constitutional;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * `EnactmentService::allocateActNumber` must be GAP-SAFE.
 *
 * It used to return COUNT + 1, which is correct only while the sequence has no
 * holes — and holes are ordinary: a demo teardown hard-deletes its rows, an act
 * is purged, an act number outside the "Act YYYY-NN" pattern is issued
 * alongside. The moment one exists, COUNT re-issues a number that is already
 * taken and `laws_legislature_id_act_number_unique` rejects the enactment.
 *
 * WHY THIS EARNED A PIN RATHER THAN A QUIET FIX: the law is written in the SAME
 * transaction as the tally, so the duplicate surfaced as a *bicameral vote
 * failing to carry*. On a world that had just seated its second chamber for the
 * first time, that read as "the Type B ruling did not work" — it cost real time
 * to trace a unique-index violation back from an apparently constitutional
 * failure. A regression here would be expensive again for the same reason.
 *
 * Observed 2026-07-26: a legislature holding Act 2026-02 and Act 2026-03, with
 * 01 deleted, counted 2 and minted "Act 2026-03" — a duplicate. It blocked
 * institutions:demo-d at the CGC charter and institutions:demo-e at judiciary
 * creation.
 *
 * MAX + 1 is also the constitutionally correct rule, not merely the working
 * one: act numbers are permanent citations. Reusing one would point two
 * different acts at the same name.
 */
class ActNumberAllocationTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'act_number_pg';

    public function test_the_allocator_is_gap_safe(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            // Real FK targets — `laws` references both. A far-future year
            // keeps the fixture's numbering isolated from the world's real
            // acts, so the gap under test is the only one that exists.
            $legislature = DB::table('legislatures')->first();

            if ($legislature === null) {
                $this->markTestSkipped('No legislature on this box — the pin needs a founded world.');
            }

            $legislatureId = (string) $legislature->id;
            $jurisdictionId = (string) $legislature->jurisdiction_id;
            $year = 2099;

            // A sequence WITH A HOLE: 01 is gone, 02 and 03 remain.
            foreach ([2, 3] as $n) {
                DB::table('laws')->insert([
                    'id'              => (string) Str::uuid(),
                    'jurisdiction_id' => $jurisdictionId,
                    'legislature_id'  => $legislatureId,
                    'act_number'      => sprintf('Act %d-%02d', $year, $n),
                    'title'           => "Pin fixture {$n}",
                    'kind'            => 'creation_act',
                    'scale'           => json_encode([$jurisdictionId]),
                    'origin'          => 'bill',
                    'status'          => 'in_force',
                    'current_version_no' => 1,
                    'effective_at'    => now(),
                    'enacted_at'      => now(),
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }

            $count = DB::table('laws')
                ->where('legislature_id', $legislatureId)
                ->where('act_number', 'like', "Act {$year}-%")
                ->count();

            $highest = DB::table('laws')
                ->where('legislature_id', $legislatureId)
                ->where('act_number', 'like', "Act {$year}-%")
                ->selectRaw("MAX(CAST(SUBSTRING(act_number FROM 'Act [0-9]{4}-([0-9]+)$') AS INTEGER)) AS n")
                ->value('n');

            // The old rule would have collided — this is what made it a defect,
            // and asserting it keeps the reason legible if anyone reverts.
            $this->assertSame(2, (int) $count, 'the fixture has a hole at 01');
            $this->assertSame(
                sprintf('Act %d-%02d', $year, 3),
                sprintf('Act %d-%02d', $year, (int) $count + 1),
                'COUNT + 1 reproduces an act number that already exists'
            );

            // The rule in force must clear every existing number.
            $next = sprintf('Act %d-%02d', $year, ((int) $highest) + 1);

            $this->assertSame(sprintf('Act %d-%02d', $year, 4), $next);

            $this->assertFalse(
                DB::table('laws')
                    ->where('legislature_id', $legislatureId)
                    ->where('act_number', $next)
                    ->exists(),
                'the allocated act number must not already be taken'
            );
        } finally {
            $conn->rollBack();
            DB::setDefaultConnection($original);
        }
    }

    /** The service must use the gap-safe rule, not the counting one. */
    public function test_the_service_allocates_from_the_highest_not_the_count(): void
    {
        $source = file_get_contents(app_path('Services/EnactmentService.php'));

        $this->assertStringContainsString(
            'MAX(CAST(SUBSTRING(act_number',
            $source,
            'allocateActNumber must derive the next number from the HIGHEST existing one.'
        );

        $this->assertStringNotContainsString(
            '->count();'."\n\n".'        return sprintf(\'Act %d-%02d\'',
            $source,
            'allocateActNumber must not return COUNT + 1 — it collides across any gap.'
        );
    }
}
