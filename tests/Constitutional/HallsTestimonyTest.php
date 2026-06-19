<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\PublicRecord;
use App\Models\SocialThread;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-1 (F-SOC-002, the testimony bridge). Filing testimony in a hall
 * seals the resident's own post into the APPEND-ONLY public register (Art. II §2): the chain
 * entry names the record, the record carries audit_seq, and the thread's published_record_id
 * back-pointer is the record's UUID. A resident may seal only their OWN statement, and only in
 * the halls (not the open square). The sealed payload is pseudonymous.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class HallsTestimonyTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k1_halls';

    public function test_filing_testimony_seals_the_post_into_the_append_only_public_record(): void
    {
        $this->onLivePg(function () {
            $jurisdictionId = $this->aJurisdiction();
            $resident = $this->resident($jurisdictionId);
            $engine = app(ConstitutionalEngine::class);

            $body = 'For the record: I support widening the plaza in the next budget.';
            $opened = $engine->file('F-SOC-001', $resident, [
                'jurisdiction_id' => $jurisdictionId,
                'space_type'      => 'halls',
                'title'           => 'Budget — plaza widening',
                'body'            => $body,
            ])->recorded;

            $filed = $engine->file('F-SOC-002', $resident, [
                'jurisdiction_id' => $jurisdictionId,
                'thread_id'       => $opened['thread_id'],
                'post_id'         => $opened['post_id'],
            ])->recorded;

            // The back-pointer is the record's UUID, not its seq.
            $this->assertTrue(Str::isUuid($filed['record_id']));
            $this->assertSame($filed['record_id'], $filed['published_record_id']);
            $this->assertSame(
                $filed['record_id'],
                (string) SocialThread::query()->whereKey($opened['thread_id'])->value('published_record_id'),
                'the thread back-points at the sealed record'
            );

            // A sealed, append-only testimony record exists.
            $record = PublicRecord::query()->where('id', $filed['record_id'])->first();
            $this->assertNotNull($record);
            $this->assertSame('testimony', $record->kind);
            $this->assertSame('F-SOC-002', $record->via_form);
            $this->assertNotNull($record->audit_seq, 'the record is sealed to the chain');
            $this->assertSame($body, $record->body);

            // Pseudonymity: a display, never an email.
            $this->assertNotNull($record->actor_display);
            $this->assertStringNotContainsString('@', (string) $record->actor_display);
        });
    }

    public function test_a_resident_cannot_file_anothers_post_as_testimony(): void
    {
        $this->onLivePg(function () {
            $jurisdictionId = $this->aJurisdiction();
            $author = $this->resident($jurisdictionId);
            $other = $this->resident($jurisdictionId);
            $engine = app(ConstitutionalEngine::class);

            $opened = $engine->file('F-SOC-001', $author, [
                'jurisdiction_id' => $jurisdictionId, 'space_type' => 'halls',
                'title' => 'Mine', 'body' => 'My own statement.',
            ])->recorded;

            $threw = false;
            try {
                $engine->file('F-SOC-002', $other, [
                    'jurisdiction_id' => $jurisdictionId,
                    'thread_id'       => $opened['thread_id'],
                    'post_id'         => $opened['post_id'],
                ]);
            } catch (ConstitutionalViolation $e) {
                $threw = true;
                $this->assertSame('Art. I', $e->citation);
            }

            $this->assertTrue($threw, 'testimony is your OWN statement — a resident cannot seal another\'s post');
            $this->assertNull(SocialThread::query()->whereKey($opened['thread_id'])->value('published_record_id'),
                'the refused filing set no back-pointer');
        });
    }

    public function test_testimony_belongs_to_the_halls_not_the_open_square(): void
    {
        $this->onLivePg(function () {
            $jurisdictionId = $this->aJurisdiction();
            $resident = $this->resident($jurisdictionId);
            $engine = app(ConstitutionalEngine::class);

            // A post in the open square (default space), NOT the halls.
            $opened = $engine->file('F-SOC-001', $resident, [
                'jurisdiction_id' => $jurisdictionId,
                'title' => 'Just chatting', 'body' => 'Hello square.',
            ])->recorded;
            $this->assertSame('public_square', $opened['space_type']);

            $threw = false;
            try {
                $engine->file('F-SOC-002', $resident, [
                    'jurisdiction_id' => $jurisdictionId,
                    'thread_id'       => $opened['thread_id'],
                    'post_id'         => $opened['post_id'],
                ]);
            } catch (ConstitutionalViolation $e) {
                $threw = true;
                $this->assertSame('Art. II §2', $e->citation);
            }

            $this->assertTrue($threw, 'testimony is a halls act (the mandated deliberation record), not a square post');
        });
    }

    private function aJurisdiction(): string
    {
        $id = DB::table('jurisdictions')->whereNull('deleted_at')->value('id');
        if ($id === null) {
            $this->markTestSkipped('Live DB has no jurisdiction.');
        }

        return (string) $id;
    }

    private function resident(string $jurisdictionId): User
    {
        $user = User::create([
            'name'              => 'K1 Halls Resident '.Str::uuid(),
            'email'             => 'k1-halls-'.Str::uuid().'@test.invalid',
            'password'          => Str::random(32),
            'terms_accepted_at' => now(),
        ]);

        DB::table('residency_confirmations')->insert([
            'id'              => (string) Str::uuid(),
            'user_id'         => $user->id,
            'jurisdiction_id' => $jurisdictionId,
            'days_confirmed'  => 30,
            'confirmed_at'    => now(),
            'is_active'       => true,
            'depth'           => 0,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        app(RoleService::class)->flush();

        return $user;
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
