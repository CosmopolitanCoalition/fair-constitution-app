<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

/**
 * Setup-loop audit (2026-08-23, ahead of the first public/cloud box) — the
 * setup window is a PUBLIC surface: on a cloud node anyone who finds the
 * hostname can reach /setup while the founder walks it. Every MUTATING
 * post-founder wizard endpoint therefore belongs to the operator alone:
 *
 *   · a GUEST is refused at the door (auth middleware → 401 on JSON);
 *   · a signed-in CITIZEN who is not the operator is refused by the handler
 *     (is_operator → 403) — the posture saveConstants / saveGameMode /
 *     pull-start already carried, extended to the whole wizard spine.
 *
 * The pins run with NO database and NO world: refusal must land before any
 * read, or the gate is not a gate. Read endpoints (state, progress, summary,
 * review drills) deliberately stay public and are not pinned here.
 */
class SetupWindowOperatorGateTest extends TestCase
{
    /** @return list<string> */
    private static function gatedEndpoints(): array
    {
        return [
            '/api/setup/cosmic-address',
            '/api/setup/wizard/step1/activate',
            '/api/setup/wizard/step2/start',
            '/api/setup/wizard/step2/control',
            '/api/setup/wizard/step3/complete',
            '/api/setup/wizard/step4/complete',
            '/api/setup/wizard/step2/review/orphans/00000000-0000-0000-0000-000000000000/decision',
        ];
    }

    public function test_a_guest_is_refused_at_every_mutating_wizard_endpoint(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        foreach (self::gatedEndpoints() as $path) {
            $this->postJson($path, [])
                ->assertStatus(401);
        }
    }

    public function test_a_citizen_who_is_not_the_operator_is_refused_by_the_handler(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        // In-memory user (never persisted): the handler reads is_operator off
        // the authenticated model and must refuse BEFORE touching the world.
        $citizen = new User(['name' => 'Resident', 'email' => 'resident@example.test']);
        $citizen->is_operator = false;
        $this->actingAs($citizen);

        foreach (self::gatedEndpoints() as $path) {
            $this->postJson($path, [])
                ->assertStatus(403);
        }
    }
}
