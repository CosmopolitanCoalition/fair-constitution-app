<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * /system/clocks + /system/amendments read-only pages (mockups-v3-wiring
 * Phase 2).
 *
 * Route boundary (DB-free, the SupportReportTest posture): the whole
 * /system group is auth — guests are bounced to /login before any DB is
 * touched. Authenticated reads run on the guarded live-pg connection (the
 * InviteFlowTest posture) — the phpunit sqlite :memory: connection has no
 * schema and RefreshDatabase is forbidden on the live dev DB; the clocks
 * registry (21 canonical rows, ClockRegistrySeeder) is seeded on this box.
 * SKIPS when pg is unreachable — run inside the app container.
 */
class SystemClocksAmendmentsTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_system_pages';

    public function test_a_guest_reads_the_clocks_publicly(): void
    {
        // §10-8 (RULED 2026-07-28): the clock is a public record — a guest reads
        // it read-only, no /login bounce. It renders on the live-pg connection
        // like the authenticated read (the controller does SELECTs only and
        // never touches auth()->user()); the mutating dev clock panel stays
        // gated in its own local-only /dev group.
        $this->onLivePg(function () {
            $this->get('/system/clocks')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('System/Clocks')
                    ->has('clocks', 21)
                    ->where('surface.id', 'system/clocks'));
        });
    }

    public function test_a_guest_is_bounced_from_amendments(): void
    {
        // Amendments STAYS auth-gated — only /clocks was ruled public (§10-8).
        $this->get('/system/amendments')->assertRedirect('/login');
    }

    public function test_an_authenticated_user_reads_the_clock_registry(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Clock reader');

            $this->actingAs($user)
                ->get('/system/clocks')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('System/Clocks')
                    ->has('clocks', 21) // the full canonical registry
                    ->has('armed')
                    ->where('stats.total', 21)
                    ->where('surface.id', 'system/clocks'));
        });
    }

    public function test_an_authenticated_user_reads_the_amendment_ledger_and_current_values(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Ledger reader');

            // The ledger may legitimately be empty — the contract is that the
            // page renders and the prop key exists (append-only feed). Door one
            // ALSO ships the current amendable values with their hardened range
            // (Wave 4 ①): the register keys in order, each with value + bounds.
            $this->actingAs($user)
                ->get('/system/amendments')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('System/Amendments')
                    ->has('changes')
                    ->has('settings')
                    // The first register key with its hardened range — proves the
                    // bounds the client-side checker reads are actually shipped.
                    ->where('settings.0.key', 'election_interval_months')
                    ->where('settings.0.bounds.min', 1)
                    ->where('settings.0.bounds.max', 60)
                    ->has('settings.0.value')
                    ->has('settings.0.basis')
                    ->where('surface.id', 'system/amendments'));
        });
    }

    // ── helpers (the SupportReportTest / InviteFlowTest live-pg posture) ─────

    private function aUser(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => 'system-pages-'.Str::uuid().'@test.invalid',
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
