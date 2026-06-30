<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Public proceedings are public record (Art. II §2): a logged-OUT visitor may VIEW them, while every
 * action stays auth+role-gated. These pins assert the ROUTE BOUNDARY only (no DB seeding needed):
 *   - a guest reading a proceeding is NOT bounced to /login (the request reaches the controller —
 *     a 404/500 from missing data is fine; what matters is the absence of an auth redirect);
 *   - a guest hitting a write surface IS bounced to /login (the auth middleware fires first).
 */
class PublicProceedingsGuestTest extends TestCase
{
    public function test_a_guest_reaches_public_proceeding_reads_without_an_auth_bounce(): void
    {
        foreach ([
            '/bills/'.Str::uuid(),
            '/cases/'.Str::uuid(),
            '/judiciaries/'.Str::uuid(),
            '/executives/'.Str::uuid(),
            '/legislatures/'.Str::uuid().'/chamber',
            '/system/public-records',
        ] as $url) {
            $location = (string) $this->get($url)->headers->get('Location', '');
            $this->assertStringNotContainsString('/login', $location, "{$url} must not auth-bounce a guest");
        }
    }

    public function test_a_guest_is_bounced_from_proceeding_actions(): void
    {
        // Write surfaces stay gated — the auth middleware redirects a guest to /login before any handler.
        // Pass a matching session+request CSRF token so the POST clears CSRF and actually reaches auth
        // (a tokenless POST would 419 at CSRF, which wouldn't prove the AUTH gate).
        $token = 'pin-csrf-token';
        foreach ([
            '/bills/'.Str::uuid().'/refer',
            '/judiciaries/'.Str::uuid().'/cases',
            '/executives/'.Str::uuid().'/orders',
        ] as $url) {
            $this->withSession(['_token' => $token])
                ->post($url, ['_token' => $token])
                ->assertRedirect('/login');
        }
    }
}
