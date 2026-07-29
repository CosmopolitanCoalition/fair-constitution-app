<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\TrainingRequired;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — operator ruling A5, walked end to end through the
 * engine (the Wave 3 proof, K2_ENGINE_PLAN §5.2):
 *
 *   an untrained SEATED member's first role-authority act is refused with
 *   the structured redirect → they complete the training → F-EDU-001 files
 *   through the engine → the achievement mints and the stipend pays ONCE →
 *   the SAME act, retried, PROCEEDS.
 *
 * Acquiring was free throughout: the member was seated untrained and
 * nothing here gates a right — EducationNoGateTest holds that side.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class TrainingGateEndToEndTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_gate_e2e';

    public function test_first_act_redirects_training_opens_stipend_pays_once_act_proceeds(): void
    {
        $this->onLivePg(function () {
            $engine = app(ConstitutionalEngine::class);

            // ── A seated, wholly-untrained member (acquiring was free) ──
            [$jid, $legId, $member] = $this->seatedMember();
            $voteId = $this->openFloorVote($jid, $legId);

            // ── Published content arms the gate for 'legislature' ────────
            $moduleKey = $this->publishLegislatureTraining();

            // ── 1. The first act: refused with the structured redirect,
            //       and the refusal is a first-class rejected chain entry. ──
            try {
                $engine->file('F-LEG-004', $member, ['vote_id' => $voteId, 'value' => 'yes']);
                $this->fail('The untrained member\'s first act must redirect.');
            } catch (TrainingRequired $e) {
                $this->assertSame('legislature', $e->track);
                $this->assertSame('/learn/legislature', $e->lessonHref);
            }

            $this->assertSame(1, DB::table('audit_log')
                ->where('ref', 'F-LEG-004')->where('rejected', true)
                ->where('actor_user_id', (string) $member->id)->count());

            // ── 2. The training: F-EDU-001 through the engine. ───────────
            $wallet = $this->fundedWorldFor($member, $jid);

            $engine->file('F-EDU-001', $member, [
                'track_key' => 'legislature', 'module_key' => $moduleKey,
                'passed' => true, 'score_pct' => 100,
            ]);

            $this->assertSame(1, DB::table('achievements')
                ->where('user_id', (string) $member->id)->where('award_key', 'ACH-EDU-001')->count());
            $this->assertSame('completed', DB::table('education_progress')
                ->where('user_id', (string) $member->id)->value('state'));

            $paidOnce = (string) DB::table('economic_accounts')->where('id', $wallet)->value('balance');
            $this->assertSame(1, bccomp($paidOnce, '0', 6), 'The one-time stipend must pay on the fresh mint.');

            // ── 3. A retake never pays twice — the ledger IS the proof. ──
            $engine->file('F-EDU-001', $member, [
                'track_key' => 'legislature', 'module_key' => $moduleKey,
                'passed' => true, 'score_pct' => 100,
            ]);

            $this->assertSame(1, DB::table('achievements')
                ->where('user_id', (string) $member->id)->where('award_key', 'ACH-EDU-001')->count());
            $this->assertSame(
                $paidOnce,
                (string) DB::table('economic_accounts')->where('id', $wallet)->value('balance'),
                'A retake paid a second stipend — the once-only proof failed.'
            );

            // ── 4. The same act, retried: it PROCEEDS. ───────────────────
            $result = $engine->file('F-LEG-004', $member, ['vote_id' => $voteId, 'value' => 'yes']);

            $this->assertSame('F-LEG-004', $result->formId);
            $this->assertSame($voteId, $result->recorded['vote_id']);
            $this->assertSame('yes', $result->recorded['value']);
        });
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string, 2: User} [jurisdictionId, legislatureId, member] */
    private function seatedMember(): array
    {
        $jid = (string) Str::uuid();
        DB::table('jurisdictions')->insert([
            'id' => $jid, 'name' => 'Gate E2E', 'slug' => 'gate-e2e-'.Str::lower(Str::random(10)),
            'adm_level' => 3, 'population' => 1000, 'source' => 'user_defined',
            'official_languages' => '["en"]', 'timezone' => 'UTC',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $legId = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id' => $legId, 'jurisdiction_id' => $jid, 'term_number' => 1, 'status' => 'active',
            'total_seats' => 5, 'type_a_seats' => 5, 'type_b_seats' => 0, 'quorum_required' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $member = User::create([
            'name' => 'Gate E2E Member',
            'email' => 'gate-e2e-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);

        DB::table('legislature_members')->insert([
            'id' => (string) Str::uuid(), 'legislature_id' => $legId,
            'user_id' => (string) $member->id, 'seat_type' => 'a', 'seat_no' => 1,
            'status' => 'seated', 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(RoleService::class)->flush();

        return [$jid, $legId, $member];
    }

    private function openFloorVote(string $jid, string $legId): string
    {
        $voteId = (string) Str::uuid();
        DB::table('chamber_votes')->insert([
            'id' => $voteId, 'body_type' => 'legislature', 'body_id' => $legId,
            'legislature_id' => $legId, 'jurisdiction_id' => $jid,
            'vote_type' => 'motion', 'vote_method' => 'yes_no',
            'threshold_basis' => 'majority', 'stage' => 'floor', 'bicameral' => false,
            'serving_snapshot' => 5, 'speaker_tiebreak' => false,
            'opened_at' => now(), 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('chamber_vote_tallies')->insert([
            'id' => (string) Str::uuid(), 'vote_id' => $voteId, 'lane' => 'all',
            'serving' => 5, 'quorum_required' => 3, 'required_yes' => 3,
            'present' => 0, 'yes' => 0, 'no' => 0, 'abstain' => 0,
            'quorate' => false, 'passed' => false,
        ]);

        return $voteId;
    }

    private function publishLegislatureTraining(): string
    {
        $trackId = (string) Str::uuid();
        DB::table('education_tracks')->insert([
            'id' => $trackId, 'key' => 'legislature', 'title' => 'Serving in a legislature',
            'status' => 'live', 'ordering' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $moduleKey = 'chamber-basics';
        DB::table('education_modules')->insert([
            'id' => (string) Str::uuid(), 'track_id' => $trackId, 'key' => $moduleKey,
            'title' => 'The chamber', 'status' => 'live', 'ordering' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $moduleKey;
    }

    /** Residency + currency + treasury + wallet, so the stipend leg is payable. Returns the wallet id. */
    private function fundedWorldFor(User $member, string $jid): string
    {
        DB::table('residency_confirmations')->insert([
            'id' => (string) Str::uuid(), 'user_id' => (string) $member->id,
            'jurisdiction_id' => $jid, 'days_confirmed' => 30, 'confirmed_at' => now(),
            'voting_right_active' => true, 'candidacy_right_active' => true,
            'is_active' => true, 'depth' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rootId = (string) DB::table('jurisdictions')->whereNull('parent_id')->whereNull('deleted_at')->value('id');

        $currencyId = DB::table('currencies')
            ->where('jurisdiction_id', $rootId)->whereNull('deleted_at')->value('id');

        if ($currencyId === null) {
            $currencyId = (string) Str::uuid();
            DB::table('currencies')->insert([
                'id' => $currencyId, 'jurisdiction_id' => $rootId, 'code' => 'GTE',
                'name' => 'Gate Test Unit', 'symbol' => '¤', 'precision' => 6,
                'unit_kind' => 'abstract', 'worth_basis' => 'abstract',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $currencyId = (string) $currencyId;

        $treasuryId = DB::table('treasury_accounts')
            ->where('owner_type', 'jurisdictions')->where('owner_id', $rootId)
            ->where('currency_id', $currencyId)->whereNull('deleted_at')->value('id');

        if ($treasuryId === null) {
            $treasuryId = (string) Str::uuid();
            DB::table('treasury_accounts')->insert([
                'id' => $treasuryId, 'owner_type' => 'jurisdictions', 'owner_id' => $rootId,
                'currency_id' => $currencyId, 'balance' => 0, 'public' => true,
                'label' => 'Gate E2E treasury', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return (string) app(\App\Services\Economy\AccountService::class)
            ->open('users', (string) $member->id, $currencyId)->id;
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        app(RoleService::class)->flush();
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
            app(RoleService::class)->flush();
        }
    }
}
