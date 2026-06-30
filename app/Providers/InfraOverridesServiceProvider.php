<?php

namespace App\Providers;

use App\Services\Operator\OperatorSettingsService;
use Illuminate\Support\ServiceProvider;

/**
 * Operator Operations console (Phase 2) — overlay the operator's instant-tier overrides
 * onto config() at boot, so every existing config('cga.…') read transparently picks them
 * up. This is what makes an in-console edit apply on the next request with NO container
 * restart and NO change to any federation read site. The overlay is defensive (it
 * survives a pre-migration / DB-less boot), so adding it can never break startup.
 */
class InfraOverridesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        app(OperatorSettingsService::class)->overlay();
    }
}
