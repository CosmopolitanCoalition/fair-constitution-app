<?php

namespace Tests\Feature;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\FormRegistry;
use App\Services\AuditService;
use App\Services\ConstitutionalValidator;
use Tests\TestCase;

/**
 * WI-2 smoke test — pure-logic coverage of the audit chain + engine
 * skeleton. Deliberately touches NO database:
 *
 * The phpunit 'testing' connection is sqlite :memory:, but the audit_log
 * migration is Postgres-only by design (BIGSERIAL, jsonb, plpgsql
 * append-only triggers) and the dev Postgres holds ~951k live
 * jurisdictions (RefreshDatabase is forbidden). The DB-backed paths
 * (append → verify → reject → trigger raise) are exercised against the
 * live dev database via tinker — see the WI-2 verification checklist in
 * the work-item report. What this suite pins:
 *
 *  - canonical_json determinism (recursive key sort, list order kept)
 *  - chain hash construction hash(n) = sha256(hash(n-1) || payload(n))
 *    including a simulated 5-link chain with a tampered middle link
 *  - FormRegistry: 104 canonical IDs, alias resolution, drift safety
 *  - ConstitutionalValidator: supermajority/quorum math, settings bounds
 *    (out-of-range → ConstitutionalViolation citing Art. II §2),
 *    rights.automatic guard
 */
class AuditChainSmokeTest extends TestCase
{
    // -------------------------------------------------------------------------
    // canonical_json + chain hashing
    // -------------------------------------------------------------------------

    public function test_canonical_json_sorts_keys_recursively_and_preserves_list_order(): void
    {
        $a = AuditService::canonicalJson([
            'zulu'  => ['b' => 2, 'a' => 1],
            'alpha' => [3, 1, 2], // list — order must be preserved
        ]);
        $b = AuditService::canonicalJson([
            'alpha' => [3, 1, 2],
            'zulu'  => ['a' => 1, 'b' => 2],
        ]);

        $this->assertSame($a, $b);
        $this->assertSame('{"alpha":[3,1,2],"zulu":{"a":1,"b":2}}', $a);
    }

    public function test_chain_hash_matches_reference_construction(): void
    {
        $prev      = str_repeat('0', 64);
        $canonical = AuditService::canonicalJson(['genesis' => true]);

        $this->assertSame(
            hash('sha256', $prev . $canonical),
            AuditService::chainHash($prev, $canonical)
        );
    }

    public function test_simulated_chain_breaks_at_exactly_the_tampered_link(): void
    {
        // Build a 5-link in-memory chain with the production helpers.
        $payloads = [
            ['genesis' => true],
            ['event' => 'individual.registered', 'name' => 'Ada'],
            ['event' => 'residency.declared', 'jurisdiction' => 'earth'],
            ['event' => 'setting.change_filed', 'value' => 8],
            ['event' => 'residency.verified', 'days' => 30],
        ];

        $chain = [];
        $prev  = str_repeat('0', 64);
        foreach ($payloads as $i => $payload) {
            $canonical = AuditService::canonicalJson($payload);
            $hash      = AuditService::chainHash($prev, $canonical);
            $chain[]   = ['seq' => $i + 1, 'payload' => $payload, 'prev_hash' => $prev, 'hash' => $hash];
            $prev      = $hash;
        }

        $verify = function (array $chain): true|int {
            $expectedPrev = str_repeat('0', 64);
            foreach ($chain as $row) {
                if ($row['prev_hash'] !== $expectedPrev) {
                    return $row['seq'];
                }
                $canonical = AuditService::canonicalJson($row['payload']);
                if (AuditService::chainHash($row['prev_hash'], $canonical) !== $row['hash']) {
                    return $row['seq'];
                }
                $expectedPrev = $row['hash'];
            }

            return true;
        };

        $this->assertTrue($verify($chain));

        // Tamper with link 3's payload — verification must report seq 3.
        $tampered = $chain;
        $tampered[2]['payload']['jurisdiction'] = 'mars';
        $this->assertSame(3, $verify($tampered));

        // Rejected entries are ordinary links: flipping payload content used
        // for a rejection entry breaks the chain identically.
        $tampered2 = $chain;
        $tampered2[3]['payload']['value'] = 12;
        $this->assertSame(4, $verify($tampered2));
    }

    // -------------------------------------------------------------------------
    // FormRegistry
    // -------------------------------------------------------------------------

    public function test_registry_holds_exactly_107_canonical_forms(): void
    {
        // 103 Template forms + F-ELB-008 (Manual District Draw, Phase H) + the
        // Phase K-1 civic-commons trio F-SOC-001/002/003 (public square / halls
        // testimony / carve-out removal).
        $this->assertCount(107, FormRegistry::FORMS);
        $this->assertCount(107, FormRegistry::ids());
    }

    public function test_pure_aliases_resolve_to_canonical_ids(): void
    {
        $this->assertSame('F-CHR-001', FormRegistry::canonical('F-COM-001'));
        $this->assertSame('F-CHR-004', FormRegistry::canonical('F-COM-004'));
        $this->assertSame('F-BOG-002', FormRegistry::canonical('F-GOV-002'));
        $this->assertSame('F-BOG-001', FormRegistry::canonical('f-gov-001')); // case-tolerant
    }

