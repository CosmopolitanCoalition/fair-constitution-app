<?php

namespace App\Providers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Support\InstanceClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * CI-2 boot assertion — a scale_demo instance is REFUSED SERVICE while
 * federation is enabled.
 *
 * Constitutional basis (Preamble): a demo has received no consent, so it is an
 * illustration and never a government. An illustration that federates would
 * inject unconsented records into instances that DO bear consent, under Full
 * Faith & Credit — the contamination vector the whole two-instance doctrine
 * exists to close. See docs/plans/simworld/SIM_SCALING_PLAN.md §1 and §11.
 *
 * WHY HTTP-ONLY: the assertion refuses to *serve*, never to boot. Console stays
 * usable on purpose — an operator who lands in this state must be able to run
 * `php artisan federation:init --disable` (or fix instance_class) to get out of
 * it. A bootstrap assertion that also bricks artisan is a trap, not a rail.
 *
 * The check is defensive in every other respect: a missing table or column, or
 * a DB that is down mid-migration, is NOT a violation — InstanceClass already
 * fails closed to `production`, and a production instance is unaffected by this
 * rail entirely.
 */
class InstanceClassProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        if (! InstanceClass::isScaleDemo()) {
            return;
        }

        if (! $this->federationEnabled()) {
            return;
        }

        throw new ConstitutionalViolation(
            'This instance is classed scale_demo but federation is ENABLED. A demo has '
            .'received no consent, so it may not federate (CI-2) — it would inject '
            .'unconsented records into consent-bearing instances under Full Faith & Credit. '
            .'Refusing to serve. Fix from the console: `php artisan federation:init --disable`, '
            .'or correct instance_settings.instance_class.',
            'Preamble; Art. V §1 (Full Faith & Credit)'
        );
    }

    /**
     * Read federation_enabled defensively. Anything unreadable resolves to
     * false: the violation requires POSITIVE evidence that federation is on,
     * so a degraded read can never manufacture an outage.
     */
    private function federationEnabled(): bool
    {
        try {
            if (! Schema::hasTable('instance_settings')
                || ! Schema::hasColumn('instance_settings', 'federation_enabled')) {
                return false;
            }

            return (bool) DB::table('instance_settings')
                ->whereNull('deleted_at')
                ->orderBy('created_at')
                ->value('federation_enabled');
        } catch (\Throwable) {
            return false;
        }
    }
}
