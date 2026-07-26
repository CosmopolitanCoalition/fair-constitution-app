<?php

namespace Tests\Constitutional;

use Tests\TestCase;

/**
 * PLANNED SEATS IS AN INTERNAL CHANNEL (2026-07-26).
 *
 * F-ELB-008 accepts `planned_seats`, and the handler will adopt it over its
 * own measurement when the two differ by one seat at the rounding edge. That
 * is safe for exactly ONE reason: the key is reachable only from the autoseed
 * path, where the plan is computed server-side in the same process from the
 * same raster. There is no client to distrust.
 *
 * Today that safety is a PROPERTY OF THE CONTROLLER — its payloads are built
 * key-by-key from a fixed literal set, so a browser cannot smuggle the key in.
 * A single future edit that splats `$request->validated()` or `->all()` into
 * that payload would silently hand a constitutional handler a client-supplied
 * seat count. This project has already found that exact shape three times
 * (the rosterSize duplicate, the counting core's blind band, the forgeable
 * install path), so the convention is pinned here as a rail:
 *
 *   the WEB paths must never carry planned_seats, and must never splat
 *   request input into an F-ELB-008 payload.
 *
 * Source-scanned rather than exercised: the point is to fail the moment
 * someone writes the dangerous line, not merely when a request reaches it.
 */
class PlannedSeatsIsInternalOnlyTest extends TestCase
{
    private const WEB_CALLERS = [
        'app/Http/Controllers/Legislature/SubdivisionDrawController.php',
        'app/Http/Controllers/LegislatureController.php',
    ];

    public function test_web_controllers_never_pass_planned_seats_to_the_handler(): void
    {
        foreach (self::WEB_CALLERS as $rel) {
            $path = base_path($rel);
            if (! is_file($path)) {
                continue;
            }
            $src = file_get_contents($path);

            $this->assertStringNotContainsString(
                'planned_seats',
                $src,
                "{$rel} must never carry planned_seats — F-ELB-008 adopts it over its own "
                .'measurement, so a client-reachable path would let a caller choose its own seat count.'
            );
        }
    }

    public function test_web_controllers_never_splat_request_input_into_an_F_ELB_008_payload(): void
    {
        foreach (self::WEB_CALLERS as $rel) {
            $path = base_path($rel);
            if (! is_file($path)) {
                continue;
            }
            $src = file_get_contents($path);

            // Find each F-ELB-008 filing and read the payload literal that
            // follows it; a splat there would admit every request key at once.
            $offset = 0;
            while (($pos = strpos($src, "'F-ELB-008'", $offset)) !== false) {
                $window = substr($src, $pos, 2000);
                foreach (['...$validated', '...$request', '...$payload', '$request->all()', '$request->validated()'] as $splat) {
                    $this->assertStringNotContainsString(
                        $splat,
                        $window,
                        "{$rel} splats request input into an F-ELB-008 payload ({$splat}) — build the "
                        .'payload key-by-key so a client can never reach planned_seats or any future '
                        .'internal-only key.'
                    );
                }
                $offset = $pos + 1;
            }
        }
    }

    public function test_the_internal_autoseed_path_is_the_one_that_carries_it(): void
    {
        // The positive half: the rail is only meaningful while the internal
        // caller actually uses the channel.
        $resolver = file_get_contents(base_path('app/Services/Districting/LeafGiantResolver.php'));
        $this->assertStringContainsString('planned_seats', $resolver,
            'the autoseed filer is the ONLY intended source of planned_seats');

        $handler = file_get_contents(base_path('app/Domain/Forms/Handlers/ManualDistrictDraw.php'));
        $this->assertStringContainsString("payload['planned_seats']", $handler);
        // …and the handler must keep its own guard rather than trusting it blindly.
        $this->assertStringContainsString('abs($plannedSeats - $seats) <= 1', $handler,
            'the handler must still refuse a disagreement larger than one seat');
    }
}
