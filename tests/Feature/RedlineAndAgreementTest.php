<?php

namespace Tests\Feature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\User;
use App\Services\Redlines\RedlineService;
use App\Services\Redlines\ResidentAgreementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Design Round 2 build pieces 5 + 6 — clause redlines + resident agreements.
 *
 * The consent plane's constitutional core: a contract takes effect only when
 * EVERY party signs (Art. I); no clause may waive a right (Art. I floor,
 * enforced on the structured tag); an accepted redline voids the signatures
 * because a signature is on a specific text; a bill is amended by a vote, not
 * a party's acceptance; and only a party may negotiate.
 */
class RedlineAndAgreementTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'redline_pg';

    public function test_a_p2p_agreement_is_active_only_when_all_sign(): void
    {
        $this->onLivePg(function () {
            $a = $this->user();
            $b = $this->user();
            $c = $this->user();
            $svc = app(ResidentAgreementService::class);

            $r = $svc->create('Trade pact', 'We trade fairly.', (string) $a->id, [(string) $b->id, (string) $c->id]);
            $this->assertSame('offered', $r['status'], 'initiator alone does not activate it');

            $svc->sign($r['agreement_id'], (string) $b->id);
            $this->assertSame('offered', DB::table('resident_agreements')->where('id', $r['agreement_id'])->value('status'));

            $signed = $svc->sign($r['agreement_id'], (string) $c->id);
            $this->assertTrue($signed['all_signed'], 'the last signature activates it in the same act');
            $this->assertSame('active', DB::table('resident_agreements')->where('id', $r['agreement_id'])->value('status'));
        });
    }

    public function test_the_art_i_floor_refuses_a_clause_that_waives_a_right(): void
    {
        $this->onLivePg(function () {
            $a = $this->user();
            $svc = app(RedlineService::class);

            $this->expectException(ConstitutionalViolation::class);
            $this->expectExceptionMessageMatches('/waive the right to voting/');

            $svc->propose(RedlineService::SUBJECT_RESIDENT, (string) Str::uuid(), null, 'add', 'You give up your vote.', null, 'voting', (string) $a->id);
        });
    }

    public function test_an_accepted_redline_clears_the_signatures(): void
    {
        $this->onLivePg(function () {
            $a = $this->user();
            $b = $this->user();
            $agree = app(ResidentAgreementService::class);
            $red = app(RedlineService::class);

            $r = $agree->create('Deal', 'Original terms.', (string) $a->id, [(string) $b->id]);
            $agree->sign($r['agreement_id'], (string) $b->id);
            $this->assertSame('active', DB::table('resident_agreements')->where('id', $r['agreement_id'])->value('status'));

            $clauseId = DB::table('clauses')
                ->where('subject_type', 'resident')->where('subject_id', $r['agreement_id'])
                ->value('id');

            $proposal = $red->propose('resident', $r['agreement_id'], $clauseId, 'edit', 'Revised terms.', 'clearer', null, (string) $b->id);
            $red->acceptForAgreement($proposal['redline_id']);

            // The text changed → the instrument leaves 'active' and both
            // signatures are void; the parties must re-sign.
            $this->assertSame('offered', DB::table('resident_agreements')->where('id', $r['agreement_id'])->value('status'));
            $this->assertSame(0, (int) DB::table('resident_agreement_signers')
                ->where('agreement_id', $r['agreement_id'])->whereNotNull('signed_at')->count());
            // The edit applied to the overlay.
            $this->assertSame('Revised terms.', DB::table('clauses')->where('id', $clauseId)->value('body'));
        });
    }

    public function test_a_bill_redline_is_not_accepted_by_a_party(): void
    {
        $this->onLivePg(function () {
            $a = $this->user();
            $red = app(RedlineService::class);

            $billId = (string) Str::uuid();
            $proposal = $red->propose('bill', $billId, null, 'add', 'A new section.', null, null, (string) $a->id);

            $this->expectException(ConstitutionalViolation::class);
            $this->expectExceptionMessageMatches('/vote of the chamber/');

            $red->acceptForAgreement($proposal['redline_id']);
        });
    }

    public function test_a_non_party_cannot_sign(): void
    {
        $this->onLivePg(function () {
            $a = $this->user();
            $b = $this->user();
            $stranger = $this->user();
            $svc = app(ResidentAgreementService::class);

            $r = $svc->create('Private deal', 'Terms.', (string) $a->id, [(string) $b->id]);

            $this->expectException(ConstitutionalViolation::class);
            $this->expectExceptionMessageMatches('/named party/');

            $svc->sign($r['agreement_id'], (string) $stranger->id);
        });
    }

    public function test_the_engine_door_gates_a_non_party_redline(): void
    {
        $this->onLivePg(function () {
            $a = $this->residentUser();
            $b = $this->residentUser();
            $stranger = $this->residentUser();

            $engine = app(ConstitutionalEngine::class);
            $created = $engine->file('F-IND-020', $a, [
                'action'  => 'create_agreement',
                'title'   => 'Engine deal',
                'terms'   => 'Terms.',
                'signers' => [(string) $b->id],
            ]);

            $agreementId = $created->recorded['agreement_id'];

            $this->expectException(ConstitutionalViolation::class);
            $this->expectExceptionMessageMatches('/party to this agreement/');

            $engine->file('F-IND-020', $stranger, [
                'action'       => 'propose_redline',
                'subject_type' => 'resident',
                'subject_id'   => $agreementId,
                'kind'         => 'add',
                'body'         => 'A clause I have no standing to add.',
            ]);
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function user(): User
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id'                => $id,
            'name'              => 'Red ' . substr($id, 0, 8),
            'email'             => "red-{$id}@test.invalid",
            'password'          => 'x',
            'terms_accepted_at' => now(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    /** R-01 ("authenticated account exists") needs only a registered account. */
    private function residentUser(): User
    {
        return $this->user();
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            $conn->rollBack();
            DB::setDefaultConnection($original);
        }
    }
}
