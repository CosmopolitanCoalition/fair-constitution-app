<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ChamberVote;
use App\Models\Election;
use App\Models\Law;
use App\Models\LawVersion;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\ReferendumQuestion;
use App\Models\User;
use App\Services\ConstitutionalValidator;
use App\Services\EnactmentService;
use App\Services\PetitionService;
use App\Services\ReferendumService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. II §6 / CLK-19 (the referendum same-term
 * shield) + the population peg. Replaces the Phase C placeholder
 * `test_referendum_act_same_term_shield`.
 *
 * Pins (PHASE_C_DESIGN_votes_laws §D + the ReferendumShieldTest spec):
 *  1. The population threshold is DERIVED from the act type — no API ever
 *     accepts a threshold input (source-scanned + DB CHECK).
 *  2. Population thresholds resolve ONLY through the PROTECTED
 *     quorum()/supermajority() functions over the CIVIC population —
 *     absent voters are arithmetically identical to a no.
 *  3. The shield gate (pure matrix): population-supermajority + pending
 *     shield election ⇒ unmodifiable; everything else modifiable (at the
 *     F-LEG-034 chamber supermajority same-term; ordinary path after).
 *  4. A majority-CLASS question passing at population-supermajority
 *     STRENGTH still earns the shield (flagged interpretation — Art. II
 *     §6 shields acts, not question classes; source-scanned).
 *  5. LIVE rolled-back: F-LEG-034 against a shielded law ⇒ rejected with
 *     Art. II §6 + rejected=true chain row; the law_versions writer
 *     refuses legislative_amendment on shielded laws; a majority-passed
 *     act proceeds — at chamber SUPERMAJORITY; releasing the shield
 *     (certification of the shield election) opens the path and converts
 *     the act to ordinary law on the public record.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class ReferendumShieldTest extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_referendum_shield';

    // ======================================================================
    // 1–2. Threshold derivation + the population peg (pure)
    // ======================================================================

    public function test_threshold_is_derived_from_act_type_never_an_input(): void
    {
        $this->assertSame('majority', ReferendumService::deriveThreshold('ordinary'));
        $this->assertSame('majority', ReferendumService::deriveThreshold('setting_change'));
        $this->assertSame('supermajority', ReferendumService::deriveThreshold('supermajority'));

        // No write path accepts a threshold: the service derives it at the
        // two creation points, and neither handler payload carries it.
        $service = file_get_contents(app_path('Services/ReferendumService.php'));
        $this->assertSame(2, substr_count($service, 'self::deriveThreshold('));
        $this->assertStringNotContainsString("\$payload['threshold']", $service);

        foreach (['ReferendumDelegation', 'ReferendumVote', 'PetitionCreation', 'PetitionSignature'] as $handler) {
            $this->assertStringNotContainsString(
                "\$payload['threshold']",
                (string) file_get_contents(app_path("Domain/Forms/Handlers/{$handler}.php")),
                "{$handler} must not accept a threshold input — the engine derives it."
            );
        }
    }

    public function test_population_thresholds_use_the_protected_functions_over_civic_population(): void
    {
        // The peg, by the PROTECTED arithmetic: eligible civic population
        // 10 → majority 6, supermajority 7; 9 → 5 / 6. Absent voters stay
        // in the denominator — they count exactly like a no.
        $this->assertSame(6, ConstitutionalValidator::quorum(10));
        $this->assertSame(7, ConstitutionalValidator::supermajority(10));
        $this->assertSame(5, ConstitutionalValidator::quorum(9));
        $this->assertSame(6, ConstitutionalValidator::supermajority(9));

        // The service performs NO threshold arithmetic of its own.
        $service = file_get_contents(app_path('Services/ReferendumService.php'));
        $this->assertStringContainsString('ConstitutionalValidator::quorum(', $service);
        $this->assertStringContainsString('ConstitutionalValidator::supermajority(', $service);
        $this->assertStringNotContainsString('ceil(', $service);

        // The denominator is the CIVIC population — active associations,
        // never WorldPop (the flagged q-ledger decision).
        $this->assertStringContainsString('CivicPopulation::of', $service);
        $civic = file_get_contents(app_path('Support/CivicPopulation.php'));
        $this->assertStringContainsString('residency_confirmations', $civic);
        $this->assertStringContainsString("where('is_active', true)", $civic);

        // Petition thresholds snapshot the same denominator (CLK-17).
        $this->assertSame(2, PetitionService::thresholdCount(25, 5.00));
        $this->assertSame(1, PetitionService::thresholdCount(10, 5.00));
        $this->assertSame(52, PetitionService::thresholdCount(1031, 5.00));
    }

    // ======================================================================
    // 3. The shield gate (pure matrix)
    // ======================================================================

    public function test_shield_matrix(): void
    {
        // Population supermajority + pending shield election ⇒ immune.
        try {
            ConstitutionalValidator::assertReferendumActModifiable(true, true);
            $this->fail('A shielded act must be unmodifiable (Art. II §6 / CLK-19).');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. II §6', $e->citation);
        }

        // Shield lapsed (election certified) ⇒ modifiable.
        ConstitutionalValidator::assertReferendumActModifiable(true, false);

        // Majority-passed ⇒ modifiable same-term (at chamber supermajority).
        ConstitutionalValidator::assertReferendumActModifiable(false, true);
        ConstitutionalValidator::assertReferendumActModifiable(false, false);
    }

    public function test_supermajority_strength_earns_the_shield_regardless_of_question_class(): void
    {
        // Flagged interpretation (votes_laws §D): Art. II §6 shields "acts
        // passed by population supermajority" — not "supermajority-class
        // questions". The certifier computes the strength REGARDLESS of
        // the question's threshold class, and only that strength anchors
        // the shield election.
        $service = file_get_contents(app_path('Services/ReferendumService.php'));
        $this->assertStringContainsString('$passedBySupermajority = $yes >= $requiredSuper', $service);

        $enactment = file_get_contents(app_path('Services/EnactmentService.php'));
        $this->assertStringContainsString("'referendum_passed_by_supermajority' => \$passedBySupermajority", $enactment);
        $this->assertStringContainsString('$passedBySupermajority ? $shieldElectionId : null', $enactment);

        // Defense in depth: the law_versions writer refuses legislative
        // sources on shielded laws (only a judicial remedy pierces).
        $this->assertStringContainsString('SOURCE_JUDICIAL_REMEDY', $enactment);

        // CLK-19 has NO timer — the gate is the validator rule, consulted
        // at filing time (F-LEG-034 in the engine match).
        $this->assertArrayNotHasKey('CLK-19', \App\Services\ClockService::HANDLERS);
        $validator = file_get_contents(app_path('Services/ConstitutionalValidator.php'));
        $this->assertStringContainsString("'F-LEG-034' => \$this->checkReferendumActModification", $validator);
    }

    // ======================================================================
    // 5. Live rolled-back pins (skipped when pg unreachable)
    // ======================================================================

    public function test_shielded_act_rejects_modification_until_the_shield_election_certifies(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            [$legislature, $memberUser] = $this->liveChamber($conn);

            // The chamber's open successor general = the live shield anchor.
            $shieldElection = Election::query()
                ->where('legislature_id', (string) $legislature->id)
                ->where('kind', Election::KIND_GENERAL)
                ->whereNotIn('status', ['certified', 'audit_rerun', 'final', 'cancelled'])
                ->orderByDesc('created_at')
                ->first();

            if ($shieldElection === null) {
                $this->markTestSkipped('No open successor general election on the live chamber.');
            }

            // ── Throwaway SHIELDED referendum act ─────────────────────────
            $shielded = $this->throwawayLaw($legislature, $shieldElection->id, passedBySupermajority: true);

            $engine = app(ConstitutionalEngine::class);

            // F-LEG-034 ⇒ rejected pre-vote with Art. II §6 + chain row.
            try {
                $engine->file('F-LEG-034', $memberUser, [
                    'law_id'          => (string) $shielded->id,
                    'text'            => 'Amended text.',
                    'jurisdiction_id' => (string) $shielded->jurisdiction_id,
                ]);
                $this->fail('Modifying a shielded act must be rejected (Art. II §6 / CLK-19).');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §6', $e->citation);
            }

            $rejection = $conn->table('audit_log')
                ->where('ref', 'F-LEG-034')
                ->where('rejected', true)
                ->orderByDesc('seq')
                ->first();
            $this->assertNotNull($rejection, 'The CLK-19 rejection must be a first-class chain row.');
            $this->assertStringContainsString('Art. II §6', (string) $rejection->blocked_reason);

            // The version writer refuses legislative_amendment too.
            try {
                app(EnactmentService::class)->amendLaw(
                    $shielded,
                    'Amended text.',
                    LawVersion::SOURCE_LEGISLATIVE_AMENDMENT,
                    'chamber_vote',
                    (string) \Illuminate\Support\Str::uuid(),
                );
                $this->fail('law_versions must refuse legislative_amendment on a shielded law.');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §6', $e->citation);
            }
            $this->assertSame(1, (int) $shielded->refresh()->current_version_no);

            // ── Majority-passed act: proceeds, at chamber SUPERMAJORITY ───
            $majority = $this->throwawayLaw($legislature, $shieldElection->id, passedBySupermajority: false);

            $result = $engine->file('F-LEG-034', $memberUser, [
                'law_id'          => (string) $majority->id,
                'text'            => 'Amended text for the majority-passed act.',
                'jurisdiction_id' => (string) $majority->jurisdiction_id,
            ]);

            $vote = ChamberVote::query()->findOrFail($result->recorded['vote_id']);
            $this->assertSame('referendum_act_modify', $vote->vote_type);
            $this->assertSame(ChamberVote::BASIS_SUPERMAJORITY, $vote->threshold_basis);

            // ── Shield release: certification of the shield election ──────
            app(ReferendumService::class)->releaseShields($shieldElection);

            $shielded->refresh();
            $this->assertNull($shielded->referendum_passed_by_supermajority);
            $this->assertNull($shielded->shield_expires_with_election_id);

            $converted = $conn->table('public_records')
                ->where('subject_type', 'law')
                ->where('subject_id', (string) $shielded->id)
                ->where('title', 'like', '%converted to ordinary law%')
                ->exists();
            $this->assertTrue($converted, 'Shield release publishes the conversion to ordinary law.');

            // The ordinary amendment path is now open (validator gate).
            ConstitutionalValidator::assertReferendumActModifiable(
                (bool) $shielded->referendum_passed_by_supermajority,
                app(ReferendumService::class)->shieldElectionPending($shielded),
            );
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }

            DB::setDefaultConnection($originalDefault);
        }
    }

    // ======================================================================
    // Plumbing (BallotSecrecyTest posture)
    // ======================================================================

    private function throwawayLaw(Legislature $legislature, string $shieldElectionId, bool $passedBySupermajority): Law
    {
        $suffix = substr((string) \Illuminate\Support\Str::uuid(), 0, 8);

        $law = Law::create([
            'jurisdiction_id'                    => (string) $legislature->jurisdiction_id,
            'legislature_id'                     => (string) $legislature->id,
            'act_number'                         => "Act TEST-{$suffix}",
            'title'                              => 'ReferendumShieldTest throwaway act',
            'kind'                               => Law::KIND_REFERENDUM_ACT,
            'scale'                              => [(string) $legislature->jurisdiction_id],
            'origin'                             => Law::ORIGIN_REFERENDUM,
            'origin_ref_type'                    => 'referendum_question',
            'origin_ref_id'                      => (string) \Illuminate\Support\Str::uuid(),
            'referendum_passed_by_supermajority' => $passedBySupermajority,
            'shield_expires_with_election_id'    => $passedBySupermajority ? $shieldElectionId : null,
            'status'                             => Law::STATUS_IN_FORCE,
            'current_version_no'                 => 1,
            'effective_at'                       => now(),
            'enacted_at'                         => now(),
        ]);

        LawVersion::create([
            'law_id'     => $law->id,
            'version_no' => 1,
            'text'       => 'The throwaway referendum act text.',
            'text_hash'  => hash('sha256', 'The throwaway referendum act text.'),
            'source'     => LawVersion::SOURCE_ENACTMENT,
            'created_at' => now(),
        ]);

        return $law;
    }

    /** @return array{0: Legislature, 1: User} */
    private function liveChamber(Connection $conn): array
    {
        $legislature = Legislature::query()
            ->whereNull('deleted_at')
            ->whereHas('members', fn ($q) => $q->whereIn('status', ['elected', 'seated']))
            ->first();

        if ($legislature === null) {
            $this->markTestSkipped('No live chamber with serving members — seed the dev DB first.');
        }

        $member = LegislatureMember::query()
            ->where('legislature_id', (string) $legislature->id)
            ->whereIn('status', ['elected', 'seated'])
            ->when($legislature->speaker_id !== null, fn ($q) => $q->whereKeyNot($legislature->speaker_id))
            ->firstOrFail();

        return [$legislature, User::query()->findOrFail($member->user_id)];
    }

    private function livePg(): Connection
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql not loaded — live pins run inside the app container.');
        }

        config([
            'database.connections.' . self::LIVE_CONNECTION => array_merge(
                config('database.connections.pgsql'),
                ['database' => env('LIVE_PG_DATABASE', 'fair_constitution')]
            ),
        ]);

        try {
            $connection = DB::connection(self::LIVE_CONNECTION);
            $connection->getPdo();

            return $connection;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Live PostgreSQL unreachable — run inside the app container. (' . $e->getMessage() . ')');
        }
    }
}
