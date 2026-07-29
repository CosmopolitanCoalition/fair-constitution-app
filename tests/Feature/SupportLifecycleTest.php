<?php

namespace Tests\Feature;

use App\Models\SupportReport;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * /support lifecycle — the read/triage door (ruling §10 item 7).
 *
 * A reporter sees only their own reports; an operator sees all and can triage
 * (status/severity). Detail is gated to owner-or-operator (a stranger 404s).
 * Runs on the guarded live-pg connection (the SupportReportTest posture) —
 * SKIPS when pg is unreachable.
 */
class SupportLifecycleTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_support_lifecycle';

    public function test_a_reporter_sees_only_their_own_reports(): void
    {
        $this->onLivePg(function () {
            $a = $this->aUser('Reporter A');
            $b = $this->aUser('Reporter B');
            $mine = $this->aReport($a, 'My bug');
            $this->aReport($b, 'Their bug');

            $this->actingAs($a)
                ->get('/support/tickets')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Support/Tickets')
                    ->where('isOperator', false)
                    ->where('totalCount', 1)
                    ->has('reports', 1)
                    ->where('reports.0.public_id', $mine->public_id));
        });
    }

    /**
     * OWN-THE-WORLD: this used to assert totalCount === 2 after creating two
     * reports, which only holds on an empty database. An operator sees EVERY
     * report by definition, so on any seeded box the assertion counts the world's
     * reports too and fails (it saw 3 against a box carrying one pre-existing
     * report). The fixture never owned the world; the test only assumed it did.
     *
     * Now it measures the CLAIM instead of a coincidence: the operator's total
     * rises by exactly the two reports created, both of them appear in the list,
     * and — the part that actually distinguishes an operator — the operator sees
     * a report filed by someone else, which that someone else's own view of the
     * same page does not show them.
     */
    public function test_an_operator_sees_every_report(): void
    {
        $this->onLivePg(function () {
            $op = $this->aUser('Operator', operator: true);
            $a = $this->aUser('Reporter A');
            $b = $this->aUser('Reporter B');

            $before = $this->actingAs($op)->get('/support/tickets')
                ->assertOk()->viewData('page')['props']['totalCount'];

            $one = $this->aReport($a, 'One');
            $two = $this->aReport($b, 'Two');

            $props = $this->actingAs($op)->get('/support/tickets')
                ->assertOk()->viewData('page')['props'];

            $this->assertTrue($props['isOperator'], 'the operator flag must be set for an operator');
            $this->assertSame($before + 2, $props['totalCount'],
                'an operator total must rise by exactly the reports filed');

            // The list is ordered latest-first, so both new rows are on page one.
            $visible = array_column($props['reports'], 'public_id');
            $this->assertContains($one->public_id, $visible);
            $this->assertContains($two->public_id, $visible);

            // The operator sees B's report; B's own view of the same page does not
            // show them A's. That contrast is what "sees every report" means.
            $bProps = $this->actingAs($b)->get('/support/tickets')
                ->assertOk()->viewData('page')['props'];

            $this->assertFalse($bProps['isOperator']);
            $this->assertSame([$two->public_id], array_column($bProps['reports'], 'public_id'),
                'a reporter sees only their own report, whatever else exists');
            $this->assertSame(1, $bProps['totalCount']);
        });
    }

    public function test_a_stranger_cannot_open_someone_elses_ticket(): void
    {
        $this->onLivePg(function () {
            $a = $this->aUser('Reporter A');
            $b = $this->aUser('Reporter B');
            $report = $this->aReport($a, 'Private detail');

            $this->actingAs($a)->get("/support/ticket/{$report->public_id}")->assertOk();
            $this->actingAs($b)->get("/support/ticket/{$report->public_id}")->assertNotFound();
        });
    }

    public function test_an_operator_triages_but_a_reporter_cannot(): void
    {
        $this->onLivePg(function () {
            $op = $this->aUser('Operator', operator: true);
            $reporter = $this->aUser('Reporter');
            $report = $this->aReport($reporter, 'Needs triage');

            $token = 'pin-csrf-token';

            // A reporter cannot triage — operator-only.
            $this->actingAs($reporter)
                ->withSession(['_token' => $token])
                ->post("/support/ticket/{$report->public_id}", ['_token' => $token, 'status' => SupportReport::STATUS_CLOSED])
                ->assertForbidden();

            // The operator can.
            $this->actingAs($op)
                ->withSession(['_token' => $token])
                ->post("/support/ticket/{$report->public_id}", [
                    '_token' => $token,
                    'status' => SupportReport::STATUS_IN_PROGRESS,
                    'severity' => 'high',
                ])
                ->assertRedirect("/support/ticket/{$report->public_id}");

            $report->refresh();
            $this->assertSame(SupportReport::STATUS_IN_PROGRESS, $report->status);
            $this->assertSame('high', $report->severity);
        });
    }

    // ── helpers (the SupportReportTest live-pg posture) ──────────────────────

    private function aUser(string $name, bool $operator = false): User
    {
        return User::create([
            'name' => $name,
            'email' => 'support-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
            'is_operator' => $operator,
        ]);
    }

    private function aReport(User $user, string $subject): SupportReport
    {
        return SupportReport::create([
            'category' => SupportReport::CATEGORY_BUG,
            'subject' => $subject,
            'body' => 'Details for '.$subject,
            'reporter_id' => $user->id,
            'status' => SupportReport::STATUS_OPEN,
            'route_target' => SupportReport::routeFor(SupportReport::CATEGORY_BUG),
        ]);
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
