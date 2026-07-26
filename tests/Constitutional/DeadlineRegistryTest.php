<?php

namespace Tests\Constitutional;

use App\Support\DeadlineRegistry;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * THE TEST THAT KEEPS THE DEADLINE REGISTRY HONEST.
 *
 * "Advance the world" works by pulling registered deadlines backward. That is
 * only safe while the registry is COMPLETE — an unregistered deadline silently
 * fails to move, and the journey stalls somewhere that looks like a bug in the
 * feature rather than a gap in a list.
 *
 * A hand-maintained list rots. So this fails the moment a deadline-shaped
 * column exists on a deadline-bearing table and is neither registered as
 * movable nor exempted WITH A REASON.
 *
 * If this test fails: add the column to DeadlineRegistry — as movable if it is
 * something scheduled that has not happened yet, or as exempt with one line
 * saying why not. **Do not silence it.** The whole value of the registry is
 * that forgetting is impossible.
 */
class DeadlineRegistryTest extends TestCase
{
    use LivePgConnection;

    public function test_every_deadline_column_is_registered_or_exempt_with_a_reason(): void
    {
        // Reads information_schema only — no fixture, nothing written, so the
        // plain live connection is all this needs.
        $pg = $this->livePg('pgsql_deadline_registry');

        $registered = array_flip(DeadlineRegistry::movableKeys());
        $exempt = DeadlineRegistry::exempt();
        $suffixes = DeadlineRegistry::deadlineSuffixes();

        $unaccounted = [];

        foreach (DeadlineRegistry::scannedTables() as $table) {
            $columns = $pg->select('
                SELECT column_name, data_type
                  FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = ?
            ', [$table]);

            foreach ($columns as $col) {
                $name = $col->column_name;

                // Only timestamp/date columns can be deadlines.
                if (! str_contains($col->data_type, 'timestamp') && $col->data_type !== 'date') {
                    continue;
                }

                $looksLikeDeadline = false;
                foreach ($suffixes as $suffix) {
                    if (str_ends_with($name, $suffix)) {
                        $looksLikeDeadline = true;
                        break;
                    }
                }

                if (! $looksLikeDeadline) {
                    continue;
                }

                $key = $table.'.'.$name;

                if (isset($registered[$key]) || isset($exempt[$key])) {
                    continue;
                }

                $unaccounted[] = $key;
            }
        }

        $this->assertSame(
            [],
            $unaccounted,
            "These deadline columns are neither registered as movable nor exempt:\n  "
            .implode("\n  ", $unaccounted)
            ."\n\nAdd each to App\\Support\\DeadlineRegistry — movable if it is something scheduled "
            ."that has not happened yet, exempt (with a reason) if moving it would forge history or "
            ."touch another node's records. Do not silence this test: an unregistered deadline does "
            ."not move, and the journey stalls in a way that looks like a bug in the feature.",
        );
    }

    /** An exemption without a reason is an oversight wearing a decision's clothes. */
    public function test_every_exemption_states_why(): void
    {
        foreach (DeadlineRegistry::exempt() as $key => $why) {
            $this->assertNotEmpty(trim($why), "{$key} is exempt with no reason given.");
            $this->assertGreaterThan(
                20,
                strlen(trim($why)),
                "{$key}'s exemption reason is too thin to be useful to the next reader.",
            );
        }
    }

    /** Nothing may be both movable and exempt — that is a contradiction, not a config. */
    public function test_nothing_is_both_movable_and_exempt(): void
    {
        $both = array_intersect(DeadlineRegistry::movableKeys(), array_keys(DeadlineRegistry::exempt()));

        $this->assertSame([], array_values($both), 'a column cannot be both movable and exempt');
    }

    /**
     * EVERY MOVABLE COLUMN MUST CARRY A STATE GUARD. Without one, advancing
     * time would rewrite rows describing things that already happened — and
     * the entire design rests on never forging history.
     */
    public function test_every_movable_column_guards_against_touching_history(): void
    {
        foreach (DeadlineRegistry::movable() as $entry) {
            $key = $entry['table'].'.'.$entry['column'];

            $this->assertNotEmpty(trim($entry['guard']), "{$key} has no state guard — it could move settled rows.");
            $this->assertNotEmpty(trim($entry['why']), "{$key} does not say what it is.");
        }
    }

    /** The spine of the whole feature. If this is missing, nothing advances. */
    public function test_the_clock_timer_deadline_is_registered(): void
    {
        $this->assertContains('clock_timers.fires_at', DeadlineRegistry::movableKeys());
    }

    /** The election phase ladder must move as a unit or a window inverts. */
    public function test_the_whole_election_phase_ladder_is_registered(): void
    {
        $keys = DeadlineRegistry::movableKeys();

        foreach (['approval_opens_at', 'finalist_cutoff_at', 'ranked_opens_at', 'ranked_closes_at'] as $col) {
            $this->assertContains(
                "elections.{$col}",
                $keys,
                "elections.{$col} must move with the rest of the ladder, or an election ends up with "
                .'its ranked window opening before its approval window.',
            );
        }
    }
}
