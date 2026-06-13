<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\FormRegistry;
use App\Jobs\Clocks\ExpireEmergencyPowerJob;
use App\Models\ChamberVote;
use App\Models\EmergencyPower;
use App\Services\ClockService;
use App\Services\ConstitutionalValidator;
use App\Services\EmergencyPowerService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. II §7 (emergency powers). Replaces the Phase C
 * placeholder `test_emergency_powers_ceiling_and_civic_process_protection`.
 *
 * Pins (PHASE_C_DESIGN_votes_laws §F + the EmergencyCeilingTest spec):
 *  1. PURE guards: the cause enum is CLOSED (disaster/invasion only); the
 *     duration ceiling is min(90, resolved max) for declarations AND each
 *     renewal alike; the renewal window arithmetic.
 *  2. Civic-process shield (engine-level, three mechanisms): the
 *     EMERGENCY_PROTECTED_FORMS list is pinned verbatim; protected forms
 *     reject emergency-citing payload keys pre-commit; NO form outside the
 *     (empty) EMERGENCY_ENABLED_FORMS allowlist may cite a power as
 *     enabling authority; no protected form's HANDLER reads emergency
 *     state (source-scanned).
 *  3. Structural absence: no service exposes an election/session/clock
 *     deferral API (reflection over the public surfaces); CLK-03 is
 *     excluded from re-derivation; there is no auto-renewal path.
 *  4. LIVE rolled-back pins (guarded pg connection, BallotSecrecyTest
 *     posture — skipped when pg unreachable): cause 'economic_crisis' and
 *     duration 91 are rejected PRE-VOTE with Art. II §7 + a rejected=true
 *     chain row; renewing an EXPIRED power is rejected; a CLK-03 fire
 *     flips active → expired with the audit trail.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class EmergencyCeilingTest extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_emergency_ceiling';

    // ======================================================================
    // 1. Pure Art. II §7 guards
    // ======================================================================

    public function test_cause_enum_is_closed_to_disaster_and_invasion(): void
    {
        EmergencyPowerService::assertCause('natural_disaster');
        EmergencyPowerService::assertCause('actual_invasion');

        foreach (['economic_crisis', 'political_unrest', 'public_order', 'pandemic', ''] as $cause) {
            try {
                EmergencyPowerService::assertCause($cause);
                $this->fail("Cause [{$cause}] must be rejected — the enum is closed (Art. II §7).");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §7', $e->citation);
            }
        }

        // The model constant is the closed enum itself.
        $this->assertSame(['natural_disaster', 'actual_invasion'], EmergencyPower::CAUSES);
    }

    public function test_duration_ceiling_binds_declarations_and_renewals(): void
    {
        EmergencyPowerService::assertDuration(1, 90);
        EmergencyPowerService::assertDuration(90, 90);
        EmergencyPowerService::assertDuration(30, 90);

        // 91 breaches the hardened ceiling.
        try {
            EmergencyPowerService::assertDuration(91, 90);
            $this->fail('91 days must breach the 90-day ceiling.');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. II §7', $e->citation);
            $this->assertStringContainsString('Rejected pre-vote', $e->getMessage());
        }

        // Zero/negative durations are not durations.
        $this->expectException(ConstitutionalViolation::class);
        EmergencyPowerService::assertDuration(0, 90);
    }

    public function test_resolved_max_lowers_but_never_raises_the_ceiling(): void
    {
        // A lowered amendable max binds new declarations…
        try {
            EmergencyPowerService::assertDuration(45, 30);
            $this->fail('45 days must breach a resolved 30-day maximum.');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. II §7', $e->citation);
        }

        EmergencyPowerService::assertDuration(30, 30);

        // …and a (hypothetically corrupt) raised max NEVER pierces 90.
        $this->expectException(ConstitutionalViolation::class);
        EmergencyPowerService::assertDuration(91, 120);
    }

    public function test_renewal_window_arithmetic(): void
    {
        $expires = \Carbon\CarbonImmutable::parse('2031-06-30T00:00:00Z');

        // Mockup grammar: maxDays 90 → "renewal window opens day 76" =
        // expiry − 14 days.
        $this->assertSame(
            '2031-06-16T00:00:00+00:00',
            EmergencyPowerService::renewalWindowOpensAt($expires, 14)->toIso8601String()
        );
    }

    // ======================================================================
    // 2. Civic-process shield (Art. II §7 — engine-level)
    // ======================================================================

    public function test_protected_forms_list_is_pinned(): void
    {
        // This list may only ever GROW, under constitutional review.
        $expected = array_merge(
            array_map(fn ($n) => sprintf('F-IND-%03d', $n), range(1, 17)),
            ['F-CAN-001', 'F-CAN-002', 'F-CAN-003'],
            array_map(fn ($n) => sprintf('F-ELB-%03d', $n), range(1, 6)),
            ['F-SPK-001', 'F-SPK-003'],
            ['F-LEG-002', 'F-LEG-004', 'F-LEG-005', 'F-LEG-036'],
            array_map(fn ($n) => sprintf('F-JDG-%03d', $n), range(1, 10)),
        );

        $this->assertSame($expected, ConstitutionalValidator::EMERGENCY_PROTECTED_FORMS);

        // Phase D registers the executive branch as the ONLY
        // emergency-enabled forms (constitutional review,
        // PHASE_D_DESIGN_executive §D): orders (F-EXE-005) and department
        // rules (F-BOG-001) may cite an ACTIVE power — bounded to its
        // declared area + duration by the order scope rules, with
        // emergency-enabled rules expiring with the power (CLK-03
        // cascade). The civic-process shield above is untouched: even an
        // enabled form can never reach a protected process.
        $this->assertSame(['F-BOG-001', 'F-EXE-005'], ConstitutionalValidator::EMERGENCY_ENABLED_FORMS);
    }

    public function test_protected_forms_reject_emergency_citing_payloads(): void
    {
        // A ballot filed "under" an emergency power: rejected pre-commit.
        foreach (['emergency_power_id', 'enabling_type', 'enabling_ref'] as $key) {
            try {
                ConstitutionalValidator::assertEmergencyCivicProcessShield('F-IND-007', [$key], null);
                $this->fail("Protected form carrying [{$key}] must be rejected (Art. II §7).");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §7', $e->citation);
            }
        }

        // The same filings WITHOUT emergency keys pass the shield — the
        // civic process is byte-identical under any emergency.
        ConstitutionalValidator::assertEmergencyCivicProcessShield('F-IND-007', ['race_id', 'rankings'], null);
        ConstitutionalValidator::assertEmergencyCivicProcessShield('F-IND-009', ['jurisdiction_id', 'title', 'law_text'], null);
        ConstitutionalValidator::assertEmergencyCivicProcessShield('F-ELB-001', ['legislature_id'], null);
        ConstitutionalValidator::assertEmergencyCivicProcessShield('F-SPK-001', ['legislature_id', 'scheduled_for'], null);

        // F-LEG-025 (renewal) legitimately names its power — NOT protected.
        ConstitutionalValidator::assertEmergencyCivicProcessShield('F-LEG-025', ['emergency_power_id', 'extension_days'], null);

        // Phase D opened a NARROW door: the emergency-enabled executive forms
        // (F-EXE-005 order, F-BOG-001 department rule) MAY cite an active
        // power as enabling authority — the shield lets exactly these pass
        // (bounded elsewhere to the power's declared area + duration).
        ConstitutionalValidator::assertEmergencyCivicProcessShield('F-EXE-005', ['enabling_type'], 'emergency_power');
        ConstitutionalValidator::assertEmergencyCivicProcessShield('F-BOG-001', ['enabling_type'], 'emergency_power');

        // Forward rule (unchanged): every form OUTSIDE that allowlist is
        // still refused — no undeclared handler may cite a power as enabling
        // authority. F-LEG-014 (delegation) is neither protected nor enabled.
        $this->expectException(ConstitutionalViolation::class);
        ConstitutionalValidator::assertEmergencyCivicProcessShield('F-LEG-014', ['enabling_type'], 'emergency_power');
    }

    public function test_no_protected_form_handler_reads_emergency_state(): void
    {
        // Architecture pin: the handler of every PROTECTED form must not
        // even mention emergency powers — invariance by construction.
        foreach (ConstitutionalValidator::EMERGENCY_PROTECTED_FORMS as $formId) {
            $handler = FormRegistry::handlerFor($formId);

            if ($handler === null) {
                continue; // Phase D/E forms — handlers do not exist yet
            }

            $source = file_get_contents((new \ReflectionClass($handler))->getFileName());

            $this->assertDoesNotMatchRegularExpression(
                '/EmergencyPower|emergency_powers/i',
                $source,
                "{$formId} handler ({$handler}) reads emergency-power state — Art. II §7 violation."
            );
        }
    }

    // ======================================================================
    // 3. Structural absence — no deferral API exists anywhere
    // ======================================================================

    public function test_no_service_exposes_a_deferral_or_suspension_api(): void
    {
        $surfaces = [
            ClockService::class,
            \App\Services\ElectionLifecycleService::class,
            \App\Services\SessionService::class,
            \App\Services\VacancyService::class,
            EmergencyPowerService::class,
        ];

        foreach ($surfaces as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $this->assertDoesNotMatchRegularExpression(
                    '/defer|postpone|suspend|pause|skip|delay|reschedule|extend/i',
                    $method->getName(),
                    "{$class}::{$method->getName()}() looks like a deferral API — emergency powers must have nothing to invoke."
                );
            }
        }

        // ClockService's write surface is exactly arm/fire/cancel — there
        // is no API that MOVES an armed timer.
        $clockMethods = array_map(
            fn (\ReflectionMethod $m) => $m->getName(),
            (new \ReflectionClass(ClockService::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        $this->assertEqualsCanonicalizing(['__construct', 'arm', 'fire', 'cancel', 'resolvedInt'], $clockMethods);

        // CLK-03 is excluded from re-derivation: an active power keeps its
        // DECLARED duration (Art. II §7) — and the power's own timer arms
        // with derive NULL (non-re-derivable by construction).
        $rederivation = file_get_contents(app_path('Services/ClockRederivationService.php'));
        $this->assertMatchesRegularExpression("/reject\\(.*'CLK-03'/s", $rederivation);

        $powerService = file_get_contents(app_path('Services/EmergencyPowerService.php'));
        $this->assertStringContainsString("'derive' => null", $powerService);

        // No auto-renewal path: every renewal flows through a FRESH
        // supermajority proposal/vote pair.
        $this->assertStringNotContainsString('auto_renew', $powerService);
        $this->assertStringContainsString('emergency_renew', $powerService);
    }

    // ======================================================================
    // 4. Live rolled-back pins (skipped when pg unreachable)
    // ======================================================================

    public function test_invoke_and_renewal_rejections_chain_and_clk03_expires(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            [$legislature, $memberUser] = $this->liveChamber($conn);

            $engine = app(ConstitutionalEngine::class);

            $base = [
                'legislature_id' => (string) $legislature->id,
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'label' => 'EmergencyCeilingTest throwaway',
                'methods' => 'Coordinate relief within constitutional order.',
            ];

            // ── cause outside the closed enum → rejected + chain row ──────
            try {
                $engine->file('F-LEG-024', $memberUser, $base + ['cause' => 'economic_crisis', 'duration_days' => 30]);
                $this->fail('economic_crisis must be rejected pre-vote (Art. II §7).');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §7', $e->citation);
            }

            $rejection = $conn->table('audit_log')
                ->where('ref', 'F-LEG-024')
                ->where('rejected', true)
                ->orderByDesc('seq')
                ->first();

            $this->assertNotNull($rejection, 'The rejection must be a first-class chain row.');
            $this->assertStringContainsString('Art. II §7', (string) $rejection->blocked_reason);

            // ── duration 91 → rejected pre-vote ───────────────────────────
            try {
                $engine->file('F-LEG-024', $memberUser, $base + ['cause' => 'natural_disaster', 'duration_days' => 91]);
                $this->fail('91 days must be rejected pre-vote (Art. II §7).');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §7', $e->citation);
            }

            // ── a lawful invoke opens a SUPERMAJORITY vote, no power row ──
            $result = $engine->file('F-LEG-024', $memberUser, $base + ['cause' => 'natural_disaster', 'duration_days' => 30]);

            $vote = ChamberVote::query()->findOrFail($result->recorded['vote_id']);
            $this->assertSame('emergency_invoke', $vote->vote_type);
            $this->assertSame(ChamberVote::BASIS_SUPERMAJORITY, $vote->threshold_basis);
            $this->assertSame(0, EmergencyPower::query()->where('label', $base['label'])->count(),
                'The power row exists only on ADOPTION — a pending vote creates nothing.');

            // ── throwaway ACTIVE power: CLK-03 fire flips → expired ───────
            $power = EmergencyPower::create([
                'legislature_id' => (string) $legislature->id,
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'cause' => 'natural_disaster',
                'label' => 'EmergencyCeilingTest expiry fixture',
                'declared_duration_days' => 1,
                'area_jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'methods' => 'n/a',
                'invoke_vote_id' => (string) $vote->id,
                'status' => EmergencyPower::STATUS_ACTIVE,
                'starts_at' => now()->subDays(2),
                'expires_at' => now()->subDay(),
            ]);

            $timer = app(ClockService::class)->arm(
                'CLK-03',
                (string) $legislature->jurisdiction_id,
                'emergency_power',
                (string) $power->id,
                $power->expires_at,
                ['derive' => null],
            );

            $this->assertTrue(app(ClockService::class)->fire($timer));
            (new ExpireEmergencyPowerJob($timer->id))->handle(app(EmergencyPowerService::class));

            $this->assertSame(EmergencyPower::STATUS_EXPIRED, $power->refresh()->status);

            $expiredEntry = $conn->table('audit_log')
                ->where('event', 'emergency.expired')
                ->where('payload->emergency_power_id', (string) $power->id)
                ->exists();
            $this->assertTrue($expiredEntry, 'Auto-expiry must chain a full audit entry (nothing rolls over silently).');

            // Idempotent: a second job run changes nothing.
            (new ExpireEmergencyPowerJob($timer->id))->handle(app(EmergencyPowerService::class));
            $this->assertSame(EmergencyPower::STATUS_EXPIRED, $power->refresh()->status);

            // ── renewal of an EXPIRED power → rejected ────────────────────
            try {
                $engine->file('F-LEG-025', $memberUser, [
                    'emergency_power_id' => (string) $power->id,
                    'extension_days' => 30,
                    'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                ]);
                $this->fail('Renewing an expired power must be rejected (Art. II §7).');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §7', $e->citation);
            }
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

    /** @return array{0: \App\Models\Legislature, 1: \App\Models\User} */
    private function liveChamber(Connection $conn): array
    {
        $legislature = \App\Models\Legislature::query()
            ->whereNull('deleted_at')
            ->where('type_b_seats', 0)
            ->whereHas('members', fn ($q) => $q->whereIn('status', ['elected', 'seated']))
            ->first();

        if ($legislature === null) {
            $this->markTestSkipped('No live unicameral chamber with serving members — seed the dev DB first.');
        }

        // A serving NON-SPEAKER member (the Speaker cannot file votes that
        // they then cannot cast in; any R-09 may file F-LEG-024).
        $member = \App\Models\LegislatureMember::query()
            ->where('legislature_id', (string) $legislature->id)
            ->whereIn('status', ['elected', 'seated'])
            ->when($legislature->speaker_id !== null, fn ($q) => $q->whereKeyNot($legislature->speaker_id))
            ->firstOrFail();

        return [$legislature, \App\Models\User::query()->findOrFail($member->user_id)];
    }

    private function livePg(): Connection
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql not loaded — live pins run inside the app container.');
        }

        config([
            'database.connections.'.self::LIVE_CONNECTION => array_merge(
                config('database.connections.pgsql'),
                ['database' => env('LIVE_PG_DATABASE', 'fair_constitution')]
            ),
        ]);

        try {
            $connection = DB::connection(self::LIVE_CONNECTION);
            $connection->getPdo();

            return $connection;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Live PostgreSQL unreachable — run inside the app container. ('.$e->getMessage().')');
        }
    }
}
