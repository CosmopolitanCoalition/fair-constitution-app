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
 * /support/report intake (mockups-v3-wiring Phase 1).
 *
 * Route boundary (DB-free, the PublicProceedingsGuestTest posture): anyone
 * may SEE the form; FILING is auth-gated. DB-backed paths (file → row +
 * public_id, invalid category → 422) run on the guarded live-pg connection
 * (the InviteFlowTest posture) — the phpunit sqlite :memory: connection has
 * no schema and RefreshDatabase is forbidden on the live dev DB. SKIPS when
 * pg is unreachable — run inside the app container.
 */
class SupportReportTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_support_report';

    public function test_a_guest_sees_the_report_form(): void
    {
        $this->get('/support/report?ref=/civic/square')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Support/Report')
                ->where('ref', '/civic/square')
                ->where('submitted', false)
                ->has('categories', count(SupportReport::CATEGORIES)));
    }

    public function test_a_guest_is_bounced_from_filing(): void
    {
        // Session+request CSRF token so the POST clears CSRF and actually
        // reaches auth (a tokenless POST would 419 before proving the gate).
        $token = 'pin-csrf-token';
        $this->withSession(['_token' => $token])
            ->post('/support/report', ['_token' => $token, 'category' => 'bug', 'body' => 'x'])
            ->assertRedirect('/login');
    }

    public function test_an_authenticated_user_files_a_report(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Reporter');

            $token = 'pin-csrf-token';
            $this->actingAs($user)
                ->withSession(['_token' => $token])
                ->post('/support/report', [
                    '_token' => $token,
                    'category' => SupportReport::CATEGORY_BUG,
                    'body' => 'The chamber page shows a blank vote tally.',
                    'ref' => '/legislatures/x/chamber',
                ])
                ->assertRedirect('/support/report');

            $report = SupportReport::query()->where('reporter_id', $user->id)->first();

            $this->assertNotNull($report, 'the filing created a support_reports row');
            $this->assertSame(10, strlen($report->public_id), 'public_id is a 10-char base62 id');
            $this->assertSame(SupportReport::STATUS_OPEN, $report->status);
            $this->assertSame(SupportReport::CATEGORY_BUG, $report->category);
            $this->assertSame('/legislatures/x/chamber', $report->ref);
            $this->assertSame(session('support_report_public_id'), $report->public_id);
        });
    }

    public function test_an_invalid_category_is_rejected_422(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Reporter');

            $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
                ->actingAs($user)
                ->postJson('/support/report', ['category' => 'nonsense', 'body' => 'x'])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['category']);

            $this->assertSame(
                0,
                SupportReport::query()->where('reporter_id', $user->id)->count(),
                'no row on a rejected filing'
            );
        });
    }

    // ── helpers (the InviteFlowTest live-pg posture) ─────────────────────────

    private function aUser(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => 'support-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
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
