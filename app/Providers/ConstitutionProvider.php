<?php

namespace App\Providers;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Domain\Forms\Contracts\ResidencyHandlerDelegate;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ConstitutionalValidator;
use App\Services\ResidencyService;
use App\Services\RoleService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the constitutional engine (WI-2 skeleton; WI-5 real bindings).
 *
 * WI-5 replaced the Phase A stubs without touching the engine:
 *  - ResolvesRoles → RoleService              (was StubRoleResolver)
 *  - ResidencyHandlerDelegate → ResidencyService (was NoopResidencyDelegate)
 *
 * RoleService is a singleton so the derivation is request-cached (one
 * fact-query pass per user per request); ResidencyService::* writers
 * flush it when the facts change.
 */
class ConstitutionProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditService::class);
        $this->app->singleton(ConstitutionalValidator::class);
        $this->app->singleton(ConstitutionalEngine::class);

        $this->app->singleton(RoleService::class);
        $this->app->bind(ResolvesRoles::class, fn ($app) => $app->make(RoleService::class));

        $this->app->singleton(ResidencyService::class);
        $this->app->bind(ResidencyHandlerDelegate::class, fn ($app) => $app->make(ResidencyService::class));
    }

    public function boot(): void
    {
        // Derived-role gates for later phases ('role:R-xx' middleware uses
        // the same vocabulary — see App\Http\Middleware\EnsureRole).
        Gate::define('associated', fn (User $user) => in_array('R-03', $this->app->make(ResolvesRoles::class)->rolesFor($user), true));
        Gate::define('voter', fn (User $user) => in_array('R-04', $this->app->make(ResolvesRoles::class)->rolesFor($user), true));
    }
}
