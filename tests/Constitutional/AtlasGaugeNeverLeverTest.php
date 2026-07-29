<?php

namespace Tests\Constitutional;

use PHPUnit\Framework\TestCase;

/**
 * CONSTITUTIONAL PIN — the Atlas is A GAUGE, NEVER A LEVER (CI-1).
 *
 * Authorized by ATLAS_DESIGN.md §2 and §9 (design approved by the operator
 * 2026-07-29, all §8 calls option (a)); the Atlas BUILD is lane 4's Wave 4
 * order ①. These are SOURCE pins, deliberately: the behavioural equivalent of
 * "this page never counts the world" would have to run the planet-scale
 * aggregate it forbids (~75 s per suite pass — the same reason
 * SimControlParityTest pins its control marker at the source). The regressions
 * guarded here are precise and textual.
 *
 * THE INVARIANTS:
 *
 *  1. THE ATLAS NEVER COUNTS THE WORLD. Every aggregate comes from the nightly
 *     `world_stats` rollup. A live COUNT would be impossible per request (the
 *     ~75 s SimConsoleController::world() aggregate) AND would break the
 *     k-anonymity the nightly snapshot exists to protect, by handing an
 *     observer sub-minute resolution on a number published once a day.
 *  2. THE PAGE CARRIES NO GOVERNANCE ACTION. Exactly one mutating call exists
 *     in the whole surface, and it is the personal map opt-in — a privacy
 *     preference on the viewer's own record, which confers no vote, no seat and
 *     no advantage. No other write verb may appear.
 *  3. A WITHHELD FIGURE IS A GAP, NEVER A ZERO. The page's formatter renders
 *     null as an em-dash. Coercing a suppressed null to 0 would publish the
 *     very count the snapshot withheld, and would let an observer defeat
 *     k-anonymity by differencing two nights.
 *  4. THE SURFACE IS NEVER OPERATOR-WALLED. Watching the world is a citizen's
 *     business; the route is a public read.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class AtlasGaugeNeverLeverTest extends TestCase
{
    private function appFile(string $relative): string
    {
        $root = str_replace('\\', '/', \dirname(__DIR__, 2));

        return file_get_contents($root.'/'.$relative);
    }

    /** Strip comments: prose may NAME a forbidden shape; code may not use it. */
    private function code(string $src): string
    {
        $src = preg_replace('!/\*.*?\*/!s', '', $src);

        return preg_replace('!//.*$!m', '', $src);
    }

    public function test_the_atlas_controller_reads_the_rollup_and_never_counts_the_world(): void
    {
        $code = $this->code($this->appFile('app/Http/Controllers/System/AtlasController.php'));

        // The REQUIRED shape: the vital signs come from the nightly rollup.
        $this->assertStringContainsString(
            "DB::table('world_stats')",
            $code,
            'the Atlas must read the nightly world_stats rollup'
        );

        // The FORBIDDEN shapes: any aggregate over the world on a request path.
        foreach (['->count()', 'count(*)', '->sum(', '->avg('] as $aggregate) {
            $this->assertStringNotContainsString(
                $aggregate,
                $code,
                "AtlasController must not compute {$aggregate} live — every aggregate belongs to the "
                .'nightly rollup. A live count is both impossible at planet scale and a k-anonymity leak.'
            );
        }

        // The anti-pattern this whole design exists to avoid must never be wired
        // to a public page.
        $this->assertStringNotContainsString(
            'SimConsoleController',
            $code,
            'the ~75s world() aggregate is a console diagnostic and must never sit behind a public page'
        );
    }

    public function test_the_atlas_page_carries_exactly_one_mutating_call_and_it_is_the_optin(): void
    {
        $src = $this->appFile('resources/js/Pages/System/Atlas.vue');

        // No other write verb, under any spelling.
        foreach (['router.put(', 'router.patch(', 'router.delete(', 'useForm('] as $verb) {
            $this->assertStringNotContainsString(
                $verb,
                $src,
                "the Atlas is a mirror, not a control panel — {$verb} has no place on it"
            );
        }

        // Exactly one POST, and it is the opt-in.
        $this->assertSame(
            1,
            substr_count($src, 'router.post('),
            'the Atlas may carry exactly ONE mutating call — the personal map opt-in, and nothing else'
        );
        $this->assertStringContainsString(
            'props.optIn.url',
            $src,
            "the page's single POST must target the map opt-in"
        );
    }

    public function test_a_withheld_figure_renders_as_a_gap_never_a_zero(): void
    {
        $src = $this->appFile('resources/js/Pages/System/Atlas.vue');

        // The formatter that IS the rail.
        $this->assertStringContainsString(
            "return n == null ? '—' : Number(n).toLocaleString();",
            $src,
            'dash() is the suppression rail: a null figure renders as an em-dash, never as 0'
        );

        // Zero-coercion of a possibly-null figure is the exact regression.
        foreach (['?? 0', '|| 0'] as $coercion) {
            $this->assertStringNotContainsString(
                "dash(n {$coercion}",
                $src,
                'a suppressed null must never be coerced to zero on its way to the page'
            );
        }
    }

    public function test_the_atlas_route_is_a_public_read_and_never_operator_walled(): void
    {
        $routes = $this->code($this->appFile('routes/web.php'));

        $this->assertStringContainsString(
            "Route::get('/atlas', [\\App\\Http\\Controllers\\System\\AtlasController::class, 'index'])->name('atlas.index');",
            $routes,
            'the Atlas is a single public GET — watching the world is a citizen right'
        );

        // No write route may ever be added for this surface.
        foreach (["Route::post('/atlas", "Route::put('/atlas", "Route::delete('/atlas"] as $write) {
            $this->assertStringNotContainsString(
                $write,
                $routes,
                'the Atlas surface offers no action; a write route would turn a measurement into a power'
            );
        }
    }
}
