<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\CourtCase;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Services\ConstitutionalValidator;
use App\Services\Judiciary\CaseService;
use App\Services\Judiciary\JudicialSeatService;
use App\Services\RoleService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Article II §8 (Double Jeopardy). "Any Individual who
 * has been prosecuted for a criminal act cannot be prosecuted for that same
 * act again. All other Judgements can be overturned only by proven
 * contradictions in law and errors found in the cases that cause invalid
 * Judgements."
 *
 * Two-layer hardening:
 *   1. the pre-commit validator rule rejects a criminal re-filing with the
 *      Art. II §8 citation and the engine records the rejected=true chain row;
 *   2. cases.double_jeopardy_locked is the persisted fact, set ATOMICALLY with
 *      the criminal verdict.
 *
 * A CIVIL re-filing on the same facts is NOT barred (double jeopardy is
 * criminal-only) — the contrast assertion.
 *
 * Live-DB sections use the guarded pg connection; every write rides one
 * transaction that is ALWAYS rolled back. If an edit breaks these tests, that
 * edit is a constitutional violation — fix the edit, never the test.
 */
class DoubleJeopardyTest extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_double_jeopardy';

    // ======================================================================
    // 1. Pure Art. II §8 pin (DB-free, always run)
    // ======================================================================

    /**
     * The pure assert: a CRIMINAL re-filing against an accused with a prior
     * terminal criminal verdict throws Art. II §8; every other combination
     * passes silently (civil never bars; no prior never bars).
     */
    public function test_assert_no_double_jeopardy_bars_only_a_criminal_reprosecution(): void
    {
        // The single barred case: criminal AND a prior terminal criminal verdict.
        try {
            ConstitutionalValidator::assertNoDoubleJeopardy(true, true);
            $this->fail('A criminal re-prosecution must be barred (Art. II §8).');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. II §8', $e->citation);
        }

        // Every other combination passes — including a CIVIL re-filing on the
        // same facts (double jeopardy is criminal-only).
        ConstitutionalValidator::assertNoDoubleJeopardy(false, true);  // civil, prior exists → OK
        ConstitutionalValidator::assertNoDoubleJeopardy(true, false);  // criminal, no prior → OK
        ConstitutionalValidator::assertNoDoubleJeopardy(false, false); // civil, no prior → OK
        $this->addToAssertionCount(3);
    }

    // ======================================================================
    // 2. LIVE engine-filed E2E (the hardened exit; rolled back)
    // ======================================================================

    /**
     * THE hardened exit (exit criterion #3 tail) end to end through the engine:
     * a criminal case reaches a terminal verdict → double_jeopardy_locked=true;
     * a second F-IND-017 against the SAME accused for the SAME act is rejected
     * PRE-COMMIT with the Art. II §8 citation, the rejection is on the audit
     * chain (rejected=true), and NO second cases row is created. The first
     * verdict is untouched. A CIVIL re-filing on the same accused is NOT barred.
     */
    public function test_criminal_reprosecution_rejected_on_record_and_civil_is_not_barred(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        app(RoleService::class)->flush();

        $conn->beginTransaction();

        try {
            $engine = app(ConstitutionalEngine::class);
            $cases = app(CaseService::class);

            [$legislature, $judiciary] = $this->appointedCourt($conn);
            $jurisdictionId = (string) $legislature->jurisdiction_id;

            $judge = User::query()->findOrFail(
                (string) JudicialSeat::query()->where('judiciary_id', $judiciary->id)->where('status', JudicialSeat::STATUS_SEATED)->first()->user_id
            );

            [$complainant, $accused] = $this->twoFreeUsers($jurisdictionId);

            // ── A criminal case all the way to a terminal verdict ────────────
            $filed = $engine->file('F-IND-017', $complainant, [
                'judiciary_id' => (string) $judiciary->id,
                'jurisdiction_id' => $jurisdictionId,
                'kind' => 'criminal',
                'title' => 'State v. DoubleJeopardyTest throwaway',
                'claimed_severity' => 'serious',
                'accused_user_id' => (string) $accused->id,
            ]);

            $case = CourtCase::query()->findOrFail($filed->recorded['case_id']);

            $engine->file('F-JDG-001', $judge, ['case_id' => (string) $case->id, 'court_severity' => 'minor']);
            $cases->advanceToHearing($case->refresh());
            $cases->enterDeliberation($case->refresh());
            $cases->recordVerdict($case->refresh(), ['decided_by' => 'jury', 'outcome' => 'not_guilty', 'jury_unanimous' => true]);

            $case->refresh();
            $this->assertTrue((bool) $case->double_jeopardy_locked, 'Art. II §8 — the criminal verdict locks double jeopardy.');

            $countBefore = CourtCase::query()->where('judiciary_id', (string) $judiciary->id)->where('kind', 'criminal')->count();
            $seqBefore = (int) $conn->table('audit_log')->max('seq');

            // ── A second criminal filing against the SAME accused is BARRED ──
            try {
                $engine->file('F-IND-017', $complainant, [
                    'judiciary_id' => (string) $judiciary->id,
                    'jurisdiction_id' => $jurisdictionId,
                    'kind' => 'criminal',
                    'title' => 'State v. DoubleJeopardyTest throwaway (re-prosecution)',
                    'accused_user_id' => (string) $accused->id,
                ]);
                $this->fail('A criminal re-prosecution of the same act must be rejected (Art. II §8).');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §8', $e->citation, 'The re-prosecution rejection cites Art. II §8.');
            }

            // NO second criminal cases row was created.
            $this->assertSame(
                $countBefore,
                CourtCase::query()->where('judiciary_id', (string) $judiciary->id)->where('kind', 'criminal')->count(),
                'Art. II §8 — no second criminal cases row is created on a barred re-filing.'
            );

            // The rejection is on the audit chain (rejected=true) with the citation.
            $rejected = $conn->table('audit_log')
                ->where('seq', '>', $seqBefore)
                ->where('ref', 'F-IND-017')
                ->where('rejected', true)
                ->orderByDesc('seq')
                ->first();
            $this->assertNotNull($rejected, 'Art. II §8 — the barred re-filing seals a rejected=true chain row.');
            $this->assertStringContainsString('Art. II §8', (string) $rejected->blocked_reason, 'The rejected row carries the verbatim citation.');

            // The first verdict is untouched.
            $this->assertSame(
                1,
                \App\Models\Verdict::query()->where('case_id', (string) $case->id)->count(),
                'The first verdict is untouched by the barred re-filing.'
            );

            // ── A CIVIL re-filing on the SAME accused is NOT barred ──────────
            $civil = $engine->file('F-IND-017', $complainant, [
                'judiciary_id' => (string) $judiciary->id,
                'jurisdiction_id' => $jurisdictionId,
                'kind' => 'civil',
                'title' => 'Plaintiff v. Same defendant (civil — not barred)',
                'accused_user_id' => (string) $accused->id,
            ]);

            $civilCase = CourtCase::query()->findOrFail($civil->recorded['case_id']);
            $this->assertSame(CourtCase::STATUS_FILED, $civilCase->status, 'Art. II §8 — a civil re-filing on the same facts is NOT barred (criminal-only).');
            $this->assertSame('civil', (string) $civilCase->kind);
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
            app(RoleService::class)->flush();
        }
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    /** @return array{0: Legislature, 1: Judiciary} */
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

            $associated = $conn->table('residency_confirmations')
                ->where('jurisdiction_id', (string) $legislature->jurisdiction_id)
                ->where('is_active', true)
                ->count();

            if ($associated < 8) {
                continue;
            }

            $constituents = \App\Services\Judiciary\JudiciaryFormationService::constituentJurisdictionIds($legislature);

            try {
                $this->driveToAppointed(app(ConstitutionalEngine::class), $legislature, $judiciary, $constituents);
            } catch (\Throwable $e) {
                continue;
            }

            return [$legislature, $judiciary->refresh()];
        }

        $this->markTestSkipped('Live DB has no forming judiciary with a chamber + an ≥8-resident pool — seed San Marino.');
    }

    /** @param  list<string>  $constituentIds */
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
            'court_name' => 'DoubleJeopardyTest throwaway court',
            'function_text' => 'DoubleJeopardyTest throwaway — appointed court.',
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

    /** @return array{0: User, 1: User} two associated users not seated as judges. */
    private function twoFreeUsers(string $jurisdictionId): array
    {
        $seatedJudgeIds = JudicialSeat::query()
            ->where('status', JudicialSeat::STATUS_SEATED)
            ->pluck('user_id')->filter()->map(fn ($id) => (string) $id)->all();

        $ids = DB::table('residency_confirmations')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->whereNotIn('user_id', $seatedJudgeIds)
            ->limit(2)
            ->pluck('user_id')->map(fn ($id) => (string) $id)->all();

        if (count($ids) < 2) {
            $this->markTestSkipped('Live DB has fewer than two free associated users — seed residents.');
        }

        return [User::query()->findOrFail($ids[0]), User::query()->findOrFail($ids[1])];
    }

    private function nonSpeakerUser(Legislature $legislature): User
    {
        $member = LegislatureMember::query()
            ->where('legislature_id', (string) $legislature->id)
            ->whereIn('status', ['elected', 'seated'])
            ->when($legislature->speaker_id !== null, fn ($q) => $q->whereKeyNot($legislature->speaker_id))
            ->firstOrFail();

        return User::query()->findOrFail($member->user_id);
    }

    private function castAllYes(ConstitutionalEngine $engine, Legislature $legislature, string $voteId): void
    {
        $members = LegislatureMember::query()
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
