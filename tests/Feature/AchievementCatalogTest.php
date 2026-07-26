<?php

namespace Tests\Feature;

use App\Domain\Achievements\AchievementCatalog as Catalog;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Registry integrity for the K-2 achievement catalogue.
 *
 * These are NOT constitutional pins — they are drift guards. The constitutional
 * pins (CI-1 non-interference, PI-2 envelope-only, PI-6 no composite score, and
 * the earner-resolution rule) land with AchievementService, because each needs a
 * live award path to assert against.
 *
 * The first test exists because the drift it guards ACTUALLY HAPPENED, in the
 * same change that added the arc: `become-a-resident` went into
 * config/cga/journeys.php and not into TOUR_ARCS, so the catalogue threw on a
 * key the app was rendering. Two registries describing one set of arcs will
 * drift; the only question is whether anything notices.
 */
class AchievementCatalogTest extends TestCase
{
    public function test_the_tour_arcs_match_the_journey_registry_exactly(): void
    {
        $configured = array_keys(config('cga.journeys'));

        $this->assertSame(
            [],
            array_values(array_diff($configured, Catalog::TOUR_ARCS)),
            'a journey exists in config/cga/journeys.php that the catalogue does not know'
        );

        $this->assertSame(
            [],
            array_values(array_diff(Catalog::TOUR_ARCS, $configured)),
            'the catalogue lists a tour arc that no longer exists in config/cga/journeys.php'
        );
    }

    public function test_every_award_declares_a_complete_and_valid_record(): void
    {
        $scopes  = [Catalog::SCOPE_PERSONAL, Catalog::SCOPE_JURISDICTION, Catalog::SCOPE_SYSTEM];
        $earners = [Catalog::EARNER_SELF, Catalog::EARNER_SUBJECT, Catalog::EARNER_STATE];
        $tiers   = [Catalog::TIER_VERIFIED, Catalog::TIER_TOUR];

        foreach (Catalog::AWARDS as $key => $award) {
            foreach (['title_key', 'scope', 'earner', 'tier', 'trigger'] as $field) {
                $this->assertArrayHasKey($field, $award, "{$key} is missing [{$field}]");
                $this->assertNotSame('', $award[$field], "{$key} has an empty [{$field}]");
            }

            $this->assertContains($award['scope'], $scopes, "{$key} has an unknown scope");
            $this->assertContains($award['earner'], $earners, "{$key} has an unknown earner");
            $this->assertContains($award['tier'], $tiers, "{$key} has an unknown tier");
        }
    }

    /**
     * THE EARNER RULE, catalogue half: the actor on a form is not always the
     * earner. A `subject` entry means a DIFFERENT person earns than the one who
     * filed — so it must say who, or the wiring has nothing to resolve against
     * and would silently fall back to the filer. That fallback is the exact
     * defect this field was introduced to kill (ACH-CAN-005 originally awarded
     * the board member who validated a candidacy rather than the candidate).
     */
    public function test_every_subject_earner_names_who_actually_earns(): void
    {
        $subjects = Catalog::withEarner(Catalog::EARNER_SUBJECT);

        $this->assertNotEmpty($subjects, 'the subject earner class should not vanish silently');

        foreach ($subjects as $key) {
            $this->assertStringContainsString(
                '->',
                Catalog::AWARDS[$key]['trigger'],
                "{$key} is earner=subject but its trigger never names who earns"
            );
        }
    }

    public function test_title_keys_are_unique(): void
    {
        $titles = array_column(Catalog::AWARDS, 'title_key');

        $this->assertSame(
            count($titles),
            count(array_unique($titles)),
            'a duplicate title_key would make two achievements share one translated name'
        );
    }

    public function test_lookup_resolves_arcs_and_rejects_unknown_keys(): void
    {
        $this->assertSame(Catalog::TIER_TOUR, Catalog::get('election')['tier']);
        $this->assertSame(Catalog::TIER_VERIFIED, Catalog::get('ACH-CIV-005')['tier']);
        $this->assertTrue(Catalog::has('become-a-resident'));
        $this->assertFalse(Catalog::has('ACH-NOPE-999'));

        $this->expectException(InvalidArgumentException::class);
        Catalog::get('ACH-NOPE-999');
    }
}
