<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\Bill;
use App\Models\ChamberVote;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Services\BillService;
use App\Services\Demo\Stages\CohortStage;
use App\Services\Demo\Stages\CountingStage;
use App\Services\Demo\Stages\ElectionStage;
use App\Services\Demo\Stages\IdentityStage;
use App\Services\Demo\Stages\SeatingStage;
use App\Services\SettingsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the amendable-setting loop (R-C), end to end.
 *
 * SettingEnactmentTest pins the PIECES (bounds, basis, clock re-derivation) and
 * its docblock DEFERS the true end-to-end — "Montegiardino election_interval_months
 * 60→48→60 with the clock timer moved" — to a "live tinker verification"
 * (AMEND_SETTING_WALK.md). This is that verification, made automatic and
 * self-seeding so it runs on every box, seeded or not.
 *
 * THE LOOP (Art. II §2, F-LEG-031 / R-09 → BillService → EnactmentService):
 *   propose → the setting does NOT move at filing
 *   → floor vote under peg quorum (setting_change enacts at the MAJORITY peg,
 *     not 2/3 — supermajority is reserved for dual-door keys + full amendments)
 *   → APPLY: constitutional_settings mutates, a setting_changes ledger row is
 *     appended naming the enacting act, and SettingsResolver (what the app reads)
 *     returns the new value.
 *
 * A world is seeded through the real stages (cohort → identities → election →
 * counting → SEATING) so the legislature is genuinely STATUS_ACTIVE with seated
 * members who can sponsor and vote — the same certification path production uses.
 * Nothing here is mocked; the setting really changes in the (rolled-back) DB.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class AmendSettingLoopTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_amend_loop';

    /** The headline: a filed amendment travels the real act pipeline and lands. */
    public function test_the_amendable_setting_loop_propose_vote_apply_lands(): void
    {
        $this->onLivePg(function () {
            [$jid, $legId] = $this->seatedActiveChamber();

            // The chamber must genuinely be governing — F-LEG-031 refuses a
            // jurisdiction with no ACTIVE legislature (Art. VII).
            $this->assertSame(
                Legislature::STATUS_ACTIVE,
                DB::table('legislatures')->where('id', $legId)->value('status'),
                'the seated chamber is active — settings amend only through a governing legislature'
            );

            // A settings row for the jurisdiction, starting at the 60-month default.
            DB::table('constitutional_settings')->insert([
                'jurisdiction_id'          => $jid,
                'election_interval_months' => 60,
            ]);

            $engine  = app(ConstitutionalEngine::class);
            $sponsor = $this->aSeatedMember($legId);

            // ── PROPOSE ──────────────────────────────────────────────────────
            $filed = $engine->file('F-LEG-031', $sponsor, [
                'jurisdiction_id' => $jid,
                'setting_key'     => 'election_interval_months',
                'value'           => 48,
            ]);

            $billId = (string) $filed->recorded['bill_id'];
            $this->assertNotEmpty($billId, 'filing introduces a setting bill');

            // The setting does NOT move at filing — only at enactment.
            $this->assertSame(
                60,
                (int) DB::table('constitutional_settings')->where('jurisdiction_id', $jid)->value('election_interval_months'),
                'the value is untouched until the floor vote enacts it'
            );
            $this->assertFalse(
                (bool) ($filed->recorded['applied'] ?? true),
                'the handler reports applied=false at filing'
            );

            // ── FLOOR VOTE (majority peg) ────────────────────────────────────
            $floor = app(BillService::class)->moveToFloor(Bill::query()->findOrFail($billId)->refresh());
            $this->assertNotNull($floor, 'the setting bill reaches its floor vote');
            $this->castAllYes($engine, $legId, (string) $floor->id);

            // ── APPLY — the loop closes ──────────────────────────────────────
            $this->assertSame(
                48,
                (int) DB::table('constitutional_settings')->where('jurisdiction_id', $jid)->value('election_interval_months'),
                'the enacted value is written to constitutional_settings'
            );

            // The append-only ledger names the enacting act (60 → 48).
            $change = DB::table('setting_changes')
                ->where('jurisdiction_id', $jid)
                ->where('setting_key', 'election_interval_months')
                ->orderByDesc('applied_at')
                ->first();

            $this->assertNotNull($change, 'a setting_changes ledger row was appended');
            $this->assertSame('48', (string) $change->new_value, 'the ledger records the new value');
            $this->assertSame('60', (string) $change->old_value, 'and the value it replaced');
            $this->assertNotNull($change->law_id, 'the ledger row names the enacting act');

            // The bill is a real enacted law, and the settings row points back to it.
            $this->assertSame(
                (string) $change->law_id,
                (string) DB::table('constitutional_settings')->where('jurisdiction_id', $jid)->value('last_amended_by_act_id'),
                'the settings row records the act that last amended it'
            );

            // What the APP reads — the resolver — returns the new value, not a memo of the old.
            $this->assertSame(
                48,
                app(SettingsResolver::class)->resolveInt($jid, 'election_interval_months', 60),
                'the resolver (the read path the whole app uses) sees the amendment'
            );
        });
    }

    /** Out-of-range never enacts: the PROTECTED bounds reject at filing. */
    public function test_an_out_of_range_amendment_is_rejected_before_any_bill(): void
    {
        $this->onLivePg(function () {
            [$jid, $legId] = $this->seatedActiveChamber();
            DB::table('constitutional_settings')->insert([
                'jurisdiction_id'          => $jid,
                'election_interval_months' => 60,
            ]);

            $engine  = app(ConstitutionalEngine::class);
            $sponsor = $this->aSeatedMember($legId);

            // 72 > the hardened max of 60 (Art. II §2).
            $threw = false;
            try {
                $engine->file('F-LEG-031', $sponsor, [
                    'jurisdiction_id' => $jid,
                    'setting_key'     => 'election_interval_months',
                    'value'           => 72,
                ]);
            } catch (\Throwable $e) {
                $threw = true;
            }

            $this->assertTrue($threw, 'an out-of-range value is refused at the door');
            $this->assertSame(
                60,
                (int) DB::table('constitutional_settings')->where('jurisdiction_id', $jid)->value('election_interval_months'),
                'and nothing was written'
            );
            $this->assertSame(
                0,
                DB::table('setting_changes')->where('jurisdiction_id', $jid)->count(),
                'no ledger row for a rejected filing'
            );
        });
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /**
     * Seed a real, seated, ACTIVE single-district chamber through the demo
     * stages — the same certification path production seats a chamber with.
     *
     * @return array{0: string, 1: string} [jurisdictionId, legislatureId]
     */
    private function seatedActiveChamber(): array
    {
        $jid = (string) Str::uuid();

        DB::table('jurisdictions')->insert([
            'id'                 => $jid,
            'name'               => 'Amend Loop Pin',
            'slug'               => 'amend-loop-'.Str::lower(Str::random(10)),
            'adm_level'          => 3,
            'population'         => 900_000,
            'source'             => 'user_defined',
            'official_languages' => '["en"]',
            'timezone'           => 'UTC',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $legId = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id'              => $legId,
            'jurisdiction_id' => $jid,
            'term_number'     => 1,
            'status'          => 'forming',
            'total_seats'     => 9,
            'type_a_seats'    => 9,
            'type_b_seats'    => 0,
            'quorum_required' => 5,
            'type_b_rep_floor' => 2,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $boardId = (string) Str::uuid();
        DB::table('election_boards')->insert([
            'id'              => $boardId,
            'jurisdiction_id' => $jid,
            'is_bootstrap'    => true,
            'status'          => 'active',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        DB::table('election_board_members')->insert([
            'id'                => (string) Str::uuid(),
            'election_board_id' => $boardId,
            'user_id'           => null,
            'status'            => 'seated',
            'term_starts_on'    => now()->toDateString(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $mapId = (string) Str::uuid();
        DB::table('legislature_district_maps')->insert([
            'id'            => $mapId,
            'legislature_id' => $legId,
            'name'          => 'Pin Map',
            'status'        => 'active',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        DB::table('legislature_districts')->insert([
            'id'                => (string) Str::uuid(),
            'legislature_id'    => $legId,
            'map_id'            => $mapId,
            'jurisdiction_id'   => $jid,
            'district_number'   => 1,
            'seats'             => 9,
            'target_population' => 900_000,
            'actual_population' => 900_000,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        CohortStage::run($jid, null, 1, 62);
        IdentityStage::run($jid, null, 1);
        $election = ElectionStage::run($jid, null, 1);
        $electionId = (string) $election['election_id'];

        CountingStage::run($electionId, null, 1);
        $seated = SeatingStage::run($electionId, null, 1);

        if (empty($seated['certified'])) {
            $this->fail('fixture failed to seat a chamber: '.($seated['skipped'] ?? 'unknown'));
        }

        return [$jid, $legId];
    }

    private function aSeatedMember(string $legislatureId): User
    {
        $member = LegislatureMember::query()
            ->where('legislature_id', $legislatureId)
            ->whereIn('status', ['elected', 'seated'])
            ->firstOrFail();

        return User::query()->findOrFail($member->user_id);
    }

    /** Cast F-LEG-004 'yes' for each seated member until the vote resolves. */
    private function castAllYes(ConstitutionalEngine $engine, string $legislatureId, string $voteId): void
    {
        $members = LegislatureMember::query()
            ->where('legislature_id', $legislatureId)
            ->whereIn('status', ['elected', 'seated'])
            ->get();

        foreach ($members as $member) {
            if (ChamberVote::query()->whereKey($voteId)->value('status') !== ChamberVote::STATUS_OPEN) {
                break;
            }

            $engine->file('F-LEG-004', User::query()->findOrFail($member->user_id), [
                'vote_id' => $voteId,
                'value'   => 'yes',
            ]);
        }
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
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
