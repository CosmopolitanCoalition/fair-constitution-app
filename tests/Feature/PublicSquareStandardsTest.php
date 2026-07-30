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
 * Wave 4 §③ (lane 15 educational slice) — the public square's COMMUNITY
 * STANDARDS card. The load-bearing pin: the card teaches that there is NO
 * viewpoint-removal code path, and enumerates exactly the four narrow,
 * logged carve-outs — grounded in the real F-SOC-003 / M-4 / M-5 mechanisms.
 * Lane 6 owns the post rows / presence / a11y; this pins only the standards
 * prop, which lives under its own key so the two slices never collide.
 */
class PublicSquareStandardsTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_square_standards';

    public function test_the_square_ships_the_community_standards_card(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser();

            $this->actingAs($user)->get('/civic/square')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Civic/PublicSquare')
                    ->where('standards.headline', 'This square cannot be censored for viewpoint.')
                    // Exactly the four content-neutral carve-outs.
                    ->has('standards.carve_outs', 4)
                    ->where('standards.carve_outs.0.key', 'imminent_harm')
                    ->where('standards.carve_outs.1.key', 'private_data')
                    ->where('standards.carve_outs.2.key', 'off_topic_flooding')
                    ->where('standards.carve_outs.3.key', 'legal_floor')
                    // The constitutionally load-bearing fact: no viewpoint path.
                    ->has('standards.no_viewpoint_path')
                    ->has('standards.logged'));
        });
    }

    private function aUser(): User
    {
        return User::create([
            'name' => 'Square Standards Reader',
            'email' => 'square-standards-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    private function onLivePg(callable $body): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

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
