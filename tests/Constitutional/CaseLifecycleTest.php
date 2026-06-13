<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Advocate;
use App\Models\CaseFiling;
use App\Models\CourtCase;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Jury;
use App\Models\JuryMember;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Panel;
use App\Models\PanelJudge;
use App\Models\User;
use App\Services\Judiciary\CaseService;
use App\Services\Judiciary\JudicialSeatService;
use App\Services\Judiciary\PanelSizing;
use App\Services\RoleService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Article IV §4 + Article II §8 (the case lifecycle, the
 * Phase E exit criterion #3). A case runs end to end THROUGH THE ENGINE:
 *
 *   F-IND-017 files → F-JDG-001 accepts + panels an odd ≥3 bench (en banc for
 *   a constitutional-major) → optional jury (F-JDG-002, a verifiable draw) →
 *   verdict (a CaseService transition, NOT a form) → F-JDG-009 sentencing /
 *   F-JDG-010 warrant; the opinion (F-JDG-003) closes the case.
 *
 * Art. IV §4 — "The number of Judges sat to a case should be at least three
 * (3), Odd in number, and scale with the severity… Constitutional Questions of
 * significant importance are heard by the entire court… Accused Individuals
 * are entitled to have their cases heard by a jury of their peers and be
 * represented by zealous and competent advocates."
 *
 * Art. II §8 — a warrant establishes the reason for the arrest and the maximum
 * duration an Individual can be held; a criminal verdict locks double jeopardy.
 *
 * Live-DB sections use the guarded pg connection (the JudiciaryCreation-
 * ConversionTest / WorkerRepresentationTest technique): SKIP when pg is
 * unreachable; every write rides one transaction that is ALWAYS rolled back.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class CaseLifecycleTest extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_case_lifecycle';

    /**
     * EXIT CRITERION #3 — a CRIMINAL case runs file → accept → panel (odd ≥3)
     * → jury (verifiable draw) → verdict (double jeopardy locks) → sentence,
     * end to end through the engine; a serious criminal case on a 5-judge bench
     * seats EXACTLY the PanelSizing::sizeFor output (5), and the opinion closes
     * the case.
     */
    public function test_criminal_case_runs_file_accept_panel_jury_verdict_sentence_through_the_engine(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        app(RoleService::class)->flush();

        $conn->beginTransaction();

        try {
            $engine = app(ConstitutionalEngine::class);

            [$legislature, $judiciary] = $this->appointedFiveJudgeCourt($conn);
            $jurisdictionId = (string) $legislature->jurisdiction_id;

            $seated = JudicialSeat::query()
                ->where('judiciary_id', $judiciary->id)
                ->where('status', JudicialSeat::STATUS_SEATED)
                ->get();
            $this->assertGreaterThanOrEqual(5, $seated->count(), 'A 5-judge appointed bench is the test substrate.');

            // The acting judge (any seated judge derives R-19).
            $judge = User::query()->findOrFail((string) $seated->first()->user_id);
            $this->assertContains('R-19', app(RoleService::class)->rolesFor($judge), 'A seated appointed judge derives R-19.');

            // The complainant + the accused (both associated residents).
            [$complainant, $accused] = $this->twoAssociatedUsers($jurisdictionId);

            // ── F-IND-017 — file a CRIMINAL case ─────────────────────────────
            $filed = $engine->file('F-IND-017', $complainant, [
                'judiciary_id' => (string) $judiciary->id,
                'jurisdiction_id' => $jurisdictionId,
                'kind' => 'criminal',
                'title' => 'State v. CaseLifecycleTest throwaway',
                'statement_of_claim' => 'Throwaway criminal accusation — rolled back.',
                'claimed_severity' => 'serious',
                'accused_user_id' => (string) $accused->id,
            ]);

            $case = CourtCase::query()->findOrFail($filed->recorded['case_id']);
            $this->assertSame(CourtCase::STATUS_FILED, $case->status);
            $this->assertMatchesRegularExpression('/^case-\d{4}-\d{3}$/', (string) $case->docket_no, 'docket-no is case-YYYY-NNN.');
            $this->assertSame('F-IND-017', (string) $case->filed_via_form);

            // The opening docket entry exists on the immutable docket.
            $this->assertSame(
                1,
                CaseFiling::query()->where('case_id', (string) $case->id)->where('filing_kind', 'case_filing')->count(),
                'The opening filing is on the docket.'
            );

            // ── F-JDG-001 — accept (serious) + panel ─────────────────────────
            $accepted = $engine->file('F-JDG-001', $judge, [
                'case_id' => (string) $case->id,
                'court_severity' => 'serious',
            ]);

            $case->refresh();
            $this->assertSame(CourtCase::STATUS_PANELED, $case->status, 'accept → paneled in one filing.');
            $this->assertSame('serious', (string) $case->court_severity, 'The COURT fixes the severity (not the filer).');
            $this->assertTrue((bool) $case->jury_entitled, 'A criminal case is jury-entitled (Art. IV §4).');

            $panel = Panel::query()->findOrFail($accepted->recorded['panel_id']);
            // The seated panel equals PanelSizing::sizeFor over the seated pool.
            $expected = PanelSizing::sizeFor('serious', $seated->count());
            $this->assertSame($expected['size'], (int) $panel->size, 'The panel size equals PanelSizing::sizeFor (Art. IV §4).');
            $this->assertSame(5, (int) $panel->size, 'A serious case on a 5-judge court seats exactly 5.');
            $this->assertFalse((bool) $panel->is_en_banc, 'A serious (non-constitutional) case is not en banc.');
            $this->assertSame(1, (int) $panel->size % 2, 'The panel is ODD.');

            // The bench is seated, odd, with one presiding judge.
            $seatedJudges = PanelJudge::query()
                ->where('panel_id', (string) $panel->id)
                ->where('status', PanelJudge::STATUS_SEATED)
                ->get();
            $this->assertCount(5, $seatedJudges);
            $this->assertSame(
                1,
                PanelJudge::query()->where('panel_id', (string) $panel->id)->where('is_presiding', true)->count(),
                'Exactly one presiding judge.'
            );

            // The draw seed is sealed to the chain (the verifiable draw).
            $drawEntry = $conn->table('audit_log')
                ->where('event', 'panel.drawn')
                ->where('payload->panel_id', (string) $panel->id)
                ->first();
            $this->assertNotNull($drawEntry, 'The panel draw seed is sealed to the audit chain.');

            // ── F-JDG-002 — empanel the jury (verifiable draw) ───────────────
            $juryOrdered = $engine->file('F-JDG-002', $judge, [
                'case_id' => (string) $case->id,
                'seats' => 12,
                'alternates' => 2,
            ]);

            $case->refresh();
            $this->assertSame(CourtCase::STATUS_JURY_EMPANELED, $case->status);

            $jury = Jury::query()->findOrFail($juryOrdered->recorded['jury_id']);
            $this->assertSame(14, JuryMember::query()->where('jury_id', (string) $jury->id)->count(), '12 jurors + 2 alternates drawn.');
            $this->assertNotEmpty((string) $jury->draw_seed, 'The jury draw seed is published.');

            // The draw REPRODUCES from the published seed (anyone can verify).
            $pool = $conn->table('residency_confirmations')
                ->where('jurisdiction_id', $jurisdictionId)
                ->where('is_active', true)
                ->pluck('user_id')->map(fn ($id) => (string) $id)->all();
            $reproduced = app(\App\Services\Judiciary\JuryService::class)->deterministicDraw($pool, (string) $jury->draw_seed, 14);
            $drawn = JuryMember::query()->where('jury_id', (string) $jury->id)->orderBy('seat_kind')->pluck('user_id')->map(fn ($id) => (string) $id)->all();
            sort($reproduced);
            sort($drawn);
            $this->assertSame($drawn, $reproduced, 'Art. IV §4 — the published seed reproduces the exact jury draw.');

            // A drawn juror derives R-22.
            $juror = User::query()->findOrFail((string) JuryMember::query()->where('jury_id', (string) $jury->id)->first()->user_id);
            app(RoleService::class)->flushUser((string) $juror->id);
            $this->assertContains('R-22', app(RoleService::class)->rolesFor($juror), 'A summoned juror derives R-22.');

            // NO fee field exists anywhere on the jury path (the structural
            // no-fee shield, Art. II §8): the juries / jury_members tables carry
            // no payment column.
            foreach (['juries', 'jury_members'] as $table) {
                $cols = $conn->getSchemaBuilder()->getColumnListing($table);
                foreach ($cols as $col) {
                    $this->assertStringNotContainsString('fee', strtolower($col), "No fee column on {$table} (Art. II §8).");
                    $this->assertStringNotContainsString('payment', strtolower($col), "No payment column on {$table} (Art. II §8).");
                }
            }

            // ── hearing → deliberation → verdict (a CaseService transition) ──
            $cases = app(CaseService::class);
            $cases->advanceToHearing($case->refresh());
            $cases->enterDeliberation($case->refresh());

            $this->assertFalse((bool) $case->refresh()->double_jeopardy_locked, 'Double jeopardy is not locked before the verdict.');

            $verdict = $cases->recordVerdict($case->refresh(), [
                'decided_by' => 'jury',
                'outcome' => 'guilty',
                'jury_unanimous' => true,
                'summary' => 'Guilty on all counts (throwaway).',
            ]);

            $case->refresh();
            $this->assertSame(CourtCase::STATUS_DECIDED, $case->status);
            $this->assertTrue((bool) $case->double_jeopardy_locked, 'Art. II §8 — a criminal verdict LOCKS double jeopardy.');
            $this->assertTrue((bool) $verdict->double_jeopardy_flag, 'The verdict carries the double-jeopardy flag (criminal).');

            // ── F-JDG-009 — sentence the guilty verdict ──────────────────────
            $sentenced = $engine->file('F-JDG-009', $judge, [
                'case_id' => (string) $case->id,
                'terms' => '10 years (throwaway sentence).',
            ]);

            $case->refresh();
            $this->assertSame(CourtCase::STATUS_SENTENCED, $case->status);
            $order = \App\Models\SentencingOrder::query()->findOrFail($sentenced->recorded['sentencing_order_id']);
            $this->assertSame('issued', $order->status);

            // ── F-JDG-010 — a warrant carries the Art. II §8 facts ──────────
            $warranted = $engine->file('F-JDG-010', $judge, [
                'case_id' => (string) $case->id,
                'kind' => 'arrest',
                'stated_reason' => 'Failure to appear (throwaway).',
                'max_hold_duration_hours' => 48,
                'subject_user_id' => (string) $accused->id,
            ]);

            $warrant = \App\Models\Warrant::query()->findOrFail($warranted->recorded['warrant_id']);
            $this->assertSame('arrest', $warrant->kind);
            $this->assertSame(48, (int) $warrant->max_hold_duration_hours, 'Art. II §8 — the max hold duration is recorded.');
            $this->assertNotEmpty((string) $warrant->stated_reason, 'Art. II §8 — the stated reason is recorded.');

            // ── F-JDG-003 — the opinion closes the case ──────────────────────
            $opined = $engine->file('F-JDG-003', $judge, [
                'case_id' => (string) $case->id,
                'kind' => 'majority',
                'title' => 'Opinion of the court (throwaway)',
                'body' => 'The panel finds… (throwaway commentary).',
            ]);

            $case->refresh();
            $this->assertSame(CourtCase::STATUS_CLOSED, $case->status, 'The opinion closes the case (terminal).');
            $opinion = \App\Models\Opinion::query()->findOrFail($opined->recorded['opinion_id']);
            $this->assertNotNull($opinion->record_id, 'The opinion publishes (kind opinion).');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
            app(RoleService::class)->flush();
        }
    }

    /**
     * Art. IV §4 — a CONSTITUTIONAL-MAJOR case seats the WHOLE court EN BANC.
     * (A civil case on a 5-judge court classified constitutional_major seats 5
     * en banc — the entire seated bench.)
     */
    public function test_constitutional_major_case_seats_the_whole_court_en_banc(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        app(RoleService::class)->flush();

        $conn->beginTransaction();

        try {
            $engine = app(ConstitutionalEngine::class);

            [$legislature, $judiciary] = $this->appointedFiveJudgeCourt($conn);
            $jurisdictionId = (string) $legislature->jurisdiction_id;

            $seated = JudicialSeat::query()->where('judiciary_id', $judiciary->id)->where('status', JudicialSeat::STATUS_SEATED)->get();
            $judge = User::query()->findOrFail((string) $seated->first()->user_id);
            [$complainant] = $this->twoAssociatedUsers($jurisdictionId);

            $filed = $engine->file('F-IND-017', $complainant, [
                'judiciary_id' => (string) $judiciary->id,
                'jurisdiction_id' => $jurisdictionId,
                'kind' => 'administrative',
                'title' => 'Major constitutional question (throwaway)',
            ]);

            $case = CourtCase::query()->findOrFail($filed->recorded['case_id']);

            $accepted = $engine->file('F-JDG-001', $judge, [
                'case_id' => (string) $case->id,
                'court_severity' => 'constitutional_major',
            ]);

            $panel = Panel::query()->findOrFail($accepted->recorded['panel_id']);
            $this->assertTrue((bool) $panel->is_en_banc, 'Art. IV §4 — a major constitutional question is heard en banc.');

            $expected = PanelSizing::sizeFor('constitutional_major', $seated->count());
            $this->assertSame($expected['size'], (int) $panel->size, 'The en-banc panel is the whole (forced-odd) court.');
            $this->assertSame($seated->count(), (int) $panel->size, 'A 5-judge court seats all 5 en banc.');
            $this->assertSame(1, (int) $panel->size % 2);
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
            app(RoleService::class)->flush();
        }
    }

    /**
     * Art. IV §4 — an advocate registers (F-IND-015 → R-21), files on behalf of
     * a client (F-ADV-001), and a brief filed after `deliberation` is rejected
     * by the attach-window gate; a NON-advocate is rejected from F-ADV-001 by
     * the engine (registration required), never a silent pass.
     */
    public function test_advocate_registration_and_attach_window_through_the_engine(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        app(RoleService::class)->flush();

        $conn->beginTransaction();

        try {
            $engine = app(ConstitutionalEngine::class);

            [$legislature, $judiciary] = $this->appointedFiveJudgeCourt($conn);
            $jurisdictionId = (string) $legislature->jurisdiction_id;

            [$advocateUser, $clientUser] = $this->twoAssociatedUsers($jurisdictionId);

            // F-IND-015 — register the advocate → R-21.
            $engine->file('F-IND-015', $advocateUser, [
                'judiciary_id' => (string) $judiciary->id,
                'jurisdiction_id' => $jurisdictionId,
            ]);

            app(RoleService::class)->flushUser((string) $advocateUser->id);
            $this->assertContains('R-21', app(RoleService::class)->rolesFor($advocateUser), 'A registered advocate derives R-21.');
            $this->assertSame(
                1,
                Advocate::query()->where('user_id', (string) $advocateUser->id)->where('judiciary_id', (string) $judiciary->id)->count(),
                'The advocate row exists.'
            );

            // F-ADV-001 — file on behalf of the client.
            $filed = $engine->file('F-ADV-001', $advocateUser, [
                'judiciary_id' => (string) $judiciary->id,
                'jurisdiction_id' => $jurisdictionId,
                'kind' => 'civil',
                'title' => 'Client v. Throwaway',
                'filed_on_behalf_of_user_id' => (string) $clientUser->id,
                'retainer_note' => 'Retainer recorded with the filing (throwaway).',
            ]);

            $case = CourtCase::query()->findOrFail($filed->recorded['case_id']);
            $this->assertSame((string) $advocateUser->id, (string) $case->filed_by_user_id);
            $this->assertSame((string) $clientUser->id, (string) $case->filed_on_behalf_of_user_id);
            $this->assertNotNull($case->advocate_id, 'The case records the engaged advocate.');

            // A NON-advocate is rejected from F-ADV-001 (registration required).
            [$nonAdvocate] = $this->twoAssociatedUsers($jurisdictionId, exclude: [(string) $advocateUser->id, (string) $clientUser->id]);
            try {
                $engine->file('F-ADV-001', $nonAdvocate, [
                    'judiciary_id' => (string) $judiciary->id,
                    'jurisdiction_id' => $jurisdictionId,
                    'kind' => 'civil',
                    'title' => 'Unregistered v. Throwaway',
                    'filed_on_behalf_of_user_id' => (string) $clientUser->id,
                ]);
                $this->fail('A non-advocate must be rejected from F-ADV-001.');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('CGA Roles & Forms Chart', $e->citation, 'The engine gates F-ADV-001 at the R-21 authorize stage.');
            }

            // A brief filed after `deliberation` is rejected by the attach-window.
            // Drive the case past deliberation, then attempt an F-ADV-004 brief.
            $cases = app(CaseService::class);
            $judge = User::query()->findOrFail(
                (string) JudicialSeat::query()->where('judiciary_id', $judiciary->id)->where('status', JudicialSeat::STATUS_SEATED)->first()->user_id
            );
            $engine->file('F-JDG-001', $judge, ['case_id' => (string) $case->id, 'court_severity' => 'minor']);
            $cases->advanceToHearing($case->refresh());
            $cases->enterDeliberation($case->refresh());

            try {
                $engine->file('F-ADV-004', $advocateUser, [
                    'case_id' => (string) $case->id,
                    'title' => 'Late brief',
                    'body' => 'Filed after deliberation — should be rejected.',
                ]);
                $this->fail('A brief filed after deliberation must be rejected by the attach-window gate.');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. IV §4', $e->citation, 'The attach-window gate cites Art. IV §4.');
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
    // Helpers
    // ======================================================================

    /**
     * Drive a forming judiciary (with constituents — San Marino) to APPOINTED
     * with a 5-seat bench, or a committee court without constituents; return
     * the chamber + the seated court.
     *
     * @return array{0: Legislature, 1: Judiciary}
     */
    private function appointedFiveJudgeCourt(Connection $conn): array
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

            // Need enough associated residents to nominate 5 judges + file/jury.
            $associated = $conn->table('residency_confirmations')
                ->where('jurisdiction_id', (string) $legislature->jurisdiction_id)
                ->where('is_active', true)
                ->count();

            if ($associated < 20) {
                continue; // too small a pool to draw a jury from
            }

            try {
                $this->driveToAppointed(app(ConstitutionalEngine::class), $legislature, $judiciary, $constituents);
            } catch (\Throwable $e) {
                continue; // try the next candidate court
            }

            return [$legislature, $judiciary->refresh()];
        }

        $this->markTestSkipped('Live DB has no forming judiciary with a chamber + a ≥20-resident pool — seed San Marino.');
    }

    /**
     * Engine-drive F-LEG-017 creation + the F-LEG-021 consent of every seat so
     * the court reaches `appointed` with 5 judges (the JudiciaryCreation-
     * ConversionTest::driveToAppointed pattern).
     *
     * @param  list<string>  $constituentIds
     */
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
            'court_name' => 'CaseLifecycleTest throwaway court',
            'function_text' => 'CaseLifecycleTest throwaway — appointed court.',
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

    /**
     * Two associated users NOT already seated as judges of any court (so they
     * are free to be parties / advocates), excluding any ids in $exclude.
     *
     * @param  list<string>  $exclude
     * @return array{0: User, 1: User}
     */
    private function twoAssociatedUsers(string $jurisdictionId, array $exclude = []): array
    {
        $seatedJudgeIds = JudicialSeat::query()
            ->where('status', JudicialSeat::STATUS_SEATED)
            ->pluck('user_id')->filter()->map(fn ($id) => (string) $id)->all();

        $ids = DB::table('residency_confirmations')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->whereNotIn('user_id', array_merge($seatedJudgeIds, $exclude))
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