    public function test_canonical_ids_are_never_rewritten_by_catalog_drift(): void
    {
        // Each of these is a real canonical form AND a stale catalog
        // reference to a different form. canonical() must return them
        // unchanged — auto-rewriting would misfile one form as another
        // (and the F-LEG-022→023→024→025 drift chain would collapse).
        foreach (['F-IND-004', 'F-IND-005', 'F-IND-013', 'F-LEG-022', 'F-LEG-023', 'F-LEG-024', 'F-LEG-030', 'F-LEG-034'] as $id) {
            $this->assertSame($id, FormRegistry::canonical($id));
        }

        // The drift is still documented and surfaced for display.
        $meta = FormRegistry::meta('F-LEG-036');
        $this->assertArrayHasKey('F-LEG-030', $meta['catalog_drift']);

        $meta = FormRegistry::meta('F-IND-016');
        $this->assertArrayHasKey('F-IND-013', $meta['catalog_drift']);
    }

    public function test_unknown_form_id_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FormRegistry::canonical('F-XYZ-999');
    }

    public function test_meta_exposes_name_roles_and_handler(): void
    {
        $meta = FormRegistry::meta('F-IND-001');

        $this->assertSame('Individual Registration', $meta['name']);
        $this->assertSame(['R-01'], $meta['roles']);
        $this->assertSame(\App\Domain\Forms\Handlers\IndividualRegistration::class, $meta['handler']);

        // Alias input resolves to the canonical form's metadata.
        $this->assertSame('Committee Meeting Call', FormRegistry::meta('F-COM-001')['name']);
        $this->assertContains('F-COM-001', FormRegistry::meta('F-CHR-001')['aliases']);
    }

    // -------------------------------------------------------------------------
    // ConstitutionalValidator — hardened math
    // -------------------------------------------------------------------------

    public function test_supermajority_formula_with_majority_plus_one_floor(): void
    {
        // ceil(n × 2/3) over ALL serving members…
        $this->assertSame(6, ConstitutionalValidator::supermajority(8));
        $this->assertSame(6, ConstitutionalValidator::supermajority(9));
        $this->assertSame(4, ConstitutionalValidator::supermajority(5));
        $this->assertSame(1334, ConstitutionalValidator::supermajority(2000));

        // …but never below majority + 1 (Art. VII): for n=6, ceil(4) would
        // equal the bare majority of 4, so the floor lifts it to 5.
        $this->assertSame(5, ConstitutionalValidator::supermajority(6));
    }

    public function test_quorum_is_majority_of_all_serving(): void
    {
        $this->assertSame(5, ConstitutionalValidator::quorum(9));
        $this->assertSame(5, ConstitutionalValidator::quorum(8));
        $this->assertSame(3, ConstitutionalValidator::quorum(5));
    }

    public function test_seats_range_guard(): void
    {
        $validator = new ConstitutionalValidator();

        $validator->assertSeatsInRange(5);
        $validator->assertSeatsInRange(9);

        $this->expectException(ConstitutionalViolation::class);
        $validator->assertSeatsInRange(10);
    }

    // -------------------------------------------------------------------------
    // ConstitutionalValidator — settings bounds (F-LEG-031)
    // -------------------------------------------------------------------------

    public function test_out_of_range_setting_change_is_rejected_with_citation(): void
    {
        $validator = new ConstitutionalValidator();

        try {
            $validator->check('F-LEG-031', ['setting_key' => 'legislature_max_seats', 'value' => 12]);
            $this->fail('Expected ConstitutionalViolation for legislature_max_seats = 12.');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. II §2', $e->citation);
        }
    }

    public function test_in_range_setting_change_passes_validation(): void
    {
        $validator = new ConstitutionalValidator();

        $validator->check('F-LEG-031', ['setting_key' => 'legislature_max_seats', 'value' => 8]);
        $validator->check('F-LEG-031', ['setting_key' => 'emergency_powers_max_days', 'value' => 30]);

        $this->assertTrue(true); // no exception = pass
    }

    public function test_hardened_voting_method_cannot_regress(): void
    {
        $validator = new ConstitutionalValidator();

        try {
            $validator->check('F-LEG-031', ['setting_key' => 'voting_method', 'value' => 'fptp']);
            $this->fail('Expected ConstitutionalViolation for voting_method = fptp.');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. II §2', $e->citation);
        }
    }

    public function test_non_amendable_key_is_rejected(): void
    {
        $this->expectException(ConstitutionalViolation::class);

        (new ConstitutionalValidator())->check('F-LEG-031', ['setting_key' => 'ballot_secrecy', 'value' => false]);
    }

    public function test_supermajority_fraction_must_exceed_one_half(): void
    {
        $validator = new ConstitutionalValidator();

        try {
            $validator->check('F-LEG-031', [
                'setting_key'               => 'supermajority_numerator',
                'value'                     => 1,
                'supermajority_denominator' => 2,
            ]);
            $this->fail('Expected ConstitutionalViolation for 1/2 supermajority.');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. VII', $e->citation);
        }

        // 3/4 is fine.
        $validator->check('F-LEG-031', [
            'setting_key'               => 'supermajority_numerator',
            'value'                     => 3,
            'supermajority_denominator' => 4,
        ]);
    }

    // -------------------------------------------------------------------------
    // rights.automatic guard
    // -------------------------------------------------------------------------

    public function test_residency_forms_may_never_carry_eligibility_conditions(): void
    {
        $validator = new ConstitutionalValidator();

        try {
            $validator->check('F-IND-003', [
                'jurisdiction_id' => '00000000-0000-0000-0000-000000000000',
                'ping_consent'    => true,
                'qualifications'  => ['property_owner'],
            ]);
            $this->fail('Expected ConstitutionalViolation for eligibility condition on F-IND-003.');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. I', $e->citation);
        }
    }
}
