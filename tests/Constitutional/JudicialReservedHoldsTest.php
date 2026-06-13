<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\EmergencyPower;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Legislature;
use App\Models\Petition;
use App\Models\ReferendumQuestion;
use App\Models\User;
use App\Services\Judiciary\JudicialSeatService;
use App\Services\PetitionService;
use App\Services\RoleService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the two RESERVED-HOLD wirings Phase E lands:
 *  - F-JDG-007 Emergency Powers Review (Art. II §7 "Emergency Powers are
 *    subject to Judicial review").
 *  - F-JDG-008 Petition Constitutional Review (Art. II §6/§8 — the real review
 *    that supersedes the Phase C stub).
 *
 * Both ride the seated-judge gate (R-19/R-20) and the cases lifecycle. Live-DB
 * sections use the guarded pg connection (the CaseLifecycleTest technique):
 * SKIP when pg is unreachable; every write rides one transaction ALWAYS rolled
 * back.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class JudicialReservedHoldsTest extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_jud_reserved';

    /**
     * F-JDG-007 — a struck power ends immediately (Art. II §7), and a narrowed
     * power records its area/methods. CLK-03 still expires an under_review power
     * at its ceiling (review never extends a power) — pinned structurally by
     * EmergencyPower::LIVE_STATUSES including under_review.
     */
    public function test_emergency_review_struck_ends_the_power_through_the_engine(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        app(RoleService::class)->flush();

        $conn->beginTransaction();

        try {
            $engine = app(ConstitutionalEngine::class);

            [$legislature, $judiciary] = $this->appointedCourt($conn);
            $jurisdictionId = (string) $legislature->jurisdiction_id;
            $judge = $this->aSeatedJudge($judiciary);

            $power = $this->throwawayPower($conn, $legislature);
            $this->assertSame(EmergencyPower::STATUS_ACTIVE, (string) $power->status);

            $reviewed = $engine->file('F-JDG-007', $judge, [
                'reviewed_power_id' => (string) $power->id,
                'judiciary_id' => (string) $judiciary->id,
                'jurisdiction_id' => $jurisdictionId,
                'review_basis' => 'methods',
                'outcome' => 'struck',
                'opinion_text' => 'The power\'s methods breach constitutional order (throwaway).',
            ]);

            $power->refresh();
            $this->assertSame(EmergencyPower::STATUS_STRUCK, (string) $power->status, 'Art. II §7 — a struck power ends immediately.');
            $this->assertSame('struck', (string) $power->review_outcome);
            $this->assertNotNull($power->judicial_review_case_id, 'The review opens a cases row.');

            // The emergency_power_reviews row is written with the disposition.
            $reviewRow = \App\Models\EmergencyPowerReview::query()->find($reviewed->recorded['review_id']);
            $this->assertNotNull($reviewRow);
            $this->assertSame('struck', (string) $reviewRow->outcome);
            $this->assertSame('methods', (string) $reviewRow->review_basis);

            // The reviewed chain entry is sealed (not a rejection).
            $entry = $conn->table('audit_log')
                ->where('event', 'emergency.reviewed')
                ->where('payload->emergency_power_id', (string) $power->id)
                ->first();
            $this->assertNotNull($entry, 'Art. II §7 — the review seals a chain entry.');
            $this->assertFalse((bool) $entry->rejected);

            // under_review is a LIVE status — CLK-03 still reaches such a power
            // (review never extends it past the ceiling).
            $this->assertContains(EmergencyPower::STATUS_UNDER_REVIEW, EmergencyPower::LIVE_STATUSES);
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
            app(RoleService::class)->flush();
        }
    }

    /**
     * F-JDG-008 — a struck petition is invalidated with NO referendum queued
     * (the kill-path the petition model names); a cleared petition validates and
     * queues onward to the ballot. The Phase C stub is unreachable once an
     * active court exists.
     */
    public function test_petition_review_struck_and_cleared_through_the_engine(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        app(RoleService::class)->flush();

        $conn->beginTransaction();

        try {
            $engine = app(ConstitutionalEngine::class);

            [$legislature, $judiciary] = $this->appointedCourt($conn);
            $jurisdictionId = (string) $legislature->jurisdiction_id;
            $judge = $this->aSeatedJudge($judiciary);

            // ── struck → invalidated, NO referendum ──────────────────────────
            $struckPetition = $this->heldPetition($conn, $jurisdictionId);
            $engine->file('F-JDG-008', $judge, [
                'petition_id' => (string) $struckPetition->id,
                'judiciary_id' => (string) $judiciary->id,
                'jurisdiction_id' => $jurisdictionId,
                'outcome' => 'struck',
                'opinion_text' => 'The proposed law contradicts the Constitution (throwaway).',
                'contradiction_citation' => 'Art. I',
            ]);

            $struckPetition->refresh();
            $this->assertSame(Petition::STATUS_INVALIDATED, (string) $struckPetition->status, 'Art. II §6 — a struck petition is invalidated.');
            $this->assertSame('struck', (string) $struckPetition->review_outcome);
            $this->assertSame(
                0,
                ReferendumQuestion::query()->where('petition_id', (string) $struckPetition->id)->count(),
                'Art. II §6 — a struck petition queues NO referendum (the kill-path).'
            );

            // ── cleared → validated → queued ─────────────────────────────────
            $clearedPetition = $this->heldPetition($conn, $jurisdictionId);
            $engine->file('F-JDG-008', $judge, [
                'petition_id' => (string) $clearedPetition->id,
                'judiciary_id' => (string) $judiciary->id,
                'jurisdiction_id' => $jurisdictionId,
                'outcome' => 'cleared',
                'opinion_text' => 'The proposed law is constitutional (throwaway).',
            ]);

            $clearedPetition->refresh();
            $this->assertSame(Petition::STATUS_VALIDATED, (string) $clearedPetition->status, 'Art. II §6 — a cleared petition validates.');
            $this->assertSame('cleared', (string) $clearedPetition->review_outcome);
            $this->assertSame(
                1,
                ReferendumQuestion::query()->where('petition_id', (string) $clearedPetition->id)->count(),
                'Art. II §6 — a cleared petition queues its referendum.'
            );

            // The Phase C stub is UNREACHABLE when an active court exists.
            $stubPetition = $this->heldPetition($conn, $jurisdictionId);
            try {
                app(PetitionService::class)->stubConstitutionalReview($stubPetition);
                $this->fail('The Phase C stub must be barred when an active court exists (Art. II §6).');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §6', $e->citation, 'Art. II §6 — production uses F-JDG-008, not the stub.');
            }
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
            app(RoleService::class)->flush();
        }
    }

    // ======================================================================
    // Helpers (shared with Art4Section5Test posture)
    // ======================================================================

    /** A throwaway ACTIVE emergency power (with a minimal invoke vote). */
    private function throwawayPower(Connection $conn, Legislature $legislature): EmergencyPower
    {
        $jurisdictionId = (string) $legislature->jurisdiction_id;

        $voteId = (string) Str::uuid();
        $conn->table('chamber_votes')->insert([
            'id' => $voteId,
            'body_type' => 'legislature',
            'body_id' => (string) $legislature->id,
            'legislature_id' => (string) $legislature->id,
            'jurisdiction_id' => $jurisdictionId,
            'vote_type' => 'emergency_declare',
            'vote_method' => 'yes_no',
            'threshold_basis' => 'supermajority',
            'stage' => 'floor',
            'bicameral' => false,
            'serving_snapshot' => 5,
            'speaker_tiebreak' => false,
            'opened_at' => now(),
            'status' => 'closed',
            'outcome' => 'adopted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return EmergencyPower::create([
            'id' => (string) Str::uuid(),
            'legislature_id' => (string) $legislature->id,
            'jurisdiction_id' => $jurisdictionId,
            'cause' => EmergencyPower::CAUSE_NATURAL_DISASTER,
            'label' => 'Throwaway emergency '.Str::random(6),
            'declared_duration_days' => 30,
            'area_jurisdiction_id' => $jurisdictionId,
            'methods' => json_encode(['curfew']),
            'invoke_vote_id' => $voteId,
            'status' => EmergencyPower::STATUS_ACTIVE,
            'starts_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }

    /** A petition HELD at constitutional_review (the F-JDG-008 entry state). */
    private function heldPetition(Connection $conn, string $jurisdictionId): Petition
    {
        $creator = $this->anAssociatedUser($jurisdictionId);

        return Petition::create([
            'id' => (string) Str::uuid(),
            'creator_user_id' => (string) $creator->id,
            'jurisdiction_id' => $jurisdictionId,
            'title' => 'Throwaway petition '.Str::random(6),
            'law_text' => 'Proposed law text (throwaway).',
            'act_type' => 'ordinary',
            'scale' => [$jurisdictionId],
            'population_basis' => 100,
            'threshold_pct' => 5.00,
            'threshold_count' => 5,
            'status' => Petition::STATUS_CONSTITUTIONAL_REVIEW,
            'review_stub' => false,
        ]);
    }

    /**
     * @return array{0: Legislature, 1: Judiciary}
     */
    private function appointedCourt(Connection $conn): array
    {
        $judiciaries = Judiciary::query()->where('status', Judiciary::STATUS_FORMING)->get();

        foreach ($judiciaries as $judiciary) {
            $legislature = Legislature::query()
                ->where('jurisdiction_id', $judiciary->jurisdiction_id)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'dissolved')
                ->whereNotNull('term_ends_on')
                ->first();

            if ($legislature === null
                || ! $legislature->members()->whereIn('status', ['elected', 'seated'])->exists()) {
                continue;
            }

            $constituents = \App\Services\Judiciary\JudiciaryFormationService::constituentJurisdictionIds($legislature);

            $associated = $conn->table('residency_confirmations')
                ->where('jurisdiction_id', (string) $legislature->jurisdiction_id)
                ->where('is_active', true)
                ->count();

            if ($associated < 8) {
                continue;
            }

            try {
                $this->driveToAppointed(app(ConstitutionalEngine::class), $legislature, $judiciary, $constituents);
            } catch (\Throwable $e) {
                continue;
            }

            return [$legislature, $judiciary->refresh()];
        }

        $this->markTestSkipped('Live DB has no forming judiciary with a chamber + an ≥8-resident pool — seed San Marino.');
    }

    /** @param list<string> $constituentIds */
    private function driveToAppointed(
        ConstitutionalEngine $engine,
        Legislature $legislature,
        Judiciary $judiciary,
        array $constituentIds,
    ): void {
        $jurisdictionId = (string) $legislature->jurisdiction_id;

        $payload = [
            'legislature_id' => (string) $legislature->id,
            'jurisdiction_id' => $jurisdictionId,
            'court_name' => 'JudicialReservedHoldsTest throwaway court',
            'function_text' => 'Throwaway — appointed court.',
        ];

        if ($constituentIds !== []) {
            $payload['judges_per_constituent'] = 5;
        } else {
            $payload['committee_judge_count'] = 5;
        }

        $proposed = $engine->file('F-LEG-017', $this->nonSpeakerUser($legislature), $payload);
        $this->castAllYes($engine, $legislature, $proposed->recorded['vote_id']);

        $seats = JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->where('status', JudicialSeat::STATUS_VACANT)
            ->orderBy('seat_number')
            ->get();

        $nominees = $this->associatedUsers($jurisdictionId, $seats->count());
        $seatService = app(JudicialSeatService::class);

        foreach ($seats as $i => $seat) {
            $out = $constituentIds !== []
                ? $seatService->nominate($seat, (string) $nominees[$i]->id, (string) $seat->nominating_jurisdiction_id)
                : $seatService->committeeNominate($seat, (string) $nominees[$i]->id);

            $this->castAllYes($engine, $legislature, $out['consent_vote_id']);
        }
    }

    private function aSeatedJudge(Judiciary $judiciary): User
    {
        $seat = JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->where('status', JudicialSeat::STATUS_SEATED)
            ->firstOrFail();

        $judge = User::query()->findOrFail((string) $seat->user_id);
        app(RoleService::class)->flushUser((string) $judge->id);

        return $judge;
    }

    private function anAssociatedUser(string $jurisdictionId): User
    {
        $id = DB::table('residency_confirmations')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->value('user_id');

        if ($id === null) {
            $this->markTestSkipped('No associated user — seed residents.');
        }

        return User::query()->findOrFail((string) $id);
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function associatedUsers(string $jurisdictionId, int $n)
    {
        $ids = DB::table('residency_confirmations')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->limit($n)
            ->pluck('user_id')->map(fn ($id) => (string) $id)->all();

        if (count($ids) < $n) {
            $this->markTestSkipped("Live DB has fewer than {$n} associated users — seed residents.");
        }

        return User::query()->whereIn('id', $ids)->get()->values();
    }

    private function nonSpeakerUser(Legislature $legislature): User
    {
        $member = \App\Models\LegislatureMember::query()
            ->where('legislature_id', (string) $legislature->id)
            ->whereIn('status', ['elected', 'seated'])
            ->when($legislature->speaker_id !== null, fn ($q) => $q->whereKeyNot($legislature->speaker_id))
            ->firstOrFail();

        return User::query()->findOrFail($member->user_id);
    }

    private function castAllYes(ConstitutionalEngine $engine, Legislature $legislature, string $voteId): void
    {
        $members = \App\Models\LegislatureMember::query()
            ->where('legislature_id', (string) $legislature->id)
            ->whereIn('status', ['elected', 'seated'])
            ->when($legislature->speaker_id !== null, fn ($q) => $q->whereKeyNot($legislature->speaker_id))
            ->get();

        foreach ($members as $member) {
            if (\App\Models\ChamberVote::query()->whereKey($voteId)->value('status') !== \App\Models\ChamberVote::STATUS_OPEN) {
                break;
            }

            $engine->file('F-LEG-004', User::query()->findOrFail($member->user_id), [
                'vote_id' => $voteId,
                'value' => 'yes',
            ]);
        }
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
