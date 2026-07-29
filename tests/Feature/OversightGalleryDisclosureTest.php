<?php

namespace Tests\Feature;

use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\RemovalProceeding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * SETTLED-LAW PIN (operator ruling B / §10 A1, W4 ⑨): a GOVERNMENT oversight
 * console is public by default INCLUDING in-progress proceedings and named
 * members; organizations decide their OWN visibility (separate controllers).
 *
 * The boundary-only PublicProceedingsGuestTest proves a guest is not
 * auth-bounced, but it seeds no data — so a regression that re-added a viewer
 * filter to proceedingRows() / memberNames would pass it unnoticed. This closes
 * that gap: a logged-OUT visitor must actually SEE an in-progress removal
 * proceeding and the named members it concerns.
 *
 * Live-pg + rolled-back tx (the default test connection is sqlite:memory with no
 * schema — see LivePgConnection). If an edit re-gates the government gallery,
 * THIS goes red.
 */
class OversightGalleryDisclosureTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_oversight_gallery';

    public function test_a_guest_sees_in_progress_oversight_and_named_members(): void
    {
        $this->onLivePg(function () {
            $jurisdictionId = DB::table('jurisdictions')->whereNull('deleted_at')->value('id');
            if ($jurisdictionId === null) {
                $this->markTestSkipped('Live DB has no jurisdiction.');
            }

            $legislature = Legislature::create([
                'id'              => (string) Str::uuid(),
                'jurisdiction_id' => (string) $jurisdictionId,
                'term_number'     => 1,
                'status'          => Legislature::STATUS_ACTIVE,
                'total_seats'     => 5,
                'type_a_seats'    => 5,
                'type_b_seats'    => 0,
                'quorum_required' => 3,
            ]);

            $name = 'Rep. Gallery Witness '.Str::random(6);

            $user = User::create([
                'name'              => $name,
                'display_name'      => $name,
                'email'             => 'oversight-'.Str::uuid().'@test.invalid',
                'password'          => Str::random(32),
                'terms_accepted_at' => now(),
            ]);

            $member = LegislatureMember::create([
                'id'             => (string) Str::uuid(),
                'legislature_id' => (string) $legislature->id,
                'user_id'        => (string) $user->id,
                'seat_type'      => 'a',
                'seat_no'        => 1,
                'status'         => LegislatureMember::STATUS_SEATED,
            ]);

            RemovalProceeding::create([
                'id'             => (string) Str::uuid(),
                'legislature_id' => (string) $legislature->id,
                'kind'           => RemovalProceeding::KIND_IMPEACHMENT,
                'subject_type'   => 'legislature_members',
                'subject_id'     => (string) $member->id,
                'opened_via'     => 'F-SPK-007',
                'status'         => RemovalProceeding::STATUS_OPENED, // in progress
            ]);

            // A logged-OUT visitor (no actingAs) reaches the government gallery
            // and sees the live proceeding + the named member it concerns.
            $this->get("/legislatures/{$legislature->id}/oversight")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Legislature/Oversight')
                    ->where('can.isGallery', true)
                    ->has('members', 1)
                    ->where('members.0.name', $name)
                    ->has('proceedings', 1)
                    ->where('proceedings.0.status', RemovalProceeding::STATUS_OPENED)
                    ->where('proceedings.0.subject', $name));
        });
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
