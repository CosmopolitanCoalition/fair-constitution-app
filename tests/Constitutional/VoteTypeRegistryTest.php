<?php

namespace Tests\Constitutional;

use App\Services\ChamberVoteService;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the 33-row Special Vote Types registry as code
 * (config/constitution/vote_types.php; CGA Roles & Forms Chart sheet 7,
 * EXPLORE_registry §E). Boot-time completeness: the config covers all 33
 * registry rows exactly — no additions, no omissions, no shape drift —
 * and the chamber engine refuses keys outside it.
 */
class VoteTypeRegistryTest extends TestCase
{
    /** The canonical 33, in registry order. */
    private const KEYS = [
        // Simple Majority (3)
        'bill_pass', 'committee_bill', 'bog_consent',
        // Supermajority (19)
        'speaker_elect', 'speaker_replace', 'committee_create',
        'exec_delegate', 'exec_office_create', 'exec_office_alter',
        'judiciary_create', 'judiciary_convert', 'referendum_delegate',
        'emergency_invoke', 'emergency_renew', 'officeholder_remove',
        'judiciary_override', 'cultural_institution', 'additional_articles',
        'referendum_act_modify', 'boundary_change', 'union_form_join', 'union_exit',
        // Population-level (3)
        'referendum_majority', 'referendum_supermajority', 'petition_initiative',
        // Bicameral structural (1)
        'bicameral_dual_agreement',
        // RCV / STV (6)
        'general_legislative', 'exec_committee_stv', 'exec_individual_rcv',
        'judicial_election', 'committee_chair', 'committee_preference',
        // The implicit 33rd (owner ruling: unstated = majority of all serving)
        'procedural_motion',
    ];

    private const CATEGORIES   = ['simple_majority', 'supermajority', 'population', 'bicameral', 'rcv_stv'];

    private const ENGINES      = ['chamber', 'population_ballot', 'stv_count', 'multi_jurisdiction', 'assignment'];

    private const BASES        = [
        'majority', 'supermajority', 'population_majority', 'population_supermajority',
        'rcv_single', 'rcv_supermajority', 'stv', 'ranked_preference', 'unanimity_constituents',
    ];

    private const DENOMINATORS = ['serving', 'committee_serving', 'civic_population', 'constituent_jurisdictions', 'board'];

    public function test_registry_covers_exactly_the_33_rows(): void
    {
        $config = config('constitution.vote_types');

        $this->assertIsArray($config);
        $this->assertCount(33, $config);
        $this->assertSame([], array_diff(self::KEYS, array_keys($config)), 'missing registry keys');
        $this->assertSame([], array_diff(array_keys($config), self::KEYS), 'keys not in the registry');
    }

    public function test_every_row_has_the_full_legal_shape(): void
    {
        foreach (config('constitution.vote_types') as $key => $row) {
            foreach (['label', 'category', 'engine', 'basis', 'denominator', 'bicameral', 'phase', 'citation'] as $field) {
                $this->assertArrayHasKey($field, $row, "{$key}.{$field}");
            }

            $this->assertArrayHasKey('dual', $row, "{$key}.dual");
            $this->assertNotSame('', $row['label'], "{$key}.label");
            $this->assertContains($row['category'], self::CATEGORIES, "{$key}.category");
            $this->assertContains($row['engine'], self::ENGINES, "{$key}.engine");
            $this->assertContains($row['basis'], self::BASES, "{$key}.basis");
            $this->assertContains($row['denominator'], self::DENOMINATORS, "{$key}.denominator");
            $this->assertContains($row['bicameral'], ['per_kind', 'n/a'], "{$key}.bicameral");
            $this->assertContains($row['dual'], [null, 'constituent_supermajority'], "{$key}.dual");
            $this->assertContains($row['phase'], ['A', 'B', 'C', 'D', 'E', 'F'], "{$key}.phase");
            $this->assertStringContainsString('Art.', (string) $row['citation'], "{$key}.citation");
        }
    }

    public function test_chamber_engine_keys_carry_chamber_legal_bases(): void
    {
        foreach (config('constitution.vote_types') as $key => $row) {
            if ($row['engine'] !== 'chamber') {
                continue;
            }

            $this->assertContains(
                $row['basis'],
                ['majority', 'supermajority', 'rcv_single', 'rcv_supermajority'],
                "{$key}: chamber engine basis"
            );
            $this->assertContains(
                $row['denominator'],
                ['serving', 'committee_serving'],
                "{$key}: chamber denominators are serving-pegged"
            );
        }
    }

    public function test_unknown_keys_are_refused_by_the_engine(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ChamberVoteService::voteTypeConfig('first_past_the_post');
    }

    public function test_the_constitutionally_load_bearing_rows(): void
    {
        $types = config('constitution.vote_types');

        // Bills: majority of all serving, per kind, at both stages.
        $this->assertSame('majority', $types['bill_pass']['basis']);
        $this->assertSame('per_kind', $types['bill_pass']['bicameral']);
        $this->assertSame('committee_serving', $types['committee_bill']['denominator']);

        // Speaker: supermajority RCV of serving — and replacement matches.
        $this->assertSame('rcv_supermajority', $types['speaker_elect']['basis']);
        $this->assertSame('rcv_supermajority', $types['speaker_replace']['basis']);
        $this->assertSame('serving', $types['speaker_elect']['denominator']);

        // Emergency powers: supermajority only (Art. II §7).
        $this->assertSame('supermajority', $types['emergency_invoke']['basis']);
        $this->assertSame('supermajority', $types['emergency_renew']['basis']);

        // Population thresholds peg on the CIVIC population.
        foreach (['referendum_majority', 'referendum_supermajority', 'petition_initiative', 'boundary_change'] as $key) {
            $this->assertSame('civic_population', $types[$key]['denominator'], $key);
        }

        // The owner ruling: unstated votes are an ordinary majority of
        // all serving members.
        $this->assertSame('majority', $types['procedural_motion']['basis']);
        $this->assertSame('serving', $types['procedural_motion']['denominator']);
    }
}
