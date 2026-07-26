<?php

namespace Tests\Constitutional;

use App\Services\Demo\PersonaFactory;
use Tests\TestCase;

/**
 * PIN — a synthetic population does not repeat its own names.
 *
 * This is a demo-quality rule, not a constitutional one, but it earned a pin the
 * hard way. Names used to be two random draws into a 16 × 12 corpus — 192
 * possibilities — so the birthday bound guaranteed collisions within a few dozen
 * people. The first real world (Niue, 379 residents) came out wearing 235
 * distinct names: five separate people called "Giulia Agostini", four "Clara
 * Calder".
 *
 * The cost was not cosmetic. A double-seat scan grouped by NAME reported one
 * person holding a seat in both chambers of Niue's legislature — which would have
 * been a real violation of bicameral dual agreement, since that person would vote
 * twice on every act. It was two different people sharing a generated name. A
 * roster that repeats itself manufactures false defects and camouflages true ones.
 *
 * PersonaFactory is pure, so this pin needs no database.
 */
class PersonaNameUniquenessTest extends TestCase
{
    /** The headline: a jurisdiction-sized population has no two people alike. */
    public function test_a_full_corpus_cycle_produces_no_duplicate_names(): void
    {
        $names = $this->names('niue-seed', 192);

        $this->assertCount(
            192,
            array_unique($names),
            'the first |given| × |family| people must each get their own name — that is what a bijection buys'
        );
    }

    /** Niue's actual population is the case that exposed the defect. */
    public function test_the_population_that_found_this_is_now_clean(): void
    {
        $names = $this->names('niue-seed', 379);

        $duplicates = array_filter(array_count_values($names), fn ($n) => $n > 1);

        $this->assertLessThanOrEqual(
            1,
            max([1, ...array_values($duplicates)]),
            'past one full cycle a second family name extends the space; nobody should share a name three ways'
        );
    }

    /** Beyond one cycle the space must MULTIPLY, not wrap onto itself. */
    public function test_past_one_cycle_names_extend_rather_than_repeat(): void
    {
        $names = $this->names('niue-seed', 400);

        $this->assertGreaterThan(
            192,
            count(array_unique($names)),
            'a second cycle that simply repeated the first would cap distinct names at the corpus size'
        );

        $this->assertStringContainsString(
            '-',
            $names[300],
            'people past the first cycle carry a second family name'
        );
    }

    /** Two places must not both open with the same person. */
    public function test_different_jurisdictions_start_at_different_names(): void
    {
        $this->assertNotSame(
            PersonaFactory::make('alofi-south', ['en'], 'town', 0)['name'],
            PersonaFactory::make('hakupu', ['en'], 'town', 0)['name'],
            'the seed offsets the cycle so neighbouring villages do not mirror each other'
        );
    }

    /** Determinism is the whole contract — the same world regenerates identically. */
    public function test_the_same_seed_and_index_always_yield_the_same_person(): void
    {
        $first = PersonaFactory::make('niue-seed', ['en'], 'town', 42);
        $second = PersonaFactory::make('niue-seed', ['en'], 'town', 42);

        $this->assertSame($first['name'], $second['name']);
        $this->assertSame($first['occupation'], $second['occupation']);
        $this->assertSame($first['locale'], $second['locale']);
    }

    /** @return list<string> */
    private function names(string $seed, int $count): array
    {
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            // A single-language jurisdiction, so every person draws the same
            // corpus — the strictest case for collisions.
            $out[] = PersonaFactory::make($seed, ['en'], 'town', $i)['name'];
        }

        return $out;
    }
}
